<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

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

it('redirects to the original when the encoder throws a raw ImagickException', function () {
    // Intervention's Imagick encoders don't wrap ImagickException (only the decoders
    // do), so an encode-stage fault (e.g. CacheResourcesExhausted under a memory /
    // policy.xml limit) must still fall back to a redirect, not a 500. ImageManager
    // is final (not Mockery-mockable), so swap the facade with a duck-typed fake.
    putFixture('static.png', 'dir/enc.png');

    $fakeImage = new class
    {
        public function width(): int
        {
            return 32;
        }

        public function height(): int
        {
            return 32;
        }

        public function scale(...$a): self
        {
            return $this;
        }

        public function encode(...$a): mixed
        {
            throw new ImagickException('CacheResourcesExhausted');
        }
    };
    Image::swap(new class($fakeImage)
    {
        public function __construct(private object $img) {}

        public function read(...$a): object
        {
            return $this->img;
        }
    });

    $res = $this->get('/production/width=32,format=webp/dir/enc.png');

    $res->assertRedirect();
    expect($res->headers->get('Location'))->toContain('enc.png');
});

it('preserves a 404 for a missing object', function () {
    $this->get('/production/width=64,format=webp/dir/missing.webp')->assertNotFound();
});

it('stores the flag on a real transform so the next request is a cache HIT', function () {
    // End-to-end (no seeding): the transform stores the object AND writes the flag,
    // so the second request hits. Regression for the low-hit-rate bug — the flag is
    // written before (removed) size management, so nothing can suppress it.
    Storage::fake('s3-cache');
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.cache.disk', 's3-cache');
    putFixture('static.png', 'dir/store.png');

    $first = $this->get('/production/width=32,format=webp/dir/store.png');
    $first->assertOk();
    expect($first->headers->get('X-Cache'))->toBe('MISS');

    $second = $this->get('/production/width=32,format=webp/dir/store.png');
    $second->assertOk();
    expect($second->headers->get('X-Cache'))->toBe('HIT');
});

it('serves the cache on the default (no-prefix) route', function () {
    // The default route resolves under 'production'; the source is resolved first
    // so $pathPrefix is normalised before the cache read — otherwise the read key
    // ('//...') never matches the vendor's write key ('/production/...').
    Storage::fake('s3-cache');
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.cache.disk', 's3-cache');

    $parsed = ['width' => 64, 'format' => 'webp'];
    $endPath = '_cache/image-transform-url/production/'.md5(json_encode($parsed)).'_dir/a.webp';
    Storage::disk('s3-cache')->put($endPath, 'SEEDED-DEFAULT-ROUTE');
    Cache::put('image-transform-url:'.Storage::disk('s3-cache')->path($endPath), true, 3600);
    putFixture('animated-tiny.webp', 'dir/a.webp');

    $res = $this->get('/width=64,format=webp/dir/a.webp'); // no prefix

    $res->assertOk();
    expect($res->headers->get('X-Cache'))->toBe('HIT');
    expect($res->getContent())->toBe('SEEDED-DEFAULT-ROUTE');
});

it('evicts on a local cache disk so it stays bounded', function () {
    // A local disk has no bucket lifecycle rule, so the vendor size sweep must
    // still run — otherwise the shipped default configuration grows unbounded.
    Storage::fake('local');
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.cache.disk', 'local');
    config()->set('image-transform-url.cache.max_size_mb', 0); // hardest possible cap

    for ($i = 0; $i < 5; $i++) {
        putFixture('static.png', "dir/e{$i}.png");
        $this->get("/production/width=3{$i},format=webp/dir/e{$i}.png")->assertOk();
    }

    expect(Storage::disk('local')->allFiles('_cache/image-transform-url'))->toBeEmpty();
});

it('skips the O(N) sweep on an s3 cache disk', function () {
    // manageCacheSize() LISTs + HEADs the whole cache dir per write — billed
    // round-trips on an object store. Objects must survive even under a zero cap.
    Storage::fake('s3-cache');
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.cache.disk', 's3-cache');
    config()->set('image-transform-url.cache.max_size_mb', 0);

    Storage::disk('s3-cache')->put('_cache/image-transform-url/production/seed.webp', str_repeat('x', 4096));
    putFixture('static.png', 'dir/keep.png');

    $this->get('/production/width=32,format=webp/dir/keep.png')->assertOk();

    // Pre-existing object untouched + the new one written = no eviction ran.
    expect(Storage::disk('s3-cache')->allFiles('_cache/image-transform-url'))->toHaveCount(2);
});

