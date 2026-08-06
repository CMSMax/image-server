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

# More documentation
Please read [here](https://image-transform-url.julian.center/installation)
