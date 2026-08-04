<?php

declare(strict_types=1);

use Illuminate\Http\Testing\File as TestingFile;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for the animated-WebP 500 fix.
 *
 * The vendor action reads images with the GD driver, which cannot decode
 * animated WebP and throws a DecoderException. The app subclass catches that,
 * retries via Imagick, and falls back to the original bytes — so these URLs
 * must return 200, never 500.
 */
beforeEach(function () {
    Storage::fake('s3');
    // Keep tests hermetic: skip writing to the real local cache disk.
    // HIT/MISS behaviour is covered by the Cloud verification checklist.
    config()->set('image-transform-url.cache.enabled', false);
});

it('serves an animated webp transform without a 500', function () {
    Storage::disk('s3')->putFileAs(
        'dir',
        new TestingFile('a.webp', fopen(base_path('tests/fixtures/animated-tiny.webp'), 'r')),
        'a.webp',
    );

    $res = $this->get('/production/width=64,quality=70,format=webp/dir/a.webp');

    $res->assertOk(); // previously 500
    expect($res->headers->get('Content-Type'))->toBe('image/webp');
    expect(strlen($res->getContent()))->toBeGreaterThan(0);
    // Imagick path preserves animation: encoded output keeps multiple frames.
    expect(substr_count($res->getContent(), 'ANMF'))->toBeGreaterThan(1);
});

it('falls back to original bytes when the input is undecodable', function () {
    // Valid WebP header so the mime gate passes, but a garbage payload that
    // neither GD nor Imagick can decode -> the guaranteed serve-original path.
    $header = substr((string) file_get_contents(base_path('tests/fixtures/animated-tiny.webp')), 0, 16);
    Storage::disk('s3')->put('dir/broken.webp', $header.str_repeat("\x00", 256));

    $res = $this->get('/production/width=64,format=webp/dir/broken.webp');

    $res->assertOk(); // safety net engaged, not a 500
    expect($res->getContent())->not->toBeEmpty();
});

it('preserves a 404 for a missing file instead of falling back', function () {
    $res = $this->get('/production/width=64,format=webp/dir/does-not-exist.webp');

    $res->assertNotFound(); // HTTP control-flow must not be swallowed by the fallback
});

it('still transforms static images via the default driver', function () {
    Storage::disk('s3')->putFileAs(
        'dir',
        new TestingFile('s.png', fopen(base_path('tests/fixtures/static.png'), 'r')),
        's.png',
    );

    $res = $this->get('/production/width=32,format=webp/dir/s.png');

    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('image/webp');
});

it('serves the original when the animated source exceeds the size guard', function () {
    // Force the OOM size guard to trip on the tiny 3-frame fixture (1-byte cap).
    config()->set('image-transform-url.cache.enabled', false);
    putenv('IMAGE_TRANSFORM_MAX_ANIMATED_BYTES=1');
    $_ENV['IMAGE_TRANSFORM_MAX_ANIMATED_BYTES'] = '1';

    Storage::disk('s3')->putFileAs(
        'dir',
        new TestingFile('big.webp', fopen(base_path('tests/fixtures/animated-tiny.webp'), 'r')),
        'big.webp',
    );

    $res = $this->get('/production/width=64,format=webp/dir/big.webp');

    $res->assertOk();
    // Guard serves the untouched original -> mime is the source's image/webp,
    // and the byte length matches the original fixture, not a re-encode.
    expect($res->getContent())->toBe((string) file_get_contents(base_path('tests/fixtures/animated-tiny.webp')));

    putenv('IMAGE_TRANSFORM_MAX_ANIMATED_BYTES');
    unset($_ENV['IMAGE_TRANSFORM_MAX_ANIMATED_BYTES']);
});
