<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Source Directories
    |--------------------------------------------------------------------------
    |
    | Here you may configure the directories from which the image transformer
    | is allowed to serve images. For security reasons, it is recommended
    | to only allow directories which are already publicly accessible.
    |
    | Important: The public storage directory should be addressed directly via
    | storage('app/public') instead of the public_path('storage') link.
    |
    | You can also use any Laravel Filesystem disk (e.g. S3) by providing an
    | array configuration with 'disk' and an optional 'prefix'.
    |
    */

    'source_directories' => [
        'production' => [
            'disk' => 's3', // Any valid Laravel Filesystem disk
        ],
        'staging' => [
            'disk' => 's3-staging',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Source Directory
    |--------------------------------------------------------------------------
    |
    | Below you may configure the default source directory which is used when
    | no specific path prefix is provided in the URL. This should be one of
    | the keys from the source_directories array.
    |
    */

    'default_source_directory' => env('IMAGE_TRANSFORM_DEFAULT_SOURCE_DIRECTORY', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | Here you may configure the route prefix of the image transformer.
    |
    */

    'route_prefix' => env('IMAGE_TRANSFORM_ROUTE_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Enabled Options
    |--------------------------------------------------------------------------
    |
    | Here you may configure the options which are enabled for the image
    | transformer.
    |
    | NOTE: requested width/height are NOT clamped to the source size — the
    | vendor calls scale(), which enlarges, so a request larger than the source
    | is upscaled. This is intentional: a clamp was added in a552c90 and
    | deliberately reverted in 4341296.
    |
    */

    'enabled_options' => env('IMAGE_TRANSFORM_ENABLED_OPTIONS', [
        'width',
        'height',
        'format',
        'quality',
        // 'flip',
        // 'contrast',
        // 'version',
        // 'background',
        // 'blur'
    ]),

    /*
    |--------------------------------------------------------------------------
    | Image Cache
    |--------------------------------------------------------------------------
    |
    | Here you may configure the image cache settings. The cache is used to
    | store the transformed images for a certain amount of time. This is
    | useful to prevent reprocessing the same image multiple times.
    | The cache is stored in the configured cache disk.
    |
    */

    'cache' => [
        'enabled' => env('IMAGE_TRANSFORM_CACHE_ENABLED', true),
        // TTL (seconds) of the "live" cache flag and the frame-count memo. NOTE:
        // env() returns numeric vars as strings; read via config()->integer(), which
        // strictly rejects strings — so cast here.
        'lifetime' => (int) env('IMAGE_TRANSFORM_CACHE_LIFETIME', 60 * 60 * 24 * 30), // 30 days
        'disk' => env('IMAGE_TRANSFORM_CACHE_DISK', 'local'),
        // Size management (max_size_mb / clear_to_percent) is enforced ONLY on a
        // local cache disk — see storeCachedImage(). On an object store (s3/R2) the
        // O(N) LIST+HEAD sweep is skipped (it pegs CPU); bound that bucket with a
        // lifecycle rule instead, where these two values do nothing. Cast to int so
        // an env-provided string can't trip the vendor's config()->integer().
        'max_size_mb' => (int) env('IMAGE_TRANSFORM_CACHE_MAX_SIZE_MB', 100), // 100 MB (local disk only)
        'clear_to_percent' => (int) env('IMAGE_TRANSFORM_CACHE_CLEAR_TO_PERCENT', 80), // clear to 80% of max
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limit
    |--------------------------------------------------------------------------
    |
    | Below you may configure the rate limit applied to each image transformation
    | by path and IP address. It is recommended to set this to a low value,
    | e.g. 2 requests per minute, to prevent abuse.
    |
    */

    'rate_limit' => [
        'enabled' => env('IMAGE_TRANSFORM_RATE_LIMIT_ENABLED', true),
        'disabled_for_environments' => env('IMAGE_TRANSFORM_RATE_LIMIT_DISABLED_FOR_ENVIRONMENTS', [
            'local',
            'testing',
        ]),
        'max_attempts' => (int) env('IMAGE_TRANSFORM_RATE_LIMIT_MAX_REQUESTS', 2),
        'decay_seconds' => (int) env('IMAGE_TRANSFORM_RATE_LIMIT_DECAY_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed URLs
    |--------------------------------------------------------------------------
    |
    | Below you may configure signed URLs, which can be used to protect image
    | transformations from unauthorized access. Signature verification is
    | only applied to images from the for_source_directories array.
    |
    */

    'signed_urls' => [
        'enabled' => env('IMAGE_TRANSFORM_SIGNED_URLS_ENABLED', false),
        'for_source_directories' => env('IMAGE_TRANSFORM_SIGNED_URLS_FOR_SOURCE_DIRECTORIES', [
            //
        ]),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Headers
    |--------------------------------------------------------------------------
    |
    | Below you may configure the response headers which are added to the
    | response. This is especially useful for controlling caching behavior
    | of CDNs.
    |
    */

    'headers' => [
        'Cache-Control' => env('IMAGE_TRANSFORM_HEADER_CACHE_CONTROL', 'immutable, public, max-age=2592000, s-maxage=2592000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Animated Frames (app extension)
    |--------------------------------------------------------------------------
    |
    | Animations with more frames than this are redirected to the original
    | instead of being transformed. Imagick coalesces every frame at full
    | canvas, so a long animation can consume GBs of RAM — an uncatchable OOM.
    | Rough cost at 1280px width: ~8.5 MB RAM and ~130 ms per frame (e.g. 30
    | frames ≈ 250 MB / 4 s; 100 frames ≈ 850 MB / 14 s; 209 frames ≈ 1.8 GB /
    | 27 s). Kept low so heavy animations (video-like clips) are served as-is
    | rather than transformed. Tune against the server's memory_limit and
    | request timeout. Set to 0 to disable the guard.
    |
    */

    'max_animated_frames' => (int) env('IMAGE_TRANSFORM_MAX_ANIMATED_FRAMES', 30),
];
