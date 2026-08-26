<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\TransformImageAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\Exceptions\EncoderException;
use Intervention\Image\Exceptions\NotSupportedException;
use Throwable;

/**
 * Background half of the async miss path: transform the source and store it
 * to cache. Dispatched by TransformImageAction::handle() on a cache miss
 * while the request itself gets a short-lived redirect to the original.
 */
class ProcessImageTransform implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public ?string $pathPrefix,
        public string $path,
        public string $options, // raw options string, e.g. "width=250,quality=80,format=webp"
    ) {
        $this->onQueue(config()->string('image-transform-url.async.queue'));
        if ($conn = config('image-transform-url.async.connection')) {
            $this->onConnection($conn);
        }
    }

    /**
     * Dedup: at most one job per transform per unique window.
     */
    public function uniqueId(): string
    {
        return ($this->pathPrefix ?? '').'|'.$this->path.'|'.$this->options;
    }

    public function uniqueFor(): int
    {
        return config()->integer('image-transform-url.async.unique_for');
    }

    public function handle(TransformImageAction $action): void
    {
        $action->processAndCache($this->pathPrefix, $this->path, $this->options);
    }

    /**
     * Sentinel ONLY a genuinely permanent fault — a decode/encode failure that
     * re-running will never fix. The request path then stops re-dispatching and
     * serves the long-cache permanent redirect instead (matches the synchronous
     * decode-failure UX, and mirrors the swallowed types in TransformImageAction).
     *
     * A transient infra fault (S3 unreachable, network, memory pressure) is NOT
     * sentineled: leaving no sentinel lets the next request re-dispatch so the
     * transform can recover on its own, instead of serving unoptimized originals
     * for the full failed_lifetime window over a momentary blip.
     */
    public function failed(?Throwable $e): void
    {
        $permanent = $e instanceof DecoderException
            || $e instanceof EncoderException
            || $e instanceof NotSupportedException
            || $e instanceof \ImagickException;

        if (! $permanent) {
            return;
        }

        app(TransformImageAction::class)->markTransformFailed(
            $this->pathPrefix, $this->path, $this->options,
        );
    }
}
