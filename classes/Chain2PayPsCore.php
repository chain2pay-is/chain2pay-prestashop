<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Chain2Pay API client shared by the Chain2Pay plugins.
 *
 * Plain PHP (7.4+), no framework dependency. It creates a payment through the
 * Chain2Pay API, signs the callback URL of an order and, when the callback
 * arrives, asks the Chain2Pay API whether that payment is really paid before
 * the shop marks anything as paid.
 *
 * API documentation: https://docs.chain2pay.is
 *
 * @license GPL-3.0-or-later
 */
final class Chain2PayPsCore
{
    const VERSION = '1.0.0';
    const API_BASE = 'https://chain2pay.is';

    /** Results of verifyPaid(). */
    const PAID = 'paid';
    const NOT_PAID = 'not_paid';
    const MISMATCH = 'mismatch';
    const UNAVAILABLE = 'unavailable';

    /**
     * Providers available through the Chain2Pay plugins.
     *
     * id => label shown to the buyer, description shown to the buyer, minimum amount
     * (checked here for USD and EUR orders, the API checks every currency) and, when
     * the provider only takes some currencies, that list.
     */
    public static function providers()
    {
        return array(
            'multihosted'  => array('label' => 'Card Payment', 'description' => 'Pay with Visa, Mastercard, Apple Pay and more via Chain2Pay', 'min' => 6, 'currencies' => null, 'admin' => 'Hosted checkout: all providers (recommended)'),
            'stripe'       => array('label' => 'Card (Stripe)', 'description' => 'Pay with credit or debit card via Stripe', 'min' => 3, 'currencies' => array('USD', 'EUR'), 'admin' => 'Stripe'),
            'moonpay'      => array('label' => 'Pay with MoonPay', 'description' => 'Card payment via MoonPay', 'min' => 20, 'currencies' => null, 'admin' => 'MoonPay'),
            'revolut'      => array('label' => 'Pay with Revolut', 'description' => 'Pay securely with Revolut', 'min' => 6, 'currencies' => null, 'admin' => 'Revolut'),
            'coinbase'     => array('label' => 'Pay with Coinbase', 'description' => 'Card payment via Coinbase', 'min' => 10, 'currencies' => null, 'admin' => 'Coinbase'),
            'paypal'       => array('label' => 'Pay with PayPal', 'description' => 'Pay securely with PayPal', 'min' => 5, 'currencies' => null, 'admin' => 'PayPal'),
            'banxa'        => array('label' => 'Pay with Banxa', 'description' => 'Card payment via Banxa', 'min' => 17, 'currencies' => null, 'admin' => 'Banxa'),
            'ramp'         => array('label' => 'Pay with Ramp', 'description' => 'Pay with card or bank via Ramp Network', 'min' => 7, 'currencies' => null, 'admin' => 'Ramp Network'),
            'robinhood'    => array('label' => 'Pay with Robinhood', 'description' => 'Debit card payment via Robinhood (US only)', 'min' => 5, 'currencies' => null, 'admin' => 'Robinhood (US only)'),
            'skrill'       => array('label' => 'Pay with Skrill', 'description' => 'Pay with your Skrill wallet', 'min' => 22, 'currencies' => null, 'admin' => 'Skrill'),
            'alchemy'      => array('label' => 'Pay with Alchemy Pay', 'description' => 'Card payment via Alchemy Pay', 'min' => 17, 'currencies' => null, 'admin' => 'Alchemy Pay'),
            'alchemylocal' => array('label' => 'Local payment methods', 'description' => 'Pay with local payment methods via Alchemy Pay (Local)', 'min' => 15, 'currencies' => null, 'admin' => 'Alchemy Pay (local methods)'),
            'blockchain'   => array('label' => 'Pay with Blockchain.com', 'description' => 'Pay via Blockchain.com', 'min' => 15, 'currencies' => null, 'admin' => 'Blockchain.com'),
            'btcdirect'    => array('label' => 'Pay with BTC Direct', 'description' => 'Pay with card or Apple Pay via BTC Direct', 'min' => 33, 'currencies' => null, 'admin' => 'BTC Direct'),
            'coindisco'    => array('label' => 'Pay by card', 'description' => 'Pay by card via Coindisco', 'min' => 23, 'currencies' => null, 'admin' => 'Coindisco'),
            'stixramp'     => array('label' => 'Pay with Cash App', 'description' => 'Pay with Cash App (US only)', 'min' => 20, 'currencies' => null, 'admin' => 'Cash App (US only)'),
            'crypto'       => array('label' => 'Pay with Crypto', 'description' => 'Pay with crypto (USDC, USDT, ETH, BNB and more)', 'min' => 6, 'currencies' => null, 'admin' => 'Crypto'),
            'custom'       => array('label' => 'Pay by Card (Chain2Pay)', 'description' => 'Pay securely with your card', 'min' => 5, 'currencies' => null, 'admin' => 'Custom provider (uses the provider slug below)'),
        );
    }

