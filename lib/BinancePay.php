<?php
// Binance Pay personal API client.
// Personal receive links use HMAC-SHA512 OpenAPI requests. Personal incoming
// payments are verified through the signed Pay Trade History API below.

final class BinancePay {
    private const BASE_URL = 'https://bpay.binanceapi.com';

    public static function mode(array $details): string {
        return 'personal';
    }

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

    // Personal Binance Pay/Agent Pay: create a receive link for a fixed
    // amount. This does not create a Merchant order and has no merchant
    // webhook; personal incoming payments must be reviewed separately.
    public static function createReceive(array $details, float $amount, string $currency = 'USDT', string $note = ''): array {
        $body = [
            'currency' => strtoupper(trim($currency)),
            'amount' => number_format($amount, 2, '.', ''),
        ];
        if (trim($note) !== '') $body['description'] = trim($note);
        return self::request($details, '/binancepay/openapi/user/c2c/createReceive', $body);
    }

    // Personal account Pay history. Binance documents this as a signed
    // USER_DATA endpoint on api.binance.com, separate from the personal
    // receive-link endpoint above.
    public static function payTransactions(array $details, int $startMs, int $endMs, int $limit = 100): array {
        if (!self::configured($details)) {
            return ['ok' => false, 'error' => 'Binance Pay credentials are not configured.'];
        }
        $params = [
            'startTime' => (string)$startMs,
            'endTime' => (string)$endMs,
            'limit' => (string)min(100, max(1, $limit)),
            'recvWindow' => '5000',
            'timestamp' => (string)round(microtime(true) * 1000),
        ];
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $query, self::secret($details));
        $url = 'https://api.binance.com/sapi/v1/pay/transactions?' . $query . '&signature=' . $signature;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-MBX-APIKEY: ' . trim((string)$details['api_key']),
            ],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'error' => 'Binance Pay history connection failed: ' . $error];
        $response = json_decode((string)$raw, true);
        if (!is_array($response)) return ['ok' => false, 'error' => 'Binance Pay history returned invalid JSON (HTTP ' . $http . ').'];
        $response['_http'] = $http;
        if (($response['success'] ?? true) === false || (isset($response['code']) && (string)$response['code'] !== '000000') || $http >= 400) {
            return [
                'ok' => false,
                'error' => (string)($response['msg'] ?? $response['message'] ?? 'Binance Pay history request failed.'),
                'code' => (string)($response['code'] ?? $http),
            ];
        }
        return ['ok' => true, 'data' => is_array($response['data'] ?? null) ? $response['data'] : []];
    }

    // Verify one specific Binance Pay payment by the Order ID Binance
    // shows the sender after paying (there is no blockchain TXID for a
    // Binance Pay transfer to a UID — the Order ID is the identifier).
    // The pasted id is matched against the row's orderId OR transactionId
    // (tolerant fallback: some rows only carry one of them), and it
    // confirms only when the currency, receiver UID, time window, order
    // type and not-already-used all match. The exact amount is NOT
    // checked (owner's decision: the Order ID verified from Binance is
    // enough). A worthless token can never match: its currency is not USDT.
    public static function findPaymentByOrderId(array $details, string $orderId, string $currency, int $startMs, int $endMs, array $excludeTxids = []): array {
        $uid = trim((string)($details['uid'] ?? ''));
        if ($uid === '') return ['ok' => false, 'error' => 'Binance Pay UID is required for automatic verification.'];
        $orderId = trim($orderId);
        if ($orderId === '') return ['ok' => true, 'found' => false];
        $res = self::payTransactions($details, $startMs, $endMs, 100);
        if (!($res['ok'] ?? false)) return $res;
        $currency = strtoupper(trim($currency));
        foreach (($res['data'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $rowOrderId = trim((string)($row['orderId'] ?? ''));
            $rowTxId = trim((string)($row['transactionId'] ?? ''));
            if ($rowOrderId !== $orderId && $rowTxId !== $orderId) continue;
            $time = (int)($row['transactionTime'] ?? 0);
            if ($time < 10000000000) $time *= 1000;
            if ($time < $startMs || $time > $endMs) continue;
            if (!in_array((string)($row['orderType'] ?? ''), ['C2C', 'PAY'], true)) continue;
            if ((string)($row['receiverInfo']['binanceId'] ?? '') !== $uid) continue;
            if (in_array($rowTxId, $excludeTxids, true)) continue;
            foreach (self::paymentFunds($row) as $fund) {
                if (strtoupper((string)($fund['currency'] ?? '')) !== $currency) continue;
                if ((float)($fund['amount'] ?? 0) <= 0) continue;
                return ['ok' => true, 'found' => true, 'transaction' => $row];
            }
        }
        return ['ok' => true, 'found' => false];
    }

    private static function paymentFunds(array $row): array {
        if (is_array($row['fundsDetail'] ?? null) && $row['fundsDetail']) return $row['fundsDetail'];
        return [[
            'currency' => $row['currency'] ?? '',
            'amount' => $row['amount'] ?? 0,
        ]];
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
