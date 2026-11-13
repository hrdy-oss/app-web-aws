<?php

/**
 * Upload / hapus / presigned URL ke S3 memakai AWS Signature V4 (tanpa SDK).
 * Mendukung AWS_SESSION_TOKEN (dipakai di AWS Academy Learner Lab).
 */
class S3Service
{
    private string $bucket;
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private string $token;
    private string $endpoint;

    public function __construct()
    {
        $this->bucket = (string) env('AWS_S3_BUCKET', '');
        $this->region = (string) env('AWS_REGION', 'us-east-1');
        $this->accessKey = (string) env('AWS_ACCESS_KEY_ID', '');
        $this->secretKey = (string) env('AWS_SECRET_ACCESS_KEY', '');
        $this->token = (string) env('AWS_SESSION_TOKEN', '');
        $this->endpoint = (string) env('S3_ENDPOINT', '');
    }

    public function enabled(): bool
    {
        return $this->bucket !== '' && $this->accessKey !== '' && $this->secretKey !== '';
    }

    private function target(string $objectKey): array
    {
        $enc = implode('/', array_map('rawurlencode', explode('/', $objectKey)));
        if ($this->endpoint !== '') {
            $p = parse_url($this->endpoint);
            $host = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            return [$p['scheme'] ?? 'https', $host, '/' . $this->bucket . '/' . $enc];
        }
        return ['https', $this->bucket . '.s3.' . $this->region . '.amazonaws.com', '/' . $enc];
    }

    private function signingKey(string $date): string
    {
        $k = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', 's3', $k, true);
        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    private function request(string $method, string $objectKey, string $body = '', string $contentType = ''): void
    {
        if (!$this->enabled()) {
            throw new RuntimeException('S3 belum dikonfigurasi di .env');
        }
        [$scheme, $host, $uri] = $this->target($objectKey);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $payloadHash = hash('sha256', $body);

        $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $now];
        if ($this->token !== '') {
            $headers['x-amz-security-token'] = $this->token;
        }
        ksort($headers);
        $canonHeaders = '';
        foreach ($headers as $k => $v) {
            $canonHeaders .= $k . ':' . trim($v) . "\n";
        }
        $signed = implode(';', array_keys($headers));
        $canonical = "$method\n$uri\n\n$canonHeaders\n$signed\n$payloadHash";
        $scope = "$date/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));
        $auth = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signed, Signature=$signature";

        $curlHeaders = ['Authorization: ' . $auth];
        foreach ($headers as $k => $v) {
            if ($k !== 'host') {
                $curlHeaders[] = "$k: $v";
            }
        }
        if ($contentType !== '') {
            $curlHeaders[] = 'Content-Type: ' . $contentType;
        }

        $ch = curl_init("$scheme://$host$uri");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('Gagal menghubungi S3: ' . $err);
        }
        if ($code >= 300) {
            $msg = 'S3 menolak permintaan (HTTP ' . $code . ')';
            if (DEBUG_MODE) {
                $msg .= ': ' . substr((string) $resp, 0, 300);
            }
            throw new RuntimeException($msg);
        }
    }

    public function put(string $objectKey, string $body, string $contentType): void
    {
        $this->request('PUT', $objectKey, $body, $contentType);
    }

    public function delete(string $objectKey): void
    {
        $this->request('DELETE', $objectKey);
    }

    /** URL sementara untuk menampilkan objek dari bucket privat. */
    public function presign(string $objectKey, int $expires = 3600): string
    {
        [$scheme, $host, $uri] = $this->target($objectKey);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $scope = "$date/{$this->region}/s3/aws4_request";

        $q = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $now,
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        if ($this->token !== '') {
            $q['X-Amz-Security-Token'] = $this->token;
        }
        ksort($q);
        $qs = http_build_query($q, '', '&', PHP_QUERY_RFC3986);

        $canonical = "GET\n$uri\n$qs\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
        $stringToSign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));

        return "$scheme://$host$uri?$qs&X-Amz-Signature=$signature";
    }
}