    /** id => name for an admin drop-down or a list of checkboxes. */
    public static function providerChoices()
    {
        $choices = array();
        foreach (self::providers() as $id => $provider) {
            $choices[$id] = $provider['admin'];
        }

        return $choices;
    }

    public static function isProvider($id)
    {
        $providers = self::providers();

        return is_string($id) && isset($providers[$id]);
    }

    /**
     * The provider slug sent to the API: the id itself, or the merchant's slug for "custom".
     * Empty string when nothing valid is configured.
     */
    public static function providerSlug($id, $customSlug = '')
    {
        if (!self::isProvider($id)) {
            return '';
        }
        if ($id !== 'custom') {
            return $id;
        }
        $slug = strtolower(trim((string) $customSlug));

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $slug) ? $slug : '';
    }

    /**
     * Checks done before calling the API. Returns an empty string when the order can go
     * ahead, or a message that can be shown to the buyer.
     */
    public static function precheck($id, $amount, $currency)
    {
        if (!self::isProvider($id)) {
            return 'This payment method is not available.';
        }
        $providers = self::providers();
        $provider = $providers[$id];
        $currency = strtoupper(trim((string) $currency));
        $amount = (float) $amount;
        if ($amount <= 0) {
            return 'There is nothing to pay on this order.';
        }
        if (is_array($provider['currencies']) && !in_array($currency, $provider['currencies'], true)) {
            return 'This payment method accepts ' . implode(' and ', $provider['currencies']) . ' only. This order is in ' . $currency . '.';
        }
        if (($currency === 'USD' || $currency === 'EUR') && $amount < (float) $provider['min']) {
            return 'The minimum amount for this payment method is ' . $provider['min'] . ' ' . $currency . '. This order is ' . self::amount($amount) . ' ' . $currency . '.';
        }

        return '';
    }

    public static function isSandboxKey($apiKey)
    {
        return strpos(trim((string) $apiKey), 'c2p_sandbox_') === 0;
    }

    /** Amount as the plain decimal string used in references and tokens: 12.5 => "12.50". */
    public static function amount($value)
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /**
     * Reference of one order of one shop at one amount. It is stored in the payment
     * (metadata.order_ref) and signed in the callback URL.
     */
    public static function orderRef($platform, $orderId, $amount, $currency)
    {
        return strtolower((string) $platform) . ':' . (string) $orderId . ':' . self::amount($amount) . ':' . strtoupper(trim((string) $currency));
    }

    /** Signature of an order reference with the shop's own secret. */
    public static function token($secret, $orderRef)
    {
        return hash_hmac('sha256', 'chain2pay|' . (string) $orderRef, (string) $secret);
    }

    public static function checkToken($secret, $orderRef, $token)
    {
        if (strlen((string) $secret) < 16 || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals(self::token($secret, $orderRef), $token);
    }

    /**
     * Creates a payment.
     *
     * @return array success (bool), checkout_url, payment_id, error (message for logs and for the buyer)
     */
    public static function createPayment($apiKey, $amount, $currency, $provider, $email, $callbackUrl, array $metadata, $userAgent = '')
    {
        $apiKey = trim((string) $apiKey);
        if ($apiKey === '') {
            return array('success' => false, 'error' => 'The Chain2Pay API key is not configured.');
        }
        if (trim((string) $provider) === '') {
            return array('success' => false, 'error' => 'No Chain2Pay provider is configured.');
        }
        $payload = array(
            'amount' => (float) self::amount($amount),
            'currency' => strtoupper(trim((string) $currency)),
            'provider' => (string) $provider,
            'callback_url' => (string) $callbackUrl,
            'metadata' => $metadata,
        );
        $email = trim((string) $email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $payload['customer_email'] = $email;
        }

        // A transport error or a 5xx answer is retried; 2xx and 4xx answers are final.
        $response = array('http' => 0, 'body' => '', 'error' => 'no attempt');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = self::http('POST', '/api/v2/payments', $apiKey, $payload, $userAgent);
            if ($response['error'] === '' && $response['http'] > 0 && $response['http'] < 500) {
                break;
            }
            if ($attempt < 3) {
                usleep(400000 * $attempt);
            }
        }
        if ($response['error'] !== '') {
            return array('success' => false, 'error' => 'Could not reach Chain2Pay: ' . $response['error']);
        }
        $data = json_decode($response['body'], true);
        if ($response['http'] !== 200 && $response['http'] !== 201) {
            return array('success' => false, 'error' => 'Chain2Pay answered HTTP ' . $response['http'] . self::apiMessage($data));
        }
        $checkoutUrl = is_array($data) && isset($data['checkout_url']) && is_string($data['checkout_url']) ? $data['checkout_url'] : '';
        $paymentId = is_array($data) && isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : '';
        if ($paymentId === '' || !preg_match('#^https://[^\s"\'<>]+$#i', $checkoutUrl)) {
            return array('success' => false, 'error' => 'Chain2Pay returned an answer without a checkout link.');
        }

        return array('success' => true, 'checkout_url' => $checkoutUrl, 'payment_id' => $paymentId, 'error' => '');
    }

    /**
     * Asks the API whether a payment is paid and belongs to the expected order.
     *
     * @param string $paymentId        id sent by Chain2Pay in the callback (chain2pay_order_id)
     * @param string $expectedRef      order reference signed in the callback URL
     * @param float  $expectedAmount   amount signed in the callback URL
     * @param string $expectedCurrency currency signed in the callback URL
     * @param string|array $storedPaymentId payment id(s) saved by the shop when it created the payment(s), '' when none
     *
     * @return array result (PAID, NOT_PAID, MISMATCH or UNAVAILABLE), reason, payment (API data)
     */
    public static function verifyPaid($apiKey, $paymentId, $expectedRef, $expectedAmount, $expectedCurrency, $storedPaymentId = '', $userAgent = '')
    {
        $paymentId = trim((string) $paymentId);
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $paymentId)) {
            return array('result' => self::MISMATCH, 'reason' => 'the callback carries no valid payment id', 'payment' => array());
        }
        // One order can have several payments (the buyer went back and started again): any of them counts.
        $known = array();
        foreach ((array) $storedPaymentId as $stored) {
            if (is_scalar($stored) && trim((string) $stored) !== '') {
                $known[] = trim((string) $stored);
            }
        }
        if ($known && !in_array($paymentId, $known, true)) {
            return array('result' => self::MISMATCH, 'reason' => 'the payment id is not one created for this order', 'payment' => array());
        }
        if (trim((string) $apiKey) === '') {
            return array('result' => self::UNAVAILABLE, 'reason' => 'the Chain2Pay API key is not configured', 'payment' => array());
        }
        // A transport error or a 5xx answer is retried twice before the callback is asked to come back later.
        $response = array('http' => 0, 'body' => '', 'error' => 'no attempt');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = self::http('GET', '/api/v2/payments/' . rawurlencode($paymentId), trim((string) $apiKey), null, $userAgent);
            if ($response['error'] === '' && $response['http'] > 0 && $response['http'] < 500) {
                break;
            }
            if ($attempt < 3) {
                usleep(700000 * $attempt);
            }
        }
        if ($response['error'] !== '') {
            return array('result' => self::UNAVAILABLE, 'reason' => 'could not reach Chain2Pay: ' . $response['error'], 'payment' => array());
        }
        if ($response['http'] === 404) {
            return array('result' => self::MISMATCH, 'reason' => 'Chain2Pay does not know this payment for this API key', 'payment' => array());
        }
        $data = json_decode($response['body'], true);
        if ($response['http'] !== 200 || !is_array($data)) {
            return array('result' => self::UNAVAILABLE, 'reason' => 'Chain2Pay answered HTTP ' . $response['http'] . self::apiMessage($data), 'payment' => array());
        }
        if (isset($data['id']) && is_scalar($data['id']) && (string) $data['id'] !== $paymentId) {
            return array('result' => self::MISMATCH, 'reason' => 'Chain2Pay returned another payment', 'payment' => $data);
        }
        $status = isset($data['status']) && is_string($data['status']) ? strtolower($data['status']) : '';
        if ($status !== 'paid') {
            return array('result' => self::NOT_PAID, 'reason' => 'the payment status is "' . $status . '"', 'payment' => $data);
        }
        if (isset($data['metadata']) && is_array($data['metadata']) && isset($data['metadata']['order_ref'])) {
            if (!is_string($data['metadata']['order_ref']) || !hash_equals((string) $expectedRef, $data['metadata']['order_ref'])) {
                return array('result' => self::MISMATCH, 'reason' => 'the payment was created for another order or another amount', 'payment' => $data);
            }
        }
        if (isset($data['currency'], $data['amount']) && is_string($data['currency']) && is_numeric($data['amount'])
            && strtoupper($data['currency']) === strtoupper(trim((string) $expectedCurrency))
            && (float) $data['amount'] + 0.01 < (float) $expectedAmount) {
            return array('result' => self::MISMATCH, 'reason' => 'the payment amount (' . self::amount($data['amount']) . ') is lower than the order amount (' . self::amount($expectedAmount) . ')', 'payment' => $data);
        }

        return array('result' => self::PAID, 'reason' => '', 'payment' => $data);
    }

    /** Short text for an order note: transaction hash, amount and coin when the API gives them. */
    public static function describe(array $payment)
    {
        $parts = array();
        if (isset($payment['id']) && is_scalar($payment['id'])) {
            $parts[] = 'payment ' . $payment['id'];
        }
        if (isset($payment['provider']) && is_string($payment['provider']) && $payment['provider'] !== '') {
            $parts[] = 'provider ' . $payment['provider'];
        }
        if (isset($payment['paid_amount']) && is_numeric($payment['paid_amount'])) {
            $parts[] = 'received ' . $payment['paid_amount'] . (isset($payment['paid_coin']) && is_string($payment['paid_coin']) ? ' ' . $payment['paid_coin'] : '');
        }
        if (isset($payment['tx_hash']) && is_string($payment['tx_hash']) && $payment['tx_hash'] !== '') {
            $parts[] = 'transaction ' . $payment['tx_hash'];
        }
        if (!empty($payment['sandbox'])) {
            $parts[] = 'SANDBOX (test payment)';
        }

        return implode(', ', $parts);
    }

    /** Transaction reference to store on the order: the transaction hash, or the payment id. */
    public static function transactionId(array $payment, $paymentId)
    {
        if (isset($payment['tx_hash']) && is_string($payment['tx_hash']) && $payment['tx_hash'] !== '') {
            return substr($payment['tx_hash'], 0, 190);
        }

        return 'chain2pay_' . $paymentId;
    }

    private static function apiBase()
    {
        // Only used by the automated tests of the plugins.
        if (defined('CHAIN2PAY_TEST_API_BASE') && is_string(CHAIN2PAY_TEST_API_BASE) && CHAIN2PAY_TEST_API_BASE !== '') {
            return rtrim(CHAIN2PAY_TEST_API_BASE, '/');
        }

        return self::API_BASE;
    }

    private static function apiMessage($data)
    {
        if (!is_array($data) || !isset($data['error'])) {
            return '';
        }
        if (is_array($data['error']) && isset($data['error']['message']) && is_string($data['error']['message'])) {
            return ': ' . substr($data['error']['message'], 0, 300);
        }
        if (is_string($data['error'])) {
            return ': ' . substr($data['error'], 0, 300);
        }

        return '';
    }

    /** @return array http (int), body (string), error (string, empty when the request went through) */
    private static function http($method, $path, $apiKey, $json, $userAgent)
    {
        if (!function_exists('curl_init')) {
            return array('http' => 0, 'body' => '', 'error' => 'the PHP cURL extension is not installed');
        }
        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        );
        $options = array(
            CURLOPT_URL => self::apiBase() . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Chain2Pay-Plugin/' . self::VERSION . ($userAgent !== '' ? ' ' . preg_replace('/[^A-Za-z0-9._\/ -]/', '', (string) $userAgent) : ''),
        );
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($json);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        $handle = curl_init();
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $error = curl_error($handle);
        $http = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($body === false || $error !== '') {
            return array('http' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'empty answer');
        }

        return array('http' => $http, 'body' => (string) $body, 'error' => '');
    }
}
