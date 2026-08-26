# How to setup

You just need to copy .env.example to .env and set S3 storage credentials and you are good to go

# Usage

Access optimized image with

`http://image-hosting.test/images/width=250,quality=80,format=webp/WDrBoAL1mSWqcIyJJlJ3rYEj/sky.jpg`

# Cache eviction

Transformed images are cached on the disk set by `IMAGE_TRANSFORM_CACHE_DISK` (default `local`).

- **Local disk:** bounded automatically to `IMAGE_TRANSFORM_CACHE_MAX_SIZE_MB` (default 100 MB).
- **Object store (S3 / R2, e.g. `s3-cache`):** the in-app size sweep is skipped (it costs O(N) billed LIST+HEAD calls per write and pegs CPU). You **must** add a bucket **lifecycle rule** to expire objects under the prefix below — it is the only thing bounding the cache bucket.

  ```
  _cache/image-transform-url/
  ```

  Do **not** append `*`. S3 and R2 lifecycle filters match a *literal* key prefix; they do not support wildcards. A prefix of `_cache/image-transform-url/*` is taken literally, matches no cache object, and the rule silently expires nothing — leaving the bucket unbounded. To target the whole bucket instead, use an empty prefix, not `*`.

# Allowed sizes + formats

Without a whitelist, `width=`/`height=` accept any positive integer, so a client can
enumerate `width=101`, `width=102`, `width=103`... — each one a distinct cache entry
(and, on the async path, a distinct queued job). `config/image-transform-url.php` →
`sizes` bounds this: a requested width/height is rounded to the **nearest** value in
the list (not rejected), so e.g. `width=203` and `width=210` both resolve to the same
200px cache/dedup entry. Default: `[200, 400, 600, 800, 1000, 1280, 1440, 1920]`. Set
to `[]` to disable.

`qualities` applies the same nearest-value clamp to `quality=` (default:
`[60, 75, 90, 100]`) — otherwise `quality=1..100` is a 100-value enumeration space
per size/format combo that the size whitelist alone doesn't bound.

`allowed_formats` restricts the `format=` option — an explicit format not in the list
404s (default: `['webp']` only). Omitting `format=` entirely is unaffected by this
whitelist; the vendor still defaults to the source's own mime type in that case.

# Async processing

On a cache **miss** the server does not transform inline. It **302s to the original**
(short-lived, `Cache-Control: public, max-age=60`) so the client gets an image
immediately, and queues the transform in the background. The next request for the
same URL hits the now-populated cache and gets the optimized image (200 `X-Cache: HIT`).

- **Required:** a running worker — `php artisan queue:work redis --queue=default`
  (use Supervisor/Horizon in production). Without a worker, misses keep redirecting
  to the original and never optimize.
- `QUEUE_CONNECTION=redis` (phpredis extension required). Ensure a `failed_jobs` table
  exists (`php artisan queue:failed-table && php artisan migrate`), **or** set
  `QUEUE_FAILED_DRIVER=null` — the app's own failure sentinel does not need it.
- Recommended: `CACHE_STORE=redis` so the dispatch dedup lock (`ShouldBeUnique`) is
  atomic and fast.
- On a job failure (undecodable source, encoder error), a permanent sentinel is
  cached and the request path serves the long-cache (30-day) redirect instead of
  re-dispatching — matching the synchronous decode-failure behavior.
- Tuning knobs (see `config/image-transform-url.php` → `async`):
  `IMAGE_TRANSFORM_ASYNC_ENABLED` (kill-switch → synchronous transform),
  `IMAGE_TRANSFORM_PENDING_MAX_AGE` (temporary redirect TTL),
  `IMAGE_TRANSFORM_JOB_UNIQUE_FOR` (dispatch dedup window),
  `IMAGE_TRANSFORM_FAILED_LIFETIME` (failure sentinel TTL).

# More documentation
Please read [here](https://image-transform-url.julian.center/installation)
