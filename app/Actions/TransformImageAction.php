<?php

declare(strict_types=1);

namespace App\Actions;

use AceOfAces\LaravelImageTransformUrl\Actions\TransformImageAction as BaseTransformImageAction;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageResult;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageSource;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\Exceptions\EncoderException;
use Intervention\Image\Exceptions\NotSupportedException;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thin override of the vendor transform action.
 *
 * The app uses the Imagick driver (config/image.php), which decodes animated
 * WebP that GD cannot — fixing the reported 500. Safety nets on top:
 *
 *  1. Frame guard: transforming a long animation coalesces every frame at full
 *     canvas — GBs of RAM and tens of seconds (an uncatchable OOM / timeout).
 *     Animations with more than max_animated_frames are redirected to the
 *     original. Gated on the DETECTED mime (not the caller-controlled name); the
 *     frame count is an Imagick header ping cached by disk+path+mtime. A corrupt
 *     source counts as over-cap (and the sentinel is cached) so it flows into the
 *     same rate-limited redirect instead of being re-downloaded every request.
 *  2. Failure fallback: a still-undecodable source (or encoder error) redirects
 *     to the original rather than returning a 500. Only decode/encode faults are
 *     swallowed — genuine faults (S3, config, type errors) still surface as 5xx.
 *
 * It also serves cached transforms with disk-native reads so an S3 cache disk
 * works (the vendor reads the cache via the local File:: facade, which always
 * misses on an S3 key). The source is resolved/validated BEFORE the cache read
 * so a deleted source 404s instead of serving a stale transform, and so the
 * cache key is normalised (fixing the default/no-prefix route). Both redirects
 * are rate-limited and carry the configured cache headers so the CDN absorbs
 * repeats. Bound over the vendor FQCN in AppServiceProvider.
 */
class TransformImageAction extends BaseTransformImageAction
{
    public function handle(?string $ip, ?string $pathPrefix, string $options, ?string $path = null): ImageResult
    {
        $source = null;

        try {
            // Resolve + validate the source FIRST. This 404s a deleted/disallowed
            // source (instead of serving a stale cache entry) and normalises
            // $pathPrefix/$path by reference — so the cache key below matches the
            // vendor's writer, including on the default (no-prefix) route.
            $source = $this->handlePath($pathPrefix, $path);

            if ($cached = $this->servedFromCache($pathPrefix, $path, $options)) {
                return $cached; // disk-native cache read — works for S3 (the vendor's File:: read does not)
            }

            $this->guardAnimatedFrames($ip, $source, (string) $path); // may throttle + redirect

            return parent::handle($ip, $pathPrefix, $options, $path);
        } catch (HttpResponseException $redirect) {
            throw $redirect; // our redirect — propagate as-is
        } catch (HttpExceptionInterface $e) {
            throw $e; // preserve HTTP control-flow (404 not-found, 429 rate-limit, etc.)
        } catch (DecoderException|EncoderException|NotSupportedException|\ImagickException $e) {
            // Decode/encode failure — serve the original instead of a 500. Intervention
            // wraps decode faults in DecoderException, but its Imagick encoders throw
            // ImagickException bare (e.g. CacheResourcesExhausted under a policy.xml /
            // memory limit), so catch that too. ImagickException only originates from
            // Imagick ops, so genuine faults (S3, config, TypeError) still surface as 5xx.
            report($e);
            $this->redirectToOriginal($source); // throws HttpResponseException
        }
    }

    /**
     * Redirect animations with more than the configured frame count straight to
     * the original, before Imagick decodes (and coalesces) them into GBs of RAM.
     * Gates on the source's DETECTED mime so a renamed animation can't slip past.
     * Runs only on a cache-miss; the frame count is cached by disk+path+mtime so
     * repeat requests skip the download. Disabled when max_animated_frames <= 0.
     */
    protected function guardAnimatedFrames(?string $ip, ImageSource $source, string $path): void
    {
        $max = (int) config('image-transform-url.max_animated_frames', 0);
        if ($max <= 0) {
            return; // guard disabled
        }

        if (! in_array($source->mime, ['image/webp', 'image/gif'], true)) {
            return; // not an animatable format
        }

        if ($this->animationFrameCount($source) <= $max) {
            return; // within the cap — let the vendor transform it
        }

        // The vendor rate limiter lives in parent::handle(), downstream of this
        // preflight, so throttle the redirect here to keep it from being spammed.
        if (
            config()->boolean('image-transform-url.rate_limit.enabled') &&
            ! in_array(App::environment(), config()->array('image-transform-url.rate_limit.disabled_for_environments'), true)
        ) {
            $this->rateLimit($ip, $path);
        }

        Log::warning('image-transform-url: animation exceeds frame guard, redirecting to original', [
            'mime' => $source->mime,
            'max' => $max,
            'path' => $source->path,
        ]);

        $this->redirectToOriginal($source); // throws HttpResponseException
    }

