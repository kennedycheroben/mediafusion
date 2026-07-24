<?php
declare(strict_types=1);

namespace MediaFusion\Storage;

/**
 * ObjectStorageAdapter — S3-compatible object storage implementation.
 *
 * Supports AWS S3, Cloudflare R2, MinIO, and any S3-compatible provider.
 * Implements the full StorageInterface including presigned URLs for direct browser uploads.
 *
 * Security:
 * - Credentials loaded from environment variables, never hardcoded
 * - Presigned URLs are short-lived and scoped to specific objects
 * - No permanent credentials exposed to the browser
 */
class ObjectStorageAdapter implements StorageInterface
{
    private string $bucket;
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private string $endpoint;
    private string $publicUrlTemplate;
    private string $cdnBaseUrl;
    private bool $pathStyle;

    public function __construct()
    {
        $this->bucket = (string)(getenv('S3_BUCKET') ?: '');
        $this->region = (string)(getenv('S3_REGION') ?: 'us-east-1');
        $this->accessKey = (string)(getenv('S3_ACCESS_KEY') ?: '');
        $this->secretKey = (string)(getenv('S3_SECRET_KEY') ?: '');
        $this->endpoint = (string)(getenv('S3_ENDPOINT') ?: 'https://s3.amazonaws.com');
        $this->publicUrlTemplate = (string)(getenv('S3_PUBLIC_URL_TEMPLATE') ?: '');
        $this->cdnBaseUrl = (string)(getenv('CDN_BASE_URL') ?: '');
        $this->pathStyle = (getenv('S3_PATH_STYLE') ?: '') === 'true'
            || strpos($this->endpoint, 'amazonaws.com') === false;
    }

    public function isAvailable(): bool
    {
        $enabled = getenv('S3_ENABLED');
        return ($enabled === '1' || $enabled === 'true')
            && !empty($this->bucket)
            && !empty($this->accessKey)
            && !empty($this->secretKey);
    }

    public function getProviderName(): string
    {
        if (strpos($this->endpoint, 'amazonaws.com') !== false) return 's3';
        if (strpos($this->endpoint, 'cloudflare') !== false || strpos($this->endpoint, 'r2.cloudflarestorage') !== false) return 'r2';
        if (strpos($this->endpoint, 'minio') !== false) return 'minio';
        return 's3';
    }

    public function putFile(string $localPath, string $key, string $contentType = 'application/octet-stream'): ?array
    {
        if (!file_exists($localPath)) {
            error_log("ObjectStorage: Source file not found: {$localPath}");
            return null;
        }

        $fileData = @file_get_contents($localPath);
        if ($fileData === false) {
            error_log("ObjectStorage: Failed to read file: {$localPath}");
            return null;
        }

        $result = $this->putObject($key, $fileData, $contentType);
        if ($result === null) return null;

        return [
            'key' => $key,
            'url' => $this->getUrl($key),
        ];
    }

    public function putData(string $data, string $key, string $contentType = 'application/octet-stream'): ?array
    {
        $result = $this->putObject($key, $data, $contentType);
        if ($result === null) return null;

        return [
            'key' => $key,
            'url' => $this->getUrl($key),
        ];
    }

