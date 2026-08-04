<?php

declare(strict_types=1);

namespace App\Actions;

use AceOfAces\LaravelImageTransformUrl\Actions\TransformImageAction as BaseTransformImageAction;
use AceOfAces\LaravelImageTransformUrl\ValueObjects\ImageResult;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Thin override of the vendor transform action.
 *
 * The app uses the Imagick driver (config/image.php), which decodes animated
 * WebP that GD cannot — fixing the reported 500. But a genuinely corrupt or
 * undecodable source (or an encoder error) can still throw. Rather than let that
 * surface as an HTTP 500, redirect the client to the untouched original so it
 * still gets an image. Bound over the vendor FQCN in AppServiceProvider.
 */
class TransformImageAction extends BaseTransformImageAction
{
    public function handle(?string $ip, ?string $pathPrefix, string $options, ?string $path = null): ImageResult
    {
        try {
            return parent::handle($ip, $pathPrefix, $options, $path);
        } catch (HttpExceptionInterface $e) {
            throw $e; // preserve HTTP control-flow (404 not-found, 429 rate-limit, etc.)
        } catch (Throwable $e) {
            report($e);
            $this->redirectToOriginal($pathPrefix, $path); // throws HttpResponseException
        }
    }

    /**
     * Safety net: redirect to the original's storage/CDN URL so the client still
     * gets an image (and the app never streams a broken/huge file through PHP).
     * Throws, so the caller never falls through.
     */
    protected function redirectToOriginal(?string $pathPrefix, ?string $path): never
    {
        $source = $this->handlePath($pathPrefix, $path); // re-resolves + re-validates (404 stays 404)

        $url = $source->type === 'disk'
            ? Storage::disk((string) $source->disk)->url($source->path)
            : Storage::url($source->path);

        throw new HttpResponseException(redirect()->away($url));
    }
}