    /**
     * Real frame count via a header-only Imagick ping (no pixel decode), cached
     * by disk + path + last-modified so repeat requests skip the source download.
     * A corrupt/undecodable source is treated as over-cap (PHP_INT_MAX) and the
     * sentinel is cached, so it flows into the rate-limited redirect rather than
     * throwing here and re-downloading the full object on every request.
     */
    protected function animationFrameCount(ImageSource $source): int
    {
        $lastModified = $source->type === 'disk'
            ? (int) Storage::disk((string) $source->disk)->lastModified($source->path)
            : (int) File::lastModified($source->path);

        $key = 'image-transform-url:frames:'.($source->disk ?? 'local').':'.$source->path.':'.$lastModified;

        return (int) Cache::remember($key, config()->integer('image-transform-url.cache.lifetime'), function () use ($source) {
            try {
                $probe = new \Imagick;
                $probe->pingImageBlob($this->readSourceBytes($source)); // headers only
                $frames = max(1, $probe->getNumberImages());
                $probe->clear();

                return $frames;
            } catch (\ImagickException $e) {
                report($e);

                return PHP_INT_MAX; // undecodable -> over cap -> rate-limited redirect, and cached
            }
        });
    }

    /**
     * Serve a cached transform read with disk-native operations, so it works on
     * any cache disk — including S3, where the vendor's `File::exists()` gate
     * (local filesystem) always misses on the S3 key. Returns null on a miss,
     * which lets the guard run and the vendor transform + store as usual (the
     * vendor's `$disk->put()` write is already S3-compatible). The cheap local
     * cache-flag is checked first, so a miss costs no S3 round-trip.
     */
    protected function servedFromCache(?string $pathPrefix, ?string $path, string $rawOptions): ?ImageResult
    {
        if (! config()->boolean('image-transform-url.cache.enabled')) {
            return null;
        }

        $options = static::parseOptions($rawOptions);

        if (! Cache::has('image-transform-url:'.$this->getCachePath($pathPrefix, $path, $options))) {
            return null; // not cached, or the TTL flag has expired
        }

        $disk = Storage::disk(config()->string('image-transform-url.cache.disk'));
        $endPath = $this->getCacheEndPath($pathPrefix, $path, $options);

        if (! $disk->exists($endPath)) {
            return null; // flag set but object evicted — treat as a miss
        }

        $bytes = (string) $disk->get($endPath);

        return new ImageResult(
            content: $bytes,
            mimeType: (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream',
            cacheHit: true,
        );
    }

    /**
     * Store the transform: write the object, set the live flag, then run size
     * management ONLY on a local cache disk.
     *
     * The flag is written before the sweep so a slow/failing sweep can never
     * suppress it (that suppression was the production low-hit-rate /
     * CPU-scales-with-replicas failure). manageCacheSize() LISTs + HEADs the whole
     * cache dir on every write: cheap local stat()s, but O(N) billed round-trips
     * that peg CPU on an object store — so it is skipped for S3, which should be
     * bounded by a bucket lifecycle rule instead (see README).
     *
     * The check is "not s3" rather than "is local" so it fails safe: an unlisted
     * driver gets the slow-but-bounded sweep instead of unbounded growth with no
     * lifecycle rule to fall back on.
     */
    protected function storeCachedImage(?string $pathPrefix, ?string $path, array $options, EncodedImageInterface $encoded): void
    {
        $diskName = config()->string('image-transform-url.cache.disk');

        Storage::disk($diskName)->put($this->getCacheEndPath($pathPrefix, $path, $options), $encoded->toString());

        Cache::put(
            key: 'image-transform-url:'.$this->getCachePath($pathPrefix, $path, $options),
            value: true,
            ttl: config()->integer('image-transform-url.cache.lifetime'),
        );

        if (config("filesystems.disks.{$diskName}.driver") !== 's3') {
            $this->manageCacheSize();
        }
    }

    /**
     * Read the raw source bytes for a disk- or local-based source.
     */
    protected function readSourceBytes(ImageSource $source): string
    {
        return $source->type === 'disk'
            ? (string) Storage::disk((string) $source->disk)->get($source->path)
            : (string) File::get($source->path);
    }

    /**
     * Safety net: redirect to the original's public URL so the client still gets
     * an image (and the app never streams a broken/huge file through PHP). Takes
     * the already-resolved source (no re-resolve / extra metadata calls). The
     * bucket is public (see README/config), so a plain url() is correct and stays
     * CDN-cacheable; the configured cache headers let the CDN absorb it. Only disk
     * sources have a public URL — a local source has none, so it 404s honestly
     * rather than emitting a malformed /storage//abs/path redirect. Throws, so the
     * caller never falls through.
     */
    protected function redirectToOriginal(?ImageSource $source): never
    {
        abort_unless($source?->type === 'disk', 404);

        $url = Storage::disk((string) $source->disk)->url($source->path);

        throw new HttpResponseException(
            redirect()->away($url)->withHeaders(config()->array('image-transform-url.headers'))
        );
    }
}
