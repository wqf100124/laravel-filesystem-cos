# Laravel Filesystem COS

腾讯云对象存储（COS）Laravel 文件系统驱动

## 介绍

Laravel Filesystem COS 是一个为 Laravel 应用程序提供的腾讯云对象存储（Cloud Object Storage，COS）驱动。它允许你在 Laravel
应用中使用腾讯云 COS 作为文件存储后端，就像使用本地文件系统一样简单。

## 安装

```bash
composer require i94m/laravel-filesystem-cos
```

## 配置

### 1. 发布配置文件（可选）

```bash
php artisan vendor:publish --provider="I94m\LaravelFilesystemCos\CosServiceProvider"
```

### 2. 配置环境变量

在 `.env` 文件中添加以下配置：

```env
# 腾讯云对象存储
COS_REGION=ap-guangzhou
COS_SECRET_ID=
COS_SECRET_KEY=
COS_BUCKET=
COS_PREFIX=
COS_URL=
```

### 3. 配置文件系统

在 [config/filesystems.php](file:///Users/wade/web/app/template/laravel-full-template/config/filesystems.php) 中添加 COS
磁盘配置：

```php
'disks' => [
    // ... 其他磁盘配置
    'cos' => [
        'driver' => 'cos',
        'key' => env('COS_KEY'),
        'secret' => env('COS_SECRET'),
        'region' => env('COS_REGION'),
        'bucket' => env('COS_BUCKET'),
        'url' => env('COS_URL'),
        'prefix' => env('COS_PREFIX', ''),
    ],
],
```

## 使用方法

### 基本文件操作

```php
use Illuminate\Support\Facades\Storage;

// 检查文件是否存在
Storage::disk('cos')->exists('file.txt');
// 删除文件
Storage::disk('cos')->delete('file.txt');
// 写入文件
Storage::disk('cos')->put('file.txt', 'Hello World');
// 读取文件
Storage::disk('cos')->get('file.txt');
// 更新文件
Storage::disk('cos')->put('file.txt', 'Updated Content');
// 追加文件
Storage::disk('cos')->append('file.txt', 'Appended Content');
// 获取文件列表
Storage::disk('cos')->files();
// 获取文件信息
Storage::disk('cos')->getMetadata('file.txt');
// 获取文件大小
Storage::disk('cos')->size('file.txt');
// 获取文件MIME类型
Storage::disk('cos')->mimeType('file.txt');
// 获取文件最后修改时间
Storage::disk('cos')->lastModified('file.txt');
```

### 获取临时上传密钥

```php
Storage::disk('cos')->getAdapter()->sts([
    // 前缀
    'prefix' => 'static/images/order/',
    // 有效期(秒)
    'expire' => 3600,
    // 最大文件大小(bytes)
    'max_file_size' => 200,
    // 上传成功后返回状态码
    'success_action_status' => 201,
]);
```

返回结果：

```json
{
  "url": "https://test-1356740456.cos.ap-nanjing.myqcloud.com",
  "payload": {
    "q-ak": "AKID**********",
    "q-sign-algorithm": "sha1",
    "q-key-time": "1754369419;1754373019",
    "q-signature": "902e**********",
    "policy": "eyJl**********",
    "success_action_status": 201
  }
}
```

参考官方文档：https://laravel.com/docs/12.x/filesystem#custom-filesystems