    public function get(string $key): string|false
    {
        $url = $this->buildSignedUrl('GET', $key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($data !== false && $httpCode >= 200 && $httpCode < 300) ? $data : false;
    }

    public function exists(string $key): bool
    {
        $url = $this->buildSignedUrl('HEAD', $key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    public function delete(string $key): bool
    {
        $url = $this->buildSignedUrl('DELETE', $key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    public function copy(string $sourceKey, string $destKey): bool
    {
        $url = $this->buildSignedUrl('PUT', $destKey);
        $data = $this->get($sourceKey);
        if ($data === false) return false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $payloadHash = hash('sha256', $data);
        $headers = $this->signRequest('PUT', $destKey, $payloadHash);
        $httpHeaders = [];
        foreach ($headers as $k => $v) {
            $httpHeaders[] = ucfirst($k) . ": {$v}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    public function getMetadata(string $key): ?array
    {
        $url = $this->buildSignedUrl('HEAD', $key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) return null;

        $headerStr = substr($response, 0, $headerSize);
        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        return [
            'size' => (int)($headers['content-length'] ?? 0),
            'content_type' => $headers['content-type'] ?? 'application/octet-stream',
            'last_modified' => $headers['last-modified'] ?? date('c'),
        ];
    }

    public function getUrl(string $key, int $expiresInSeconds = 3600): string
    {
        // CDN takes priority if configured
        if (!empty($this->cdnBaseUrl)) {
            return rtrim($this->cdnBaseUrl, '/') . '/' . ltrim($key, '/');
        }

        // Public URL template
        if (!empty($this->publicUrlTemplate)) {
            return str_replace(
                ['{bucket}', '{region}', '{key}'],
                [$this->bucket, $this->region, $key],
                $this->publicUrlTemplate
            );
        }

        // Default: signed URL
        return $this->buildSignedUrl('GET', $key, $expiresInSeconds);
    }

    public function createUploadAuthorization(string $key, string $contentType, int $maxSize, int $expiresInSeconds = 900): ?array
    {
        $policy = [
            'expiration' => gmdate('Y-m-d\TH:i:s\Z', time() + $expiresInSeconds),
            'conditions' => [
                ['bucket' => $this->bucket],
                ['key' => $key],
                ['Content-Type' => $contentType],
                ['content-length-range', 1, $maxSize],
            ],
        ];

        $policyBase64 = base64_encode(json_encode($policy, JSON_UNESCAPED_SLASHES));
        $signature = base64_encode(
            hash_hmac('sha256', $policyBase64, $this->secretKey, true)
        );

        $host = $this->getHost();
        $url = $this->getScheme() . '://' . $host;
        if ($this->pathStyle) {
            $url .= '/' . $this->bucket;
        }
        $url .= '/';

        return [
            'url' => $url,
            'fields' => [
                'key' => $key,
                'Content-Type' => $contentType,
                'AWSAccessKeyId' => $this->accessKey,
                'policy' => $policyBase64,
                'signature' => $signature,
            ],
        ];
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function putObject(string $key, string $data, string $contentType): bool
    {
        $url = $this->buildSignedUrl('PUT', $key);
        $payloadHash = hash('sha256', $data);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $headers = $this->signRequest('PUT', $key, $payloadHash, $contentType);
        $httpHeaders = [];
        foreach ($headers as $k => $v) {
            $httpHeaders[] = ucfirst($k) . ": {$v}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("ObjectStorage PUT failed: {$httpCode} for key={$key}");
            return false;
        }
        return true;
    }

    private function buildSignedUrl(string $method, string $key, int $expiresInSeconds = 3600): string
    {
        $host = $this->getHost();
        $scheme = $this->getScheme();

        if ($this->pathStyle) {
            $requestUri = '/' . $this->bucket . '/' . ltrim($key, '/');
        } else {
            $requestUri = '/' . ltrim($key, '/');
        }

        $now = time();
        $dateStamp = gmdate('Ymd', $now);
        $amzDate = gmdate('Ymd\THis\Z', $now);

        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";

        // Query parameters for presigned URL
        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($queryParams);

        $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = 'host';
        $payloadHash = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = "{$method}\n{$requestUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $queryParams['X-Amz-Signature'] = $signature;

        return "{$scheme}://{$host}{$requestUri}?" . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
    }

    private function signRequest(string $method, string $key, string $payloadHash, string $contentType = ''): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $host = $this->getHost();

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
            $signedHeaders .= (empty($signedHeaders) ? '' : ';') . $k;
        }

        $requestUri = $this->pathStyle
            ? '/' . $this->bucket . '/' . ltrim($key, '/')
            : '/' . ltrim($key, '/');

        $canonicalRequest = "{$method}\n{$requestUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $headers;
    }

    private function getHost(): string
    {
        $parts = parse_url($this->endpoint);
        $host = $parts['host'] ?? 's3.amazonaws.com';
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        if (!$this->pathStyle && strpos($host, 'amazonaws.com') !== false && strpos($host, $this->bucket) === false) {
            return $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
        }

        return $host;
    }

    private function getScheme(): string
    {
        $parts = parse_url($this->endpoint);
        return $parts['scheme'] ?? 'https';
    }
}
