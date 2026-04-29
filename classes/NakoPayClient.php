<?php
/**
 * NakoPay HTTP client + signature helpers for the PrestaShop module.
 *
 * Dual base URL strategy (per project memory: plugin-base-urls):
 *   PRIMARY   - https://daslrxpkbkqrbnjwouiq.supabase.co/functions/v1/   (active)
 *   FALLBACK  - https://api.nakopay.com/v1/                              (reserved
 *               for the future self-hosted cutover; declared, not actively used)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NakoPayClient
{
    const VERSION       = '0.1.0';
    const BASE_PRIMARY  = 'https://daslrxpkbkqrbnjwouiq.supabase.co/functions/v1/';
    const BASE_FALLBACK = 'https://api.nakopay.com/v1/';
    const SIG_TOLERANCE = 300;

    public function getBaseUrl()
    {
        $override = trim((string) Configuration::get('NAKOPAY_API_BASE_OVERRIDE'));
        if ($override !== '') {
            return rtrim($override, '/') . '/';
        }
        if (defined('NAKOPAY_API_BASE') && is_string(NAKOPAY_API_BASE) && NAKOPAY_API_BASE !== '') {
            return rtrim(NAKOPAY_API_BASE, '/') . '/';
        }
        return self::BASE_PRIMARY;
    }

    public function resolveEndpoint($name)
    {
        $base = $this->getBaseUrl();
        $isV1 = (strpos($base, '/v1/') !== false) && (strpos($base, '/functions/v1/') === false);
        $map  = [
            'invoices-create' => $isV1 ? 'invoices/create' : 'invoices-create',
            'invoices-get'    => $isV1 ? 'invoices/get'    : 'invoices-get',
            'ping'            => 'ping',
        ];
        return isset($map[$name]) ? $map[$name] : $name;
    }

    public function getApiKey()
    {
        return trim((string) Configuration::get('NAKOPAY_API_KEY'));
    }

    public function getWebhookSecret()
    {
        return trim((string) Configuration::get('NAKOPAY_WEBHOOK_SECRET'));
    }

    public function isTestMode()
    {
        if ((int) Configuration::get('NAKOPAY_TEST_MODE') === 1) {
            return true;
        }
        return strpos($this->getApiKey(), 'sk_test_') === 0;
    }

    private function request($method, $endpoint, $body = null)
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return ['_ok' => false, '_status' => 0, '_error' => 'NakoPay API key is not configured.'];
        }
        $url     = $this->getBaseUrl() . ltrim($this->resolveEndpoint($endpoint), '/');
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'User-Agent: NakoPay-PrestaShop/' . self::VERSION,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body !== null ? json_encode($body) : null,
        ]);
        $raw    = curl_exec($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['_ok' => false, '_status' => 0, '_error' => $err ?: 'network error'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['_ok' => false, '_status' => $status, '_error' => 'invalid json', '_raw' => $raw];
        }
        $decoded['_ok']     = $status >= 200 && $status < 300;
        $decoded['_status'] = $status;
        return $decoded;
    }

    public function ping()
    {
        return $this->request('GET', 'ping');
    }

    public function createInvoice(array $args)
    {
        return $this->request('POST', 'invoices-create', [
            'amount'         => (string) $args['amount'],
            'currency'       => isset($args['currency']) ? strtoupper((string) $args['currency']) : 'USD',
            'coin'           => isset($args['coin']) ? strtoupper((string) $args['coin']) : 'BTC',
            'description'    => isset($args['description']) ? (string) $args['description'] : 'PrestaShop order',
            'customer_email' => isset($args['customer_email']) ? (string) $args['customer_email'] : '',
            'metadata'       => array_filter([
                'ps_cart_id'  => isset($args['cart_id']) ? (string) $args['cart_id'] : null,
                'ps_order_id' => isset($args['order_id']) ? (string) $args['order_id'] : null,
                'source'      => 'prestashop',
            ], function ($v) { return $v !== null && $v !== ''; }),
        ]);
    }

    public function getInvoice($id)
    {
        return $this->request('GET', 'invoices-get?id=' . rawurlencode($id));
    }

    public function verifyWebhook($rawBody, $sigHeader)
    {
        $secret = $this->getWebhookSecret();
        if ($secret === '' || $sigHeader === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $sigHeader) as $kv) {
            $kv = trim($kv);
            if ($kv === '' || strpos($kv, '=') === false) {
                continue;
            }
            list($k, $v) = explode('=', $kv, 2);
            $parts[trim($k)] = trim($v);
        }
        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }
        $t = (int) $parts['t'];
        if (abs(time() - $t) > self::SIG_TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        return hash_equals($expected, $parts['v1']);
    }
}
