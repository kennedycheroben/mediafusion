<?php
/**
 * MediaFusion - Lightweight Object Storage Client
 * 
 * Implements a dependency-free AWS Signature Version 4 signer for S3/R2/MinIO REST API.
 * This ensures compatibility with AWS S3, Cloudflare R2, and local MinIO setups
 * without requiring the heavy AWS SDK.
 */

declare(strict_types=1);

class SimpleS3Client {
    private string $bucket;
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private string $endpoint;
    private string $publicUrlTemplate;

    public function __construct() {
        $this->bucket = (string)(getenv('S3_BUCKET') ?: '');
        $this->region = (string)(getenv('S3_REGION') ?: 'us-east-1');
        $this->accessKey = (string)(getenv('S3_ACCESS_KEY') ?: '');
        $this->secretKey = (string)(getenv('S3_SECRET_KEY') ?: '');
        $this->endpoint = (string)(getenv('S3_ENDPOINT') ?: 'https://s3.amazonaws.com');
        $this->publicUrlTemplate = (string)(getenv('S3_PUBLIC_URL_TEMPLATE') ?: '');
    }

    /**
     * Checks if S3 Object Storage is enabled and properly configured.
     */
    public function isEnabled(): bool {
        $enabled = getenv('S3_ENABLED');
        return ($enabled === '1' || $enabled === 'true')
            && !empty($this->bucket)
            && !empty($this->accessKey)
            && !empty($this->secretKey);
    }

    /**
     * Uploads a local file to S3 using PUT and AWS Signature V4 headers.
     * Returns the public URL on success, or null on failure.
     */
    public function uploadFile(string $localFilePath, string $s3Key, string $contentType = 'video/mp4'): ?string {
        if (!file_exists($localFilePath)) {
            error_log("S3 Client Error: Local file not found: $localFilePath");
            return null;
        }

        $fileData = @file_get_contents($localFilePath);
        if ($fileData === false) {
            error_log("S3 Client Error: Unable to read local file: $localFilePath");
            return null;
        }

        $payloadHash = hash('sha256', $fileData);

        // Parse endpoint host and scheme
        $endpointParts = parse_url($this->endpoint);
        if (!$endpointParts || empty($endpointParts['host'])) {
            error_log("S3 Client Error: Invalid endpoint configured: {$this->endpoint}");
            return null;
        }

        $endpointHost = $endpointParts['host'];
        $endpointScheme = $endpointParts['scheme'] ?? 'https';

        // Check virtual-hosted vs path-style URL structure
        // Virtual-hosted style is preferred for AWS S3. Path-style is preferred for R2 and MinIO.
        if (strpos($endpointHost, 'amazonaws.com') !== false && strpos($endpointHost, $this->bucket) === false) {
            $host = $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
            $requestUri = '/' . ltrim($s3Key, '/');
            $uploadUrl = "$endpointScheme://$host$requestUri";
        } else {
            $host = $endpointHost;
            if (isset($endpointParts['port'])) {
                $host .= ':' . $endpointParts['port'];
            }
            $requestUri = '/' . $this->bucket . '/' . ltrim($s3Key, '/');
            $uploadUrl = "$endpointScheme://$host$requestUri";
        }

        $service = 's3';
        $algorithm = 'AWS4-HMAC-SHA256';
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        // Headers required for AWS Signature V4 signing
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'content-type' => $contentType,
        ];

        // Sort alphabetically to maintain canonical signature integrity
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
            $signedHeaders .= (empty($signedHeaders) ? '' : ';') . $k;
        }

        $canonicalRequest = "PUT\n"
            . $requestUri . "\n"
            . "\n" // Empty query string
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $credentialScope = "$dateStamp/$this->region/$service/aws4_request";
        $stringToSign = "$algorithm\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

        // Derive AWS signing key
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "$algorithm Credential=$this->accessKey/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

        // Dispatch PUT upload via cURL
        $ch = curl_init($uploadUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $httpHeaders = [
            "Authorization: $authorization"
        ];
        foreach ($headers as $k => $v) {
            $httpHeaders[] = ucfirst($k) . ": $v";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            if (!empty($this->publicUrlTemplate)) {
                return str_replace(
                    ['{bucket}', '{region}', '{key}'],
                    [$this->bucket, $this->region, $s3Key],
                    $this->publicUrlTemplate
                );
            }
            return "$endpointScheme://$host$requestUri";
        } else {
            error_log("S3 Upload Failed with status code: $httpCode. Response: " . (string)$response);
            return null;
        }
    }
}
