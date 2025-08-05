<?php

namespace I94m\LaravelFilesystemCos;

use Illuminate\Support\ServiceProvider;

class CosStorageServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->register(CosServiceProvider::class);
    }
}