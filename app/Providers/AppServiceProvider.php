<?php

namespace App\Providers;

use AceOfAces\LaravelImageTransformUrl\Actions\TransformImageAction as BaseTransformImageAction;
use App\Actions\TransformImageAction;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve the vendor transform action to our failure-aware subclass.
        // Controllers method-inject the vendor FQCN, so this makes them use ours.
        $this->app->bind(BaseTransformImageAction::class, TransformImageAction::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
