<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for the image-transform guards.
 *
 * The app uses the Imagick driver (config/image.php) so animated WebP decodes
 * (the reported 500). On top: a frame guard redirects long animations, and any
 * decode failure redirects to the original. Both redirects are rate-limited and
 * CDN-cacheable, and the frame guard gates on the DETECTED mime, not the name.
 */
beforeEach(function () {
    Storage::fake('s3');
    config()->set('image-transform-url.cache.enabled', false);
    config()->set('image-transform-url.rate_limit.enabled', false);
    config()->set('image-transform-url.max_animated_frames', 30);
});

function putFixture(string $name, string $storedAs): void
{
    Storage::disk('s3')->put($storedAs, (string) file_get_contents(base_path("tests/fixtures/{$name}")));
}

it('transforms an animated webp without a 500 and preserves animation', function () {
    putFixture('animated-tiny.webp', 'dir/a.webp');

    $res = $this->get('/production/width=64,quality=70,format=webp/dir/a.webp');

    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('image/webp');
    expect(substr_count($res->getContent(), 'ANMF'))->toBeGreaterThan(1);
});

it('still transforms static images', function () {
    putFixture('static.png', 'dir/s.png');

    $this->get('/production/width=32,format=webp/dir/s.png')->assertOk();
});

it('gates the frame guard on the detected mime, not the file name', function () {
    // Animated WebP bytes stored under a .jpg name — must still be guarded.
    config()->set('image-transform-url.max_animated_frames', 2);
    putFixture('animated-tiny.webp', 'dir/many.jpg');

    $this->get('/production/width=64,format=webp/dir/many.jpg')->assertRedirect();
});

it('gives the over-cap redirect the configured CDN cache headers', function () {
    config()->set('image-transform-url.max_animated_frames', 2);
    putFixture('animated-tiny.webp', 'dir/big.webp');

    $res = $this->get('/production/width=64,format=webp/dir/big.webp');

    $res->assertRedirect();
    expect($res->headers->get('Cache-Control'))->toContain('max-age=2592000');
});

it('rate-limits the over-cap redirect path', function () {
    config()->set('image-transform-url.max_animated_frames', 2);
    config()->set('image-transform-url.rate_limit.enabled', true);
    config()->set('image-transform-url.rate_limit.disabled_for_environments', []);
    config()->set('image-transform-url.rate_limit.max_attempts', 1);
    putFixture('animated-tiny.webp', 'dir/spam.webp');

    $this->get('/production/width=64,format=webp/dir/spam.webp')->assertRedirect();
    $this->get('/production/width=64,format=webp/dir/spam.webp')->assertStatus(429);
});

it('preserves 429 on the normal transform path', function () {
    config()->set('image-transform-url.rate_limit.enabled', true);
    config()->set('image-transform-url.rate_limit.disabled_for_environments', []);
    config()->set('image-transform-url.rate_limit.max_attempts', 1);
    putFixture('static.png', 'dir/s.png');

    $this->get('/production/width=32,format=webp/dir/s.png')->assertOk();
    $this->get('/production/width=32,format=webp/dir/s.png')->assertStatus(429);
});

it('transforms a short animated gif (frame count via ping)', function () {
    putFixture('animated.gif', 'dir/a.gif'); // 3 frames

    $this->get('/production/width=32,format=gif/dir/a.gif')->assertOk();
});

it('does not redirect a single-frame gif even at cap 1', function () {
    // A static GIF has 1 frame; the old GCE byte-scan miscounted these.
    config()->set('image-transform-url.max_animated_frames', 1);
    putFixture('static.gif', 'dir/s.gif');

    $this->get('/production/width=16,format=gif/dir/s.gif')->assertOk();
});

it('redirects an animated gif over the frame cap', function () {
    config()->set('image-transform-url.max_animated_frames', 2);
    putFixture('animated.gif', 'dir/a.gif'); // 3 frames

    $this->get('/production/width=32,format=gif/dir/a.gif')->assertRedirect();
});

it('redirects to the original when the source cannot be decoded', function () {
    $header = substr((string) file_get_contents(base_path('tests/fixtures/animated-tiny.webp')), 0, 16);
    Storage::disk('s3')->put('dir/broken.webp', $header.str_repeat("\x00", 256));

    $res = $this->get('/production/width=64,format=webp/dir/broken.webp');

    $res->assertRedirect();
    expect($res->headers->get('Location'))->toContain('broken.webp');
});

it('preserves a 404 for a missing object', function () {
    $this->get('/production/width=64,format=webp/dir/missing.webp')->assertNotFound();
});

it('serves a cached transform even after the frame cap is tightened', function () {
    // Seeds the vendor cache directly, then tightens the cap: isAlreadyCached()
    // must skip the guard so the cached bytes are served (no redirect, no decode).
    Storage::fake('local'); // cache disk
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.max_animated_frames', 2); // would redirect the 3-frame file

    $parsed = ['width' => 64, 'format' => 'webp'];
    $endPath = '_cache/image-transform-url/production/'.md5(json_encode($parsed)).'_dir/a.webp';
    Storage::disk('local')->put($endPath, 'SEEDED-CACHE-BODY');
    Cache::put('image-transform-url:'.Storage::disk('local')->path($endPath), true, 3600);
    putFixture('animated-tiny.webp', 'dir/a.webp');

    $res = $this->get('/production/width=64,format=webp/dir/a.webp');

    $res->assertOk();
    expect($res->headers->get('X-Cache'))->toBe('HIT');
    expect($res->getContent())->toBe('SEEDED-CACHE-BODY');
});
