<?php

declare(strict_types=1);

use Illuminate\Http\Testing\File as TestingFile;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for animated-WebP support.
 *
 * The app uses the Imagick driver (config/image.php). Unlike GD, Imagick decodes
 * animated WebP, so these transform URLs return 200 (not the previous 500) and
 * the animation is preserved through resize + re-encode.
 */
beforeEach(function () {
    Storage::fake('s3');
    // Keep tests hermetic: skip writing to the real local cache disk.
    config()->set('image-transform-url.cache.enabled', false);
});

it('serves an animated webp transform without a 500', function () {
    Storage::disk('s3')->putFileAs(
        'dir',
        new TestingFile('a.webp', fopen(base_path('tests/fixtures/animated-tiny.webp'), 'r')),
        'a.webp',
    );

    $res = $this->get('/production/width=64,quality=70,format=webp/dir/a.webp');

    $res->assertOk(); // previously 500 under the GD driver
    expect($res->headers->get('Content-Type'))->toBe('image/webp');
    // Imagick preserves animation: encoded output keeps multiple frames.
    expect(substr_count($res->getContent(), 'ANMF'))->toBeGreaterThan(1);
});

it('still transforms static images', function () {
    Storage::disk('s3')->putFileAs(
        'dir',
        new TestingFile('s.png', fopen(base_path('tests/fixtures/static.png'), 'r')),
        's.png',
    );

    $res = $this->get('/production/width=32,format=webp/dir/s.png');

    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('image/webp');
});

it('does not upscale beyond the source size', function () {
    // static.png is 32x32; request a larger width -> output must stay 32 wide.
    Storage::disk('s3')->putFileAs(
        'dir',
        new TestingFile('small.png', fopen(base_path('tests/fixtures/static.png'), 'r')),
        'small.png',
    );

    $res = $this->get('/production/width=512,format=webp/dir/small.png');

    $res->assertOk();
    $size = getimagesizefromstring($res->getContent());
    expect($size[0])->toBe(32); // clamped to native width, not enlarged to 512
});

it('redirects to the original when the animation exceeds the frame limit', function () {
    config()->set('image-transform-url.max_animated_frames', 2); // fixture has 3 frames
    Storage::disk('s3')->putFileAs(
        'dir',
        new TestingFile('many.webp', fopen(base_path('tests/fixtures/animated-tiny.webp'), 'r')),
        'many.webp',
    );

    $res = $this->get('/production/width=64,format=webp/dir/many.webp');

    $res->assertRedirect(); // over the cap -> redirect to original, not a heavy decode
    expect($res->headers->get('Location'))->toContain('many.webp');
});

it('redirects to the original when the source cannot be decoded', function () {
    // Valid WebP header so the mime gate passes, but a garbage payload that even
    // Imagick cannot decode -> the failure must not become a 500.
    $header = substr((string) file_get_contents(base_path('tests/fixtures/animated-tiny.webp')), 0, 16);
    Storage::disk('s3')->put('dir/broken.webp', $header.str_repeat("\x00", 256));

    $res = $this->get('/production/width=64,format=webp/dir/broken.webp');

    $res->assertRedirect(); // 302 to the original, not a 500
    expect($res->headers->get('Location'))->toContain('broken.webp');
});
