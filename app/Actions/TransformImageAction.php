<?php

declare(strict_types=1);

namespace App\Actions;

use AceOfAces\LaravelImageTransformUrl\Actions\TransformImageAction as BaseTransformImageAction;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageResult;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageSource;
use Illuminate\Http\Exceptions\HttpResponseException;
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
 *  1. Frame guard: a very long animation (e.g. 209 frames) coalesces to GBs of
 *     RAM in Imagick — an uncatchable OOM. Before decoding, count frames from
 *     the container (no decode) and redirect oversized animations to the
 *     original instead. Checked only on cache-miss, only for webp/gif.
 *  2. Failure fallback: if a source is still corrupt/undecodable (or the encoder
 *     throws), redirect to the original rather than return a 500.
 *
 * Bound over the vendor FQCN in AppServiceProvider so controllers resolve it.
 */
class TransformImageAction extends BaseTransformImageAction
{
    public function handle(?string $ip, ?string $pathPrefix, string $options, ?string $path = null): ImageResult
    {
        try {
            $this->guardAnimatedFrames($pathPrefix, $path, $options); // may redirect

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
     * Runs only for webp/gif sources on a cache-miss, so static images and cache
     * hits pay nothing. Frame count is read from the container structure — no
     * pixel decode. Disabled when max_animated_frames <= 0.
     */
    protected function guardAnimatedFrames(?string $pathPrefix, ?string $path, string $rawOptions): void
    {
        $max = (int) config('image-transform-url.max_animated_frames', 0);
        if ($max <= 0) {
            return; // guard disabled
        }

        $candidate = $path ?? $pathPrefix ?? '';
        if (! preg_match('/\.(webp|gif)$/i', $candidate)) {
            return; // only animatable containers — static images skip entirely
        }

        if ($this->isAlreadyCached($pathPrefix, $path, $rawOptions)) {
            return; // a cached transform will be served; no fresh decode ahead
        }

        $source = $this->handlePath($pathPrefix, $path); // validates; 404 stays 404
        $frames = $this->countAnimationFrames($this->readSourceBytes($source), $source->mime);

        if ($frames > $max) {
            Log::warning('image-transform-url: animation exceeds frame guard, redirecting to original', [
                'frames' => $frames,
                'max' => $max,
                'path' => $source->path,
            ]);
            $this->redirectToOriginal($pathPrefix, $path); // throws HttpResponseException
        }
    }

    /**
     * Whether the transform is already cached (mirrors the vendor cache check).
     * Cheap — no download — so cache hits skip the frame guard entirely.
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
