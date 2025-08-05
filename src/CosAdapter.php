<?php

namespace I94m\LaravelFilesystemCos;

use Carbon\Carbon;
use Exception;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Qcloud\Cos\Client;

class CosAdapter implements FilesystemAdapter
{
    protected Client $client;
    protected string $bucket;
    protected string $prefix;
    protected array  $config;

    public function __construct(Client $client, string $bucket, string $prefix = '')
    {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->prefix = trim($prefix, '/');
    }

    protected function applyPathPrefix(string $path): string
    {
        return $this->prefix !== ''
            ? $this->prefix . '/' . ltrim($path, '/')
            : $path;
    }

    /**
     * 实现 directoryExists 方法 (Flysystem 3.x 必需)
     */
    public function directoryExists(string $path): bool
    {
        $normalizedPath = rtrim($this->applyPathPrefix($path), '/') . '/';

        try {
            // COS 通过列出对象检查目录是否存在
            $result = $this->client->listObjects([
                'Bucket'    => $this->bucket,
                'Prefix'    => $normalizedPath,
                'Delimiter' => '/',
                'MaxKeys'   => 1
            ]);

            // 如果找到公共前缀或内容，则目录存在
            return !empty($result['CommonPrefixes']) || !empty($result['Contents']);
        } catch (Exception $e) {
            return false;
        }
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->applyPathPrefix($path)
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->client->putObject([
                'Bucket'      => $this->bucket,
                'Key'         => $this->applyPathPrefix($path),
                'Body'        => $contents,
                'ContentType' => $config->get('mimetype', 'application/octet-stream')
            ]);
        } catch (Exception $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage());
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->applyPathPrefix($path)
            ]);
            return (string)$result['Body'];
        } catch (Exception $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
    }

    public function readStream(string $path)
    {
        $temp = fopen('php://temp', 'w+');
        fwrite($temp, $this->read($path));
        rewind($temp);
        return $temp;
    }

    public function delete(string $path): void
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->applyPathPrefix($path)
            ]);
        } catch (Exception $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage());
        }
    }

    public function deleteDirectory(string $path): void
    {
        $path = rtrim($this->applyPathPrefix($path), '/') . '/';

        $objects = $this->listContents($path, true);
        foreach ($objects as $object) {
            if ($object instanceof FileAttributes) {
                $this->delete($object->path());
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key'    => rtrim($this->applyPathPrefix($path), '/') . '/',
            'Body'   => ''
        ]);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->client->putObjectAcl([
            'Bucket' => $this->bucket,
            'Key'    => $this->applyPathPrefix($path),
            'ACL'    => $visibility === 'public' ? 'public-read' : 'private'
        ]);
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            $result = $this->client->getObjectAcl([
                'Bucket' => $this->bucket,
                'Key'    => $this->applyPathPrefix($path)
            ]);

            $visibility = 'private';
            foreach ($result['Grants'] as $grant) {
                if ($grant['Grantee']['URI'] === 'http://cam.qcloud.com/groups/global/AllUsers'
                    && $grant['Permission'] === 'READ') {
                    $visibility = 'public';
                    break;
                }
            }

            return new FileAttributes($path, null, $visibility);
        } catch (Exception $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage());
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->getMetadata($path, 'mimetype');
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path, 'lastModified');
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->getMetadata($path, 'fileSize');
    }

    protected function getMetadata(string $path, string $type): FileAttributes
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->applyPathPrefix($path)
            ]);

            $attributes = [
                'fileSize'     => $result['ContentLength'],
                'lastModified' => strtotime($result['LastModified']),
                'mimetype'     => $result['ContentType']
            ];

            return new FileAttributes(
                $path,
                $attributes['fileSize'],
                null,
                $attributes['lastModified'],
                $attributes['mimetype']
            );
        } catch (Exception $e) {
            throw UnableToRetrieveMetadata::create($path, $type, $e->getMessage());
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $path      = rtrim($this->applyPathPrefix($path), '/') . '/';
        $delimiter = $deep ? '' : '/';
        $marker    = '';

        do {
            $result = $this->client->listObjects([
                'Bucket'    => $this->bucket,
                'Prefix'    => $path,
                'Delimiter' => $delimiter,
                'Marker'    => $marker
            ]);

            // 处理文件
            foreach ($result['Contents'] ?? [] as $content) {
                $key = $content['Key'];
                if ($key === $path) continue; // 跳过目录标记本身

                yield new FileAttributes(
                    $this->removePathPrefix($key),
                    $content['Size'],
                    null,
                    strtotime($content['LastModified']),
                    $content['ContentType']
                );
            }

            // 处理目录
            foreach ($result['CommonPrefixes'] ?? [] as $prefix) {
                yield new DirectoryAttributes(
                    $this->removePathPrefix(rtrim($prefix['Prefix'], '/'))
                );
            }

            $marker = $result['NextMarker'] ?? null;
        } while ($marker);
    }

    protected function removePathPrefix(string $path): string
    {
        return $this->prefix !== ''
            ? substr($path, strlen($this->prefix) + 1)
            : $path;
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copyObject([
                'Bucket'     => $this->bucket,
                'Key'        => $this->applyPathPrefix($destination),
                'CopySource' => "{$this->bucket}/{$this->applyPathPrefix($source)}"
            ]);
        } catch (Exception $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e->getMessage());
        }
    }

    public function getUrl(string $path, array $options = []): string
    {
        $key = ltrim($this->applyPathPrefix($path), '/');

        // 自定义域名处理
        if (!empty($this->config['url'])) {
            $baseUrl = rtrim($this->config['url'], '/');
            return "{$baseUrl}/{$key}";
        }

        // 默认COS域名处理
        $region = $this->config['region'] ?? 'ap-guangzhou';
        $bucket = $this->config['bucket'];
        $domain = "{$bucket}.cos.{$region}.myqcloud.com";

        return $this->generateUrl($domain, $key, $options);
    }

    protected function generateUrl(string $domain, string $key, array $options): string
    {
        $signed = $options['signed'] ?? false;

        if ($signed) {
            return $this->getSignedUrl($key, $options);
        }

        return "https://{$domain}/{$key}";
    }

    /**
     * 生成预签名URL
     */
    protected function getSignedUrl(string $key, array $options): string
    {
        $expires         = $options['expires'] ?? '+30 minutes';
        $responseHeaders = $options['headers'] ?? [];

        $command = $this->client->getCommand('GetObject', [
            'Bucket'          => $this->bucket,
            'Key'             => $key,
            'ResponseHeaders' => $responseHeaders
        ]);

        $request = $this->client->createPresignedRequest(
            $command,
            $expires
        );

        return (string)$request->getUri();
    }

    /**
     * 获取临时密钥
     *
     * @param array $options
     * @return array
     */
    public function sts(array $options = []): array
    {
        $maxFileSize   = (int)($options['max_file_size'] ?? 1024 * 1024 * 1024);
        $expireSeconds = (int)($options['expire'] ?? 30 * 60);
        $prefix        = $options['prefix'] ?? '';
        if ($this->config['prefix']) {
            $prefix = $this->config['prefix'] . '/' . $prefix;
        }

        $keyTime = time() . ';' . time() + $expireSeconds;

        $policy = json_encode([
            'expiration' => Carbon::now()->addSeconds($expireSeconds)->format('Y-m-d\TH:i:s.000\Z'),
            'conditions' => [
                ["bucket" => $this->config['bucket']],
                ["starts-with", '$key', $prefix],
                ["content-length-range", 0, $maxFileSize],
                ["q-sign-algorithm" => 'sha1'],
                ["q-ak" => $this->config['secret_id']],
                ["q-sign-time" => $keyTime]
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // ["starts-with", '$Content-Type', 'image/*'],

        $signKey      = hash_hmac('sha1', $keyTime, $this->config['secret_key']);
        $stringToSign = sha1($policy);
        $signature    = hash_hmac('sha1', $stringToSign, $signKey);

        return [
            'url'     => $this->config['url'],
            'payload' => [
                'q-ak'                  => $this->config['secret_id'],
                'q-sign-algorithm'      => 'sha1',
                'q-key-time'            => $keyTime,
                'q-signature'           => $signature,
                'policy'                => base64_encode($policy),
                'success_action_status' => $options['success_action_status'] ?? 201,
            ]
        ];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }
}