<?php

declare(strict_types=1);

namespace App\Actions;

use AceOfAces\LaravelImageTransformUrl\Actions\TransformImageAction as BaseTransformImageAction;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageResult;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageSource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\AutoEncoder;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * App override of the vendor transform action.
 *
 * The default Intervention driver is GD, which cannot decode animated WebP and
 * throws an uncaught DecoderException -> HTTP 500. This subclass wraps handle()
 * with a two-stage safety net: (1) retry the transform with the Imagick driver
 * (animation-capable), (2) if that also fails, serve the original bytes as-is.
 * Bound over the vendor FQCN in AppServiceProvider so controllers resolve it.
 */
class TransformImageAction extends BaseTransformImageAction
{
    public function handle(?string $ip, ?string $pathPrefix, string $options, ?string $path = null): ImageResult
    {
        try {
            // GD fast path — handles all static images unchanged.
            return parent::handle($ip, $pathPrefix, $options, $path);
        } catch (Throwable $gdError) {
            // Preserve HTTP control-flow (404 not-found, 429 rate-limit, etc.).
            // Only genuine decode/processing failures get the fallback treatment.
            if ($gdError instanceof HttpExceptionInterface) {
                throw $gdError;
            }

            // GD could not decode (e.g. animated WebP). Try Imagick before giving up.
            try {
                if (extension_loaded('imagick')) {
                    return $this->transformWithImagick($pathPrefix, $path, $options);
                }
            } catch (Throwable $imagickError) {
                report($imagickError);
            }

            // Guaranteed safety net: never let a decode failure become a 500.
            report($gdError);

            return $this->serveOriginal($pathPrefix, $path, $options);
        }
    }

    /**
     * Real transform via the Imagick driver, which decodes and preserves
     * animation across scale/encode. Only enabled options (width/height/
     * format/quality) are honoured — matching the vendor pipeline.
     */
    protected function transformWithImagick(?string $pathPrefix, ?string $path, string $rawOptions): ImageResult
    {
        $source = $this->handlePath($pathPrefix, $path); // re-resolves + re-validates (404 stays 404)
        $options = static::parseOptions($rawOptions);
        $bytes = $this->readSourceBytes($source);

        // Pre-flight OOM guard: an unbounded animated upscale can exhaust
        // memory_limit -> uncatchable PHP fatal, defeating the safety net.
        if ($this->animatedBudgetExceeded($bytes, $options)) {
            return $this->serveOriginal($pathPrefix, $path, $rawOptions);
        }

        $image = ImageManager::imagick()->read($bytes);

        if (Arr::hasAny($options, ['width', 'height'])) {
            $image->scale(
                $this->getPositiveIntOptionValue($options, 'width', $image->width() * 2),
                $this->getPositiveIntOptionValue($options, 'height', $image->height() * 2),
            );
        }

        $format = $this->getStringOptionValue($options, 'format', $source->mime);
        $quality = $this->getPositiveIntOptionValue($options, 'quality', 100, 100);
        $encoded = $image->encode($this->buildEncoder((string) $format, (int) $quality));

        if (config()->boolean('image-transform-url.cache.enabled')) {
            // Reuse the vendor cache writer so the base handle() finds this HIT.
            $this->storeCachedImage($pathPrefix, $path, $options, $encoded);
        }

        return new ImageResult(
            content: $encoded->toString(),
            mimeType: $encoded->mimetype(),
            cacheHit: false,
        );
    }

    /**
     * Guaranteed safety net: stream the untouched original with its real mime
     * type, cached under the transform key so repeat requests are cheap HITs.
     */
    protected function serveOriginal(?string $pathPrefix, ?string $path, string $rawOptions): ImageResult
    {
        $source = $this->handlePath($pathPrefix, $path);
        $content = $this->readSourceBytes($source);

        if (config()->boolean('image-transform-url.cache.enabled')) {
            $this->cacheOriginalBytes($pathPrefix, $path, static::parseOptions($rawOptions), $content);
        }

        return new ImageResult(
            content: $content,
            mimeType: $source->mime,
            cacheHit: false,
        );
    }

    /**
     * Persist raw original bytes under the transform cache path (mirrors the
     * vendor storeCachedImage() but skips encoding), and set the flag the base
     * checks on read. The stored file keeps its extension, so mime detection
     * on the next HIT resolves correctly.
     */
    protected function cacheOriginalBytes(?string $pathPrefix, ?string $path, array $options, string $bytes): void
    {
        $disk = Storage::disk(config()->string('image-transform-url.cache.disk'));
        $disk->put($this->getCacheEndPath($pathPrefix, $path, $options), $bytes);

        Cache::put(
            key: 'image-transform-url:'.$this->getCachePath($pathPrefix, $path, $options),
            value: true,
            ttl: config()->integer('image-transform-url.cache.lifetime'),
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
     * Resolve the Intervention encoder for a format/quality pair. Mirrors the
     * vendor match() so cached output is byte-compatible with the GD path.
     */
    protected function buildEncoder(string $format, int $quality): EncoderInterface
    {
        return match ($format) {
            'png', 'image/png' => new PngEncoder,
            'webp', 'image/webp' => new WebpEncoder($quality),
            'jpeg', 'jpg', 'image/jpeg' => new JpegEncoder($quality),
            'gif', 'image/gif' => new GifEncoder,
            default => new AutoEncoder(quality: $quality),
        };
    }

    /**
     * Cheap pre-flight check for whether an Imagick transform would blow the
     * memory budget. Uses pingImageBlob() (header-only, no pixel decode) to get
     * frame count + source dimensions, then estimates output frame-pixels.
     * Threshold is env-tunable (IMAGE_TRANSFORM_MAX_ANIMATED_PIXELS); over it we
     * skip Imagick and serve the original instead of risking a fatal OOM.
     */
    protected function animatedBudgetExceeded(string $bytes, array $options): bool
    {
        $max = (int) env('IMAGE_TRANSFORM_MAX_ANIMATED_PIXELS', 250_000_000);
        if ($max <= 0) {
            return false; // guard disabled
        }

        try {
            $ping = new \Imagick;
            $ping->pingImageBlob($bytes);
            $frames = max(1, $ping->getNumberImages());
            $srcW = max(1, $ping->getImageWidth());
            $srcH = max(1, $ping->getImageHeight());
            $ping->clear();
        } catch (Throwable) {
            return false; // cannot measure -> let the transform attempt proceed
        }

        if ($frames <= 1) {
            return false; // not animated; GD-equivalent memory profile
        }

        $reqW = $this->getPositiveIntOptionValue($options, 'width');
        $reqH = $this->getPositiveIntOptionValue($options, 'height');

        // Estimate output dimensions the same way the scale() call would.
        [$outW, $outH] = match (true) {
            $reqW && $reqH => [$reqW, $reqH],
            (bool) $reqW => [$reqW, (int) round($srcH * $reqW / $srcW)],
            (bool) $reqH => [(int) round($srcW * $reqH / $srcH), $reqH],
            default => [$srcW * 2, $srcH * 2],
        };

        $budget = $frames * max(1, $outW) * max(1, $outH);

        if ($budget > $max) {
            Log::warning('image-transform-url: animated Imagick guard tripped, serving original', [
                'frames' => $frames,
                'output' => $outW.'x'.$outH,
                'budget' => $budget,
                'max' => $max,
            ]);

            return true;
        }

        return false;
    }
}
