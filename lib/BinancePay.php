<?php
// Binance Pay Merchant API client.
// API requests use HMAC-SHA512; webhook notifications use Binance's RSA
// signature and the public certificate returned by /certificates.

final class BinancePay {
    private const BASE_URL = 'https://bpay.binanceapi.com';

    public static function configured(array $details): bool {
        return trim((string)($details['api_key'] ?? '')) !== ''
            && self::secret($details) !== '';
    }

    public static function secret(array $details): string {
        $enc = (string)($details['api_secret_enc'] ?? '');
        return $enc === '' ? '' : dec_token($enc);
    }

    public static function request(array $details, string $path, array $body = []): array {
        if (!self::configured($details)) {
            return ['ok' => false, 'error' => 'Binance Pay credentials are not configured.'];
        }
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return ['ok' => false, 'error' => 'Could not encode Binance Pay request.'];

        $timestamp = (string)round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16)); // Binance requires 32 bytes/chars.
        $payload = $timestamp . "\n" . $nonce . "\n" . $json . "\n";
        $signature = strtoupper(hash_hmac('sha512', $payload, self::secret($details)));

        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'BinancePay-Timestamp: ' . $timestamp,
                'BinancePay-Nonce: ' . $nonce,
                'BinancePay-Certificate-SN: ' . trim((string)$details['api_key']),
                'BinancePay-Signature: ' . $signature,
            ],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'error' => 'Binance Pay connection failed: ' . $error];
        $response = json_decode((string)$raw, true);
        if (!is_array($response)) return ['ok' => false, 'error' => 'Binance Pay returned an invalid response (HTTP ' . $http . ').'];
        $response['_http'] = $http;
        return $response;
    }

    public static function createOrder(array $details, string $tradeNo, float $amount, string $name, string $referenceId): array {
        $safeName = trim((string)preg_replace('/[^\pL\pN ._\-]/u', '', $name));
        if ($safeName === '') $safeName = 'Digital product';
        $safeName = function_exists('mb_substr') ? mb_substr($safeName, 0, 120) : substr($safeName, 0, 120);
        return self::request($details, '/binancepay/openapi/v3/order', [
            'env' => ['terminalType' => 'APP'],
            'merchantTradeNo' => $tradeNo,
            'orderAmount' => number_format($amount, 2, '.', ''),
            'currency' => 'USDT',
            'description' => $safeName,
            'goodsDetails' => [[
                'goodsType' => '02',
                'goodsCategory' => 'Z000',
                'referenceGoodsId' => $referenceId,
                'goodsName' => $safeName,
                'goodsDetail' => $safeName,
            ]],
        ]);
    }

    public static function queryOrder(array $details, string $tradeNo, string $prepayId = ''): array {
        $body = ['merchantTradeNo' => $tradeNo];
        if ($prepayId !== '') $body['prepayId'] = $prepayId;
        return self::request($details, '/binancepay/openapi/v2/order/query', $body);
    }

    public static function certificates(array $details): array {
        return self::request($details, '/binancepay/openapi/certificates', []);
    }

    public static function verifyWebhook(array $details, string $rawBody, array $headers): bool {
        $serial = self::header($headers, 'BinancePay-Certificate-SN');
        $nonce = self::header($headers, 'BinancePay-Nonce');
        $timestamp = self::header($headers, 'BinancePay-Timestamp');
        $signature = self::header($headers, 'BinancePay-Signature');
        $expectedSerial = trim((string)($details['cert_sn'] ?? ''));
        $publicKey = trim((string)($details['cert_public'] ?? ''));
        if ($serial === '' || $nonce === '' || $timestamp === '' || $signature === '' || $publicKey === '') return false;
        if ($expectedSerial !== '' && !hash_equals($expectedSerial, $serial)) return false;
        $decoded = base64_decode($signature, true);
        if ($decoded === false) return false;
        $pem = $publicKey;
        if (!str_contains($pem, 'BEGIN PUBLIC KEY')) {
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }
        $key = openssl_pkey_get_public($pem);
        if ($key === false) return false;
        $payload = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";
        return openssl_verify($payload, $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function header(array $headers, string $wanted): string {
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === strtolower($wanted)) return trim((string)$value);
        }
        return '';
    }
}
