<?php

declare(strict_types=1);

namespace App\Actions;

use AceOfAces\LaravelImageTransformUrl\Actions\TransformImageAction as BaseTransformImageAction;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageResult;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageSource;
use App\Jobs\ProcessImageTransform;
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
            $options = $this->normalizeOptions($options);

            if ($cached = $this->servedFromCache($pathPrefix, $path, $options)) {
                return $cached; // disk-native cache read — works for S3 (the vendor's File:: read does not)
            }

            // When image is not optimized, dispatch to the queue and return a temporary redirect
            if (config()->boolean('image-transform-url.async.enabled')) {
                // Prior permanent failure — serve the long-cache redirect, do not re-dispatch.
                if (Cache::has($this->failureSentinelKey($pathPrefix, $path, $options))) {
                    $this->redirectToOriginal($source);
                }

                $this->guardAnimatedFrames($ip, $source, (string) $path); // over-cap → permanent redirect

                // Rate-limit by IP+path (not options) BEFORE dispatch. width/height/
                // quality are clamped to a fixed whitelist above (normalizeOptions()),
                // so a straightforward param-enumeration attack no longer forks the
                // dedup key — but the whitelist is config-driven (an empty `sizes`/
                // `qualities` disables clamping) and this is the only per-request
                // throttle left on the miss path, so keep it as defense-in-depth —
                // the same protection the vendor gave every synchronous transform.
                if (
                    config()->boolean('image-transform-url.rate_limit.enabled') &&
                    ! in_array(App::environment(), config()->array('image-transform-url.rate_limit.disabled_for_environments'), true)
                ) {
                    $this->rateLimit($ip, $path);
                }

                ProcessImageTransform::dispatch($pathPrefix, $path, $options); // deduped by ShouldBeUnique

                // TEMPORARY redirect — short TTL so the CDN re-checks and picks up the HIT.
                $this->redirectToOriginal($source, [
                    'Cache-Control' => 'public, max-age='.config()->integer('image-transform-url.async.pending_redirect_max_age'),
                ]);
            }

            // If image is optimized, show the image
            $this->guardAnimatedFrames($ip, $source, (string) $path); // may throttle + redirect

            return parent::handle($ip, $pathPrefix, $options, $path);
        } catch (HttpResponseException $redirect) {
            throw $redirect; // our redirect — propagate as-is
        } catch (HttpExceptionInterface $e) {
            throw $e; // preserve HTTP control-flow (404 not-found, 429 rate-limit, etc.)
        } catch (DecoderException|EncoderException|NotSupportedException|\ImagickException $e) {
            // Decode/encode failure — serve the original instead of a 500.
            report($e);
            $this->redirectToOriginal($source); // throws HttpResponseException
        }
    }

    /**
     * App-level abuse guard: canonicalize width/height to the nearest allowed
     * size and reject a non-whitelisted explicit format, before anything else
     * reads/writes the cache or dispatches a job. Re-serializes with sorted
     * keys so option order can't fork the cache/dedup key either (e.g.
     * `format=webp,width=200` and `width=200,format=webp` collapse to one).
     */
    protected function normalizeOptions(string $rawOptions): string
    {
        $options = static::parseOptions($rawOptions);

        if (array_key_exists('width', $options)) {
            $options['width'] = $this->nearestAllowedValue((int) $options['width'], config()->array('image-transform-url.sizes'));
        }

        if (array_key_exists('height', $options)) {
            $options['height'] = $this->nearestAllowedValue((int) $options['height'], config()->array('image-transform-url.sizes'));
        }

        if (array_key_exists('quality', $options)) {
            $options['quality'] = $this->nearestAllowedValue((int) $options['quality'], config()->array('image-transform-url.qualities'));
        }

        if (array_key_exists('format', $options)) {
            abort_unless(
                in_array($options['format'], config()->array('image-transform-url.allowed_formats'), true),
                404,
            );
        }

        ksort($options);

        return collect($options)->map(fn ($value, $key) => "{$key}={$value}")->implode(',');
    }

    /**
     * Round a requested width/height/quality to the nearest value in the given
     * whitelist, bounding cache/queue cardinality per source image (an
     * enumerated width=101, width=102, ... all collapse onto one entry). An
     * empty whitelist disables the guard — the raw value passes through.
     */
    protected function nearestAllowedValue(int $value, array $whitelist): int
    {
        if ($whitelist === []) {
            return $value;
        }

        return collect($whitelist)->sortBy(fn (int $allowed) => abs($allowed - $value))->first();
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
     * Background half of the async miss path (called from ProcessImageTransform).
     * Reuses the vendor pipeline (DRY — no re-implementing Intervention), then
     * stores the result explicitly and exactly once from the returned bytes.
     */
    public function processAndCache(?string $pathPrefix, ?string $path, string $rawOptions): void
    {
        // Trusted internal call — the rate limiter is a public-abuse guard, and the
        // parent's own cache read/deferred-store must not run (we store explicitly
        // below). A persistent `queue:work` process reuses the same booted app
        // across many jobs (config is NOT reset between them), so these overrides
        // MUST be restored — otherwise the first async job permanently disables
        // caching/rate-limiting for every request that process (or, under the
        // `sync` queue driver, the current request/test) handles afterwards.
        $originalRateLimit = config()->boolean('image-transform-url.rate_limit.enabled');
        $originalCacheEnabled = config()->boolean('image-transform-url.cache.enabled');

        config()->set('image-transform-url.rate_limit.enabled', false);
        config()->set('image-transform-url.cache.enabled', false);

        try {
            $result = parent::handle(null, $pathPrefix, $rawOptions, $path);

            $this->storeResultBytes($pathPrefix, $path, $rawOptions, $result);
        } finally {
            config()->set('image-transform-url.rate_limit.enabled', $originalRateLimit);
            config()->set('image-transform-url.cache.enabled', $originalCacheEnabled);
        }
    }

    /**
     * Store the transform from an ImageResult's raw bytes
     */
    protected function storeResultBytes(?string $pathPrefix, ?string $path, string $rawOptions, ImageResult $result): void
    {
        $options = static::parseOptions($rawOptions);
        $diskName = config()->string('image-transform-url.cache.disk');

        Storage::disk($diskName)->put($this->getCacheEndPath($pathPrefix, $path, $options), $result->content);

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
     * Cache key for the permanent-failure sentinel written by the job's failed().
     */
    protected function failureSentinelKey(?string $pathPrefix, ?string $path, string $rawOptions): string
    {
        $options = static::parseOptions($rawOptions);

        return 'image-transform-url:failed:'.$this->getCacheEndPath($pathPrefix, $path, $options);
    }

    /**
     * Mark a transform as permanently failed so the request path stops
     * re-dispatching and serves the long-cache permanent redirect instead.
     */
    public function markTransformFailed(?string $pathPrefix, ?string $path, string $rawOptions): void
    {
        Cache::put(
            $this->failureSentinelKey($pathPrefix, $path, $rawOptions),
            true,
            config()->integer('image-transform-url.async.failed_lifetime'),
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
     * Safety net: redirect to the original's public URL so the client still gets
     * an image (and the app never streams a broken/huge file through PHP). Takes
     * the already-resolved source (no re-resolve / extra metadata calls). The
     * bucket is public (see README/config), so a plain url() is correct and stays
     * CDN-cacheable. Only disk sources have a public URL — a local source has
     * none, so it 404s honestly rather than emitting a malformed /storage//abs/path
     * redirect. Throws, so the caller never falls through.
     *
     * $headers defaults to the permanent (30-day) config headers — used for the
     * frame-guard/failure/sentinel redirects, where the source will never
     * transform. The async-miss path passes a short-lived header set instead, so
     * the CDN re-checks origin once the queued transform finishes.
     */
    protected function redirectToOriginal(?ImageSource $source, ?array $headers = null): never
    {
        abort_unless($source?->type === 'disk', 404);
        $headers ??= config()->array('image-transform-url.headers');

        $url = Storage::disk((string) $source->disk)->url($source->path);

        throw new HttpResponseException(
            redirect()->away($url)->withHeaders($headers)
        );
    }
}
