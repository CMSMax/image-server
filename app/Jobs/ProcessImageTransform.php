<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\TransformImageAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
     * Permanent-failure sentinel — request path stops re-dispatching, serves
     * the long-cache permanent redirect instead (matches today's decode-failure UX).
     */
    public function failed(?Throwable $e): void
    {
        app(TransformImageAction::class)->markTransformFailed(
            $this->pathPrefix, $this->path, $this->options,
        );
    }
}
