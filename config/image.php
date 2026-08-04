<?php

use Intervention\Image\Drivers\Imagick\Driver;

return [

    /*
    |--------------------------------------------------------------------------
    | Image Driver
    |--------------------------------------------------------------------------
    |
    | Intervention Image supports “GD Library” and “Imagick” to process images
    | internally. We use Imagick because — unlike GD — it can decode animated
    | WebP (and animated GIF), which the image-transform endpoint must serve.
    |
    | Included options:
    |   - \Intervention\Image\Drivers\Gd\Driver::class
    |   - \Intervention\Image\Drivers\Imagick\Driver::class
    |
    */

    'driver' => Driver::class,

    /*
    |--------------------------------------------------------------------------
    | Configuration Options
    |--------------------------------------------------------------------------
    |
    | - "autoOrientation" auto-rotates imported images per Exif data.
    | - "decodeAnimation" keeps animated images animated (required for WebP/GIF).
    | - "blendingColor" default blending color.
    | - "strip" removes meta data (exif) when encoding.
    */

    'options' => [
        'autoOrientation' => true,
        'decodeAnimation' => true,
        'blendingColor' => 'ffffff',
        'strip' => false,
    ],
];
