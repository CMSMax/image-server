<?php

declare(strict_types=1);

use App\Jobs\ProcessImageTransform;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Coverage for the app-level abuse guard: width/height are clamped to the
 * nearest whitelisted size and only whitelisted formats are accepted, BEFORE
 * the cache/dedup key is computed — so an enumerated width=101, width=102...
 * collapses onto one bounded set of cache entries / queued jobs instead of an
 * unbounded one. See plans/260826-1035-async-queue-image-transform.
 */
beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('s3-cache');
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.cache.disk', 's3-cache');
    config()->set('image-transform-url.rate_limit.enabled', false);
    config()->set('image-transform-url.max_animated_frames', 30);
    config()->set('image-transform-url.async.enabled', true);
    config()->set('image-transform-url.sizes', [200, 400, 600, 800, 1000, 1280, 1440, 1920]);
    config()->set('image-transform-url.qualities', [60, 75, 90, 100]);
    config()->set('image-transform-url.allowed_formats', ['webp']);
});

it('clamps an out-of-whitelist quality to the nearest allowed value', function () {
    putFixture('static.png', 'dir/clampq.png');

    $this->get('/production/quality=70,format=webp/dir/clampq.png')->assertRedirect();

    // 70 is closer to 75 than to 60 -> must clamp to 75, not pass through raw.
    $parsed = ['format' => 'webp', 'quality' => 75];
    $endPath = '_cache/image-transform-url/production/'.md5(json_encode($parsed)).'_dir/clampq.png';
    Cache::put('image-transform-url:'.Storage::disk('s3-cache')->path($endPath), true, 3600);
    Storage::disk('s3-cache')->put($endPath, 'CLAMPED-75');

    $res = $this->get('/production/quality=70,format=webp/dir/clampq.png');
    $res->assertOk();
    expect($res->headers->get('X-Cache'))->toBe('HIT');
    expect($res->getContent())->toBe('CLAMPED-75');
});

it('collapses quality enumeration onto one dispatched job instead of one per value', function () {
    Queue::fake();
    putFixture('static.png', 'dir/enumq.png');

    // Both round to the nearest allowed quality (90) -> must dedup to one job.
    $this->get('/production/quality=86,format=webp/dir/enumq.png')->assertRedirect();
    $this->get('/production/quality=93,format=webp/dir/enumq.png')->assertRedirect();

    Queue::assertPushed(ProcessImageTransform::class, 1);
});

it('clamps an out-of-whitelist width to the nearest allowed size', function () {
    putFixture('static.png', 'dir/clampw.png');

    $this->get('/production/width=210,format=webp/dir/clampw.png')->assertRedirect();

    // The clamped (200px) cache entry must exist — not one keyed by the raw 210.
    $parsed = ['format' => 'webp', 'width' => 200]; // alphabetical — matches normalizeOptions()'s ksort()
    $endPath = '_cache/image-transform-url/production/'.md5(json_encode($parsed)).'_dir/clampw.png';
    Cache::put('image-transform-url:'.Storage::disk('s3-cache')->path($endPath), true, 3600);
    Storage::disk('s3-cache')->put($endPath, 'CLAMPED-200');

    $res = $this->get('/production/width=210,format=webp/dir/clampw.png');
    $res->assertOk();
    expect($res->headers->get('X-Cache'))->toBe('HIT');
    expect($res->getContent())->toBe('CLAMPED-200');
});

it('clamps an out-of-whitelist height to the nearest allowed size', function () {
    putFixture('static.png', 'dir/clamph.png');

    $this->get('/production/height=395,format=webp/dir/clamph.png')->assertRedirect();

    $parsed = ['format' => 'webp', 'height' => 400];
    $endPath = '_cache/image-transform-url/production/'.md5(json_encode($parsed)).'_dir/clamph.png';
    Cache::put('image-transform-url:'.Storage::disk('s3-cache')->path($endPath), true, 3600);
    Storage::disk('s3-cache')->put($endPath, 'CLAMPED-400');

    $res = $this->get('/production/height=395,format=webp/dir/clamph.png');
    $res->assertOk();
    expect($res->headers->get('X-Cache'))->toBe('HIT');
});

it('collapses width enumeration onto one dispatched job instead of one per value', function () {
    Queue::fake();
    putFixture('static.png', 'dir/enum.png');

    // Different raw widths that both round to the nearest allowed size (200) must
    // dedup to a single job — the whole point of the whitelist is bounding this.
    $this->get('/production/width=203,format=webp/dir/enum.png')->assertRedirect();
    $this->get('/production/width=210,format=webp/dir/enum.png')->assertRedirect();

    Queue::assertPushed(ProcessImageTransform::class, 1);
});

it('ignores option order when computing the cache/dedup key', function () {
    Queue::fake();
    putFixture('static.png', 'dir/order.png');

    $this->get('/production/width=200,format=webp/dir/order.png')->assertRedirect();
    $this->get('/production/format=webp,width=200/dir/order.png')->assertRedirect();

    Queue::assertPushed(ProcessImageTransform::class, 1);
});

it('rejects an explicit non-whitelisted format', function () {
    putFixture('static.png', 'dir/badformat.png');

    $this->get('/production/width=200,format=png/dir/badformat.png')->assertNotFound();
});

it('allows an explicit whitelisted format', function () {
    Queue::fake();
    putFixture('static.png', 'dir/goodformat.png');

    $this->get('/production/width=200,format=webp/dir/goodformat.png')->assertRedirect();
    Queue::assertPushed(ProcessImageTransform::class, 1);
});

it('leaves an omitted format unaffected by the whitelist', function () {
    Queue::fake();
    putFixture('static.png', 'dir/noformat.png');

    // No format= param at all -> not gated by allowed_formats (vendor defaults
    // to the source's own mime instead).
    $this->get('/production/width=200/dir/noformat.png')->assertRedirect();
    Queue::assertPushed(ProcessImageTransform::class, 1);
});

it('passes width/height through unclamped when the whitelist is disabled', function () {
    config()->set('image-transform-url.sizes', []);
    Queue::fake();
    putFixture('static.png', 'dir/nosizeguard.png');

    $this->get('/production/width=57,format=webp/dir/nosizeguard.png')->assertRedirect();

    $parsed = ['format' => 'webp', 'width' => 57]; // alphabetical — matches normalizeOptions()'s ksort()
    $endPath = '_cache/image-transform-url/production/'.md5(json_encode($parsed)).'_dir/nosizeguard.png';
    Cache::put('image-transform-url:'.Storage::disk('s3-cache')->path($endPath), true, 3600);
    Storage::disk('s3-cache')->put($endPath, 'RAW-57');

    $res = $this->get('/production/width=57,format=webp/dir/nosizeguard.png');
    $res->assertOk();
    expect($res->headers->get('X-Cache'))->toBe('HIT');
});