it('runs the sweep for an unlisted driver so it fails safe', function () {
    // The gate is "not s3", not "is local": an unknown driver must get the
    // bounded sweep rather than unbounded growth with no lifecycle rule.
    Storage::fake('odd'); // usable local fake…
    config()->set('filesystems.disks.odd.driver', 'sftp'); // …but the config says sftp
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.cache.disk', 'odd');
    config()->set('image-transform-url.cache.max_size_mb', 0);

    putFixture('static.png', 'dir/odd.png');
    $this->get('/production/width=32,format=webp/dir/odd.png')->assertOk();

    expect(Storage::disk('odd')->allFiles('_cache/image-transform-url'))->toBeEmpty();
});

it('404s a deleted source even while a stale transform is cached', function () {
    // The source is resolved/validated before the cache read, so a takedown takes
    // effect immediately instead of being served for the full cache lifetime.
    Storage::fake('s3-cache');
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.cache.disk', 's3-cache');

    $parsed = ['width' => 64, 'format' => 'webp'];
    $endPath = '_cache/image-transform-url/production/'.md5(json_encode($parsed)).'_dir/gone.webp';
    Storage::disk('s3-cache')->put($endPath, 'STALE-BODY');
    Cache::put('image-transform-url:'.Storage::disk('s3-cache')->path($endPath), true, 3600);
    // The source object itself was never stored (deleted / unpublished).

    $this->get('/production/width=64,format=webp/dir/gone.webp')->assertNotFound();
});

it('rate-limits a corrupt source instead of re-downloading it forever', function () {
    // A corrupt source throws on the frame ping; it is treated as over-cap (the
    // sentinel is cached) so it flows into the rate-limited redirect rather than
    // bypassing the limiter and re-fetching the full object on every request.
    config()->set('image-transform-url.max_animated_frames', 2);
    config()->set('image-transform-url.rate_limit.enabled', true);
    config()->set('image-transform-url.rate_limit.disabled_for_environments', []);
    config()->set('image-transform-url.rate_limit.max_attempts', 1);
    $header = substr((string) file_get_contents(base_path('tests/fixtures/animated-tiny.webp')), 0, 16);
    Storage::disk('s3')->put('dir/corrupt.webp', $header.str_repeat("\x00", 256));

    $this->get('/production/width=64,format=webp/dir/corrupt.webp')->assertRedirect();
    $this->get('/production/width=64,format=webp/dir/corrupt.webp')->assertStatus(429);
});

it('serves a cached transform from a non-local (s3) cache disk', function () {
    // Reads the cache disk-natively so an S3 cache disk works (the vendor's
    // File:: read always misses on an S3 key). Seeds the cache on the configured
    // disk, then requests: the cached bytes are served (200 + X-Cache HIT) and
    // the guard is skipped even though the cap is below the file's frame count.
    Storage::fake('s3-cache');
    config()->set('image-transform-url.cache.enabled', true);
    config()->set('image-transform-url.cache.disk', 's3-cache');
    config()->set('image-transform-url.max_animated_frames', 2); // would redirect the 3-frame file

    $parsed = ['width' => 64, 'format' => 'webp'];
    $endPath = '_cache/image-transform-url/production/'.md5(json_encode($parsed)).'_dir/a.webp';
    Storage::disk('s3-cache')->put($endPath, 'SEEDED-CACHE-BODY');
    Cache::put('image-transform-url:'.Storage::disk('s3-cache')->path($endPath), true, 3600);
    putFixture('animated-tiny.webp', 'dir/a.webp');

    $res = $this->get('/production/width=64,format=webp/dir/a.webp');

    $res->assertOk();
    expect($res->headers->get('X-Cache'))->toBe('HIT');
    expect($res->getContent())->toBe('SEEDED-CACHE-BODY');
});
