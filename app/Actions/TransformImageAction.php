<?php

declare(strict_types=1);

namespace App\Actions;

use AceOfAces\LaravelImageTransformUrl\Actions\TransformImageAction as BaseTransformImageAction;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageResult;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageSource;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
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
 * WebP that GD cannot — fixing the reported 500. On top of the vendor pipeline:
 *
 *  1. Frame guard: a very long animation (e.g. 209 frames) coalesces to GBs of
 *     RAM in Imagick — an uncatchable OOM. Frame count is read from the container
 *     (no decode); animations over the cap are redirected to the original.
 *  2. No upscale: the vendor allows up to 2x enlargement. Clamp requested
 *     width/height to the source's native size so we never inflate an image.
 *  3. Failure fallback: a still-undecodable source (or encoder error) redirects
 *     to the original instead of returning a 500.
 *
 * (1) and (2) share a single source read, done only on a cache-miss. Bound over
 * the vendor FQCN in AppServiceProvider so controllers resolve this subclass.
 */
class TransformImageAction extends BaseTransformImageAction
{
    public function handle(?string $ip, ?string $pathPrefix, string $options, ?string $path = null): ImageResult
    {
        try {
            $options = $this->preprocess($pathPrefix, $path, $options); // may redirect; may clamp options

            return parent::handle($ip, $pathPrefix, $options, $path);
        } catch (HttpResponseException $redirect) {
            throw $redirect; // frame-guard / oversize redirect — propagate as-is
        } catch (HttpExceptionInterface $e) {
            throw $e; // preserve HTTP control-flow (404 not-found, 429 rate-limit, etc.)
        } catch (Throwable $e) {
            report($e);
            $this->redirectToOriginal($pathPrefix, $path); // throws HttpResponseException
        }
    }

    /**
     * On a cache-miss, read the source once to (a) redirect animations over the
     * frame cap before Imagick OOMs, and (b) clamp width/height so we never
     * upscale the original. Returns the (possibly clamped) options string.
     * Static images without a resize, and cache hits, read nothing here.
     */
    protected function preprocess(?string $pathPrefix, ?string $path, string $rawOptions): string
    {
        $parsed = static::parseOptions($rawOptions);
        $animatable = (bool) preg_match('/\.(webp|gif)$/i', $path ?? $pathPrefix ?? '');
        $wantsResize = Arr::hasAny($parsed, ['width', 'height']);

        if ((! $animatable && ! $wantsResize) || $this->isAlreadyCached($pathPrefix, $path, $rawOptions)) {
            return $rawOptions;
        }

        $source = $this->handlePath($pathPrefix, $path); // validates; 404 stays 404
        $bytes = $this->readSourceBytes($source);

        // (a) Frame guard — redirect very long animations before decoding.
        $max = (int) config('image-transform-url.max_animated_frames', 0);
        if ($animatable && $max > 0 && ($frames = $this->countAnimationFrames($bytes, $source->mime)) > $max) {
            Log::warning('image-transform-url: animation exceeds frame guard, redirecting to original', [
                'frames' => $frames,
                'max' => $max,
                'path' => $source->path,
            ]);
            $this->redirectToOriginal($pathPrefix, $path); // throws HttpResponseException
        }

        // (b) Never upscale — clamp requested dimensions to the source's own size.
        if ($wantsResize && ($size = @getimagesizefromstring($bytes)) !== false) {
            return $this->clampToSourceSize($rawOptions, $parsed, (int) $size[0], (int) $size[1]);
        }

        return $rawOptions;
    }

    /**
     * Rewrite the options string so width/height never exceed the source size.
     * Preserves order and other options; returns the input unchanged if neither
     * dimension would upscale.
     */
    protected function clampToSourceSize(string $rawOptions, array $parsed, int $srcW, int $srcH): string
    {
        $w = isset($parsed['width']) ? (int) $parsed['width'] : null;
        $h = isset($parsed['height']) ? (int) $parsed['height'] : null;

        if (($w === null || $w <= $srcW) && ($h === null || $h <= $srcH)) {
            return $rawOptions; // no upscale requested
        }

        return implode(',', array_map(function (string $opt) use ($srcW, $srcH) {
            [$key, $value] = array_pad(explode('=', $opt, 2), 2, null);

            return match ($key) {
                'width' => 'width='.min((int) $value, $srcW),
                'height' => 'height='.min((int) $value, $srcH),
                default => $opt,
            };
        }, explode(',', $rawOptions)));
    }

    /**
     * Whether the transform is already cached (mirrors the vendor cache check).
     * Cheap — no download — so cache hits skip the source read above.
     */
    protected function isAlreadyCached(?string $pathPrefix, ?string $path, string $rawOptions): bool
    {
        if (! config()->boolean('image-transform-url.cache.enabled')) {
            return false;
        }

        $cachePath = $this->getCachePath($pathPrefix, $path, static::parseOptions($rawOptions));

        return File::exists($cachePath) && Cache::has('image-transform-url:'.$cachePath);
    }

    /**
     * Count animation frames from the container structure (no pixel decode):
     * WebP ANMF chunks / GIF Graphic Control Extensions. A static image → 1.
     */
    protected function countAnimationFrames(string $bytes, string $mime): int
    {
        return match ($mime) {
            'image/webp' => max(1, substr_count($bytes, 'ANMF')),
            'image/gif' => max(1, substr_count($bytes, "\x21\xF9")),
            default => 1,
        };
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
     * Throws, so the caller never falls through.
     */
    protected function redirectToOriginal(?string $pathPrefix, ?string $path): never
    {
        $source = $this->handlePath($pathPrefix, $path); // re-resolves + re-validates (404 stays 404)

        $url = $source->type === 'disk'
            ? Storage::disk((string) $source->disk)->url($source->path)
            : Storage::url($source->path);

        throw new HttpResponseException(redirect()->away($url));
    }
}
