<?php

namespace I94m\LaravelFilesystemCos\Facades;

use Illuminate\Support\Facades\Facade;

class CosStorage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cos';
    }
}