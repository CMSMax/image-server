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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Thin override of the vendor transform action.
 *
 * The app uses the Imagick driver (config/image.php), which decodes animated
 * WebP that GD cannot — fixing the reported 500. Two safety nets on top:
 *
 *  1. Frame guard: transforming a long animation coalesces every frame at full
 *     canvas — GBs of RAM and tens of seconds (an uncatchable OOM / timeout).
 *     Animations with more than max_animated_frames are redirected to the
 *     original. Gated on the DETECTED mime (not the caller-controlled name); the
 *     frame count is an Imagick header ping cached by disk+path+mtime.
 *  2. Failure fallback: a still-undecodable source (or encoder error) redirects
 *     to the original rather than returning a 500.
 *
 * It also serves cached transforms with disk-native reads so an S3 cache disk
 * works (the vendor reads the cache via the local File:: facade, which always
 * misses on an S3 key). Both redirects are rate-limited and carry the configured
 * cache headers so the CDN absorbs repeats. Bound over the vendor FQCN in
 * AppServiceProvider.
 */
class TransformImageAction extends BaseTransformImageAction
{
    public function handle(?string $ip, ?string $pathPrefix, string $options, ?string $path = null): ImageResult
    {
        try {
            if ($cached = $this->servedFromCache($pathPrefix, $path, $options)) {
                return $cached; // disk-native cache read — works for S3 (the vendor's File:: read does not)
            }

            $this->guardAnimatedFrames($ip, $pathPrefix, $path, $options); // may throttle + redirect

            return parent::handle($ip, $pathPrefix, $options, $path);
        } catch (HttpResponseException $redirect) {
            throw $redirect; // frame-guard redirect — propagate as-is
        } catch (HttpExceptionInterface $e) {
            throw $e; // preserve HTTP control-flow (404 not-found, 429 rate-limit, etc.)
        } catch (Throwable $e) {
            report($e);
            $this->redirectToOriginal($pathPrefix, $path); // throws HttpResponseException
        }
    }

    /**
     * Redirect animations with more than the configured frame count straight to
     * the original, before Imagick decodes (and coalesces) them into GBs of RAM.
     * Gates on the source's DETECTED mime so a renamed animation can't slip past.
     * Runs only on a cache-miss; the frame count is cached by disk+path+mtime so
     * repeat requests skip the download. Disabled when max_animated_frames <= 0.
     */
    protected function guardAnimatedFrames(?string $ip, ?string $pathPrefix, ?string $path, string $rawOptions): void
    {
        $max = (int) config('image-transform-url.max_animated_frames', 0);
        if ($max <= 0) {
            return; // guard disabled
        }

        // Validate + resolve by content mime (extension is caller-controlled).
        $source = $this->handlePath($pathPrefix, $path); // 404 stays 404
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
            $this->rateLimit($ip, (string) $path);
        }

        Log::warning('image-transform-url: animation exceeds frame guard, redirecting to original', [
            'mime' => $source->mime,
            'max' => $max,
            'path' => $source->path,
        ]);

        $this->redirectToOriginal($pathPrefix, $path); // throws HttpResponseException
    }

    /**
     * Real frame count via a header-only Imagick ping (no pixel decode), cached
     * by disk + path + last-modified so repeat requests skip the source download.
     */
    protected function animationFrameCount(ImageSource $source): int
    {
        $lastModified = $source->type === 'disk'
            ? (int) Storage::disk((string) $source->disk)->lastModified($source->path)
            : (int) File::lastModified($source->path);

        $key = 'image-transform-url:frames:'.($source->disk ?? 'local').':'.$source->path.':'.$lastModified;

        return (int) Cache::remember($key, config()->integer('image-transform-url.cache.lifetime'), function () use ($source) {
            $probe = new \Imagick;
            $probe->pingImageBlob($this->readSourceBytes($source)); // headers only
            $frames = max(1, $probe->getNumberImages());
            $probe->clear();

            return $frames;
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
     * Read the raw source bytes for a disk- or local-based source.
     */
    protected function readSourceBytes(ImageSource $source): string
    {
        return $source->type === 'disk'
            ? (string) Storage::disk((string) $source->disk)->get($source->path)
            : (string) File::get($source->path);
    }

    /**
     * Safety net: redirect to the original's storage/CDN URL so the client still
     * gets an image (and the app never streams a broken/huge file through PHP).
     * The bucket is public (see README/config), so a plain url() is correct and
     * stays CDN-cacheable; the configured cache headers let the CDN absorb it.
     * Throws, so the caller never falls through.
     */
    protected function redirectToOriginal(?string $pathPrefix, ?string $path): never
    {
        $source = $this->handlePath($pathPrefix, $path); // re-resolves + re-validates (404 stays 404)

        $url = $source->type === 'disk'
            ? Storage::disk((string) $source->disk)->url($source->path)
            : Storage::url($source->path);

        throw new HttpResponseException(
            redirect()->away($url)->withHeaders(config()->array('image-transform-url.headers'))
        );
    }
}
