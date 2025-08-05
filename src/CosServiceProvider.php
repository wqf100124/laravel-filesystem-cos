<?php

namespace I94m\LaravelFilesystemCos;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Qcloud\Cos\Client;

class CosServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Storage::extend('cos', function (Application $app, array $config) {
            $adapter = new CosAdapter(new Client([
                'region' => $config['region'],
                'credentials' => [
                    'secretId' => $config['secret_id'],
                    'secretKey' => $config['secret_key'],
                ]
            ]), $config['bucket'], $config['prefix'] ?? '');

            // 将配置传递给适配器
            $adapter->setConfig($config);

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }
}