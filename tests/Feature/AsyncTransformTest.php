<?php

declare(strict_types=1);

use App\Jobs\ProcessImageTransform;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Coverage for the async miss path: a cache miss dispatches a background
 * transform job and redirects (short TTL) to the original instead of
 * transforming on the request. See plans/260826-1035-async-queue-image-transform.
 */
beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('s3-cache');
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.cache.disk', 's3-cache');
    config()->set('image-transform-url.rate_limit.enabled', false);
    config()->set('image-transform-url.max_animated_frames', 30);
    config()->set('image-transform-url.async.enabled', true);
    // These tests use arbitrary widths to exercise dispatch/dedup/rate-limit
    // mechanics, not the size whitelist — see AllowedSizesAndFormatsTest.php.
    config()->set('image-transform-url.sizes', []);
    config()->set('image-transform-url.qualities', []);
});

it('dispatches the transform job and redirects with a short-lived cache header on a miss', function () {
    Queue::fake();
    // Pin the TTL the assertion checks so the test doesn't break when the config
    // default changes — it verifies the miss redirect uses the CONFIGURED pending
    // TTL (short), not the permanent 30-day header.
    config()->set('image-transform-url.async.pending_redirect_max_age', 10);
    putFixture('static.png', 'dir/s.png');

    $res = $this->get('/production/width=32,format=webp/dir/s.png');

    $res->assertRedirect();
    expect($res->headers->get('Cache-Control'))->toContain('max-age=10');
    expect($res->headers->get('Cache-Control'))->not->toContain('2592000');
    Queue::assertPushed(ProcessImageTransform::class, 1);
});

it('dedups the dispatch for identical concurrent requests', function () {
    Queue::fake();
    putFixture('static.png', 'dir/dedup.png');

    $this->get('/production/width=32,format=webp/dir/dedup.png')->assertRedirect();
    $this->get('/production/width=32,format=webp/dir/dedup.png')->assertRedirect();

    Queue::assertPushed(ProcessImageTransform::class, 1);
});

it('does not dispatch on a cache hit', function () {
    Queue::fake();
    putFixture('static.png', 'dir/hit.png');

    // Seed the cache directly (bypass the job) then request.
    $parsed = ['format' => 'webp', 'width' => 32]; // alphabetical — matches normalizeOptions()'s ksort()
    $endPath = '_cache/image-transform-url/production/'.md5(json_encode($parsed)).'_dir/hit.png';
    Storage::disk('s3-cache')->put($endPath, 'SEEDED');
    Cache::put('image-transform-url:'.Storage::disk('s3-cache')->path($endPath), true, 3600);

    $res = $this->get('/production/width=32,format=webp/dir/hit.png');

    $res->assertOk();
    expect($res->headers->get('X-Cache'))->toBe('HIT');
    Queue::assertNothingPushed();
});

it('runs the job for real and populates the cache so the next request is a HIT', function () {
    putFixture('static.png', 'dir/job.png');

    $this->get('/production/width=32,format=webp/dir/job.png')->assertRedirect();

    $res = $this->get('/production/width=32,format=webp/dir/job.png');
    $res->assertOk();
    expect($res->headers->get('X-Cache'))->toBe('HIT');
});

it('writes a failure sentinel and serves the permanent redirect without re-dispatching', function () {
    // Undecodable bytes under an allowed mime -> job's handle() throws -> failed() sentinel.
    $header = substr((string) file_get_contents(base_path('tests/fixtures/animated-tiny.webp')), 0, 16);
    Storage::disk('s3')->put('dir/broken.webp', $header.str_repeat("\x00", 256));

    $this->get('/production/width=64,format=webp/dir/broken.webp')->assertRedirect();

    Queue::fake();
    $res = $this->get('/production/width=64,format=webp/dir/broken.webp');

    $res->assertRedirect();
    expect($res->headers->get('Cache-Control'))->toContain('max-age=2592000');
    Queue::assertNothingPushed();
});

it('still redirects animations over the frame cap without dispatching', function () {
    config()->set('image-transform-url.max_animated_frames', 2);
    Queue::fake();
    putFixture('animated-tiny.webp', 'dir/big.webp');

    $res = $this->get('/production/width=64,format=webp/dir/big.webp');

    $res->assertRedirect();
    expect($res->headers->get('Cache-Control'))->toContain('max-age=2592000');
    Queue::assertNothingPushed();
});

it('throttles only the dispatch on the miss path — still redirects, never 429s', function () {
    Queue::fake();
    config()->set('image-transform-url.rate_limit.enabled', true);
    config()->set('image-transform-url.rate_limit.disabled_for_environments', []);
    config()->set('image-transform-url.rate_limit.max_attempts', 1);
    putFixture('static.png', 'dir/spam.png');

    $this->get('/production/width=32,format=webp/dir/spam.png')->assertRedirect();
    // Different options -> distinct ShouldBeUnique dedup key, but the SAME ip+path
    // rate-limit key -> the 2nd enqueue is throttled. The request MUST still get a
    // redirect (a miss always serves an image); only the dispatch is suppressed.
    $this->get('/production/width=33,format=webp/dir/spam.png')->assertRedirect();

    Queue::assertPushed(ProcessImageTransform::class, 1);
});

it('transforms synchronously when the async flag is off', function () {
    config()->set('image-transform-url.async.enabled', false);
    Queue::fake();
    putFixture('static.png', 'dir/sync.png');

    $res = $this->get('/production/width=32,format=webp/dir/sync.png');

    $res->assertOk();
    Queue::assertNothingPushed();
});
