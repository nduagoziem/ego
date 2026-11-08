<?php

namespace Emmy\Ego\Gateway\Paypal;

use Emmy\Ego\Exception\ApiException;
use Emmy\Ego\Exception\UnsupportedGatewayMethodExeption;
use Emmy\Ego\Gateway\Realm\Tollgate;
use Emmy\Ego\Interface\PaymentGatewayInterface;
use Emmy\Ego\Trait\Paypal\PaypalHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Paypal extends Tollgate implements PaymentGatewayInterface
{
    use PaypalHelper;

    /**
     * Hosts PayPal serves its webhook signing certificates from.
     */
    protected array $trustedCertHosts = [
        'api.paypal.com',
        'api-m.paypal.com',
        'api.sandbox.paypal.com',
        'api-m.sandbox.paypal.com',
    ];

    public function __construct()
    {
        $this->baseUrl = config('ego.credentials.paypal.url');
        $this->clientId = config('ego.credentials.paypal.client_id');
        $this->secret = config('ego.credentials.paypal.client_secret');
        $this->bnCode = config('ego.credentials.paypal.bn_code');
    }

    public function setKey(string|array $key): void
    {
        if (is_array($key)) {
            $this->secret = $key['client_secret'] ?? $key['secret_key'] ?? $this->secret;
            $this->bnCode = $key['bn_code'] ?? $key['bnCode'] ?? $this->bnCode;
            $this->clientId = $key['client_id'] ?? $this->clientId;
            $this->baseUrl = $key['url'] ?? $this->baseUrl;
        } else {
            $this->secret = $key;
        }
    }

    public function prepareForPayment(array $data): array
    {
        $email = searchArray('email', $data);
        $merchantId = searchArray(['merchantId', 'merchant_id'], $data);
        $amount = searchArray('amount', $data);
        $currency = searchArray('currency', $data);
        $reference = searchArray('reference', $data);
        $callbackUrl = searchArray(['callback_url', 'callbackUrl'], $data);
        $cancelUrl = searchArray(['cancel_url', 'cancelUrl'], $data);
        $returnUrl = searchArray(['return_url', 'returnUrl'], $data);
        $purchaseUnits = searchArray(['purchase_units'], $data);
        $paymentSource = searchArray(['payment_source', 'purchase_source'], $data);

        $this->setEmail($email);
        $this->setAmount($amount);
        $this->setCurrency($currency);
        $this->setReference($reference);

        if ($purchaseUnits) {
            $this->setPurchaseUnits($purchaseUnits);
        }

        if ($paymentSource) {
            $this->setPaymentSource($paymentSource);
        }

        if ($merchantId) {
            $this->setMerchantId($merchantId);
        }

        if ($callbackUrl) {
            $this->setCallbackUrl($callbackUrl);
        }

        if ($cancelUrl) {
            $this->setCancelUrl($cancelUrl);
        }

        if ($returnUrl) {
            $this->setReturnUrl($returnUrl);
        }

        return $this->builder;
    }

    /**
     * Creates a PayPal order and returns the URL the payer should be redirected to.
     * Note: PayPal expects "amount" in major units (e.g. 10.50), not cents.
     */
    public function pay(?array $data = []): array
    {
        $payload = $this->buildPayload($data ?? []);

        $merchantId = $payload['merchant_id'] ?? null;
        if ($merchantId) {
            $this->merchantId = $merchantId;
        }

        $header = ['Prefer' => 'return=representation'];

        $purchaseUnit = [
            'reference_id' => isset($payload['reference']) ? (string) $payload['reference'] : null,
            'amount' => [
                'value' => (string) ($payload['amount'] ?? ''),
                'currency_code' => $payload['currency'] ?? null,
            ],
        ];

        if ($merchantId) {
            $purchaseUnit['payee'] = ['merchant_id' => $merchantId];
        }

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => $payload['purchase_units'] ?? [array_filter($purchaseUnit, fn ($v) => $v !== null)],
            'payment_source' => $payload['payment_source'] ?? [
                'paypal' => array_filter([
                    // The payer's email; PayPal uses it to prefill the login form.
                    'email_address' => $payload['email'] ?? null,
                    'experience_context' => array_filter([
                        'user_action' => 'PAY_NOW',
                        'cancel_url' => $payload['cancel_url'] ?? $payload['callback_url'] ?? null,
                        'return_url' => $payload['return_url'] ?? $payload['callback_url'] ?? null,
                    ]),
                ]),
            ],
        ];

        $response = $this->makeRequest('POST', $this->versionedBaseUrl().'/checkout/orders', $body, $header);

        $url = $this->findLink($response, ['payer-action', 'approve']);

        if (! $url) {
            throw new ApiException('PayPal did not return an approval link: '.json_encode($response));
        }

        return [
            'status' => true,
            'message' => 'Authorization URL created',
            'url' => $url,
            'id' => $response['id'] ?? null,
            'reference' => $response['purchase_units'][0]['reference_id'] ?? null,
            'api_message' => $response,
        ];
    }

    /**
     * Verifies a PayPal order. $paymentReference is the PayPal order id (PayPal appends it
     * to your return_url as "token"). An APPROVED order is captured so funds actually move.
     */
    public function verifyPayment(array|string $paymentReference, ?string $paymentType = null): array
    {
        $orderId = is_array($paymentReference)
            ? searchArray(['token', 'order_id', 'orderId', 'id', 'reference'], $paymentReference)
            : $paymentReference;

        if (! $orderId) {
            throw new ApiException('A PayPal order id is required to verify a payment.');
        }

        $orderId = rawurlencode((string) $orderId);

        $response = $this->makeRequest('GET', $this->versionedBaseUrl()."/checkout/orders/{$orderId}");

        if (($response['status'] ?? null) === 'APPROVED') {
            $response = $this->makeRequest(
                'POST',
                $this->versionedBaseUrl()."/checkout/orders/{$orderId}/capture",
                [],
                [
                    'Prefer' => 'return=representation',
                    // Deterministic id makes retries idempotent: PayPal will not capture twice.
                    'PayPal-Request-Id' => "ego-capture-{$orderId}",
                ]
            );
        }

        if (! isset($response['purchase_units'])) {
            throw new ApiException('Transaction not found');
        }

        return [
            'status' => $this->mapStatus($response),
            'message' => $response['status'] ?? 'No description',
            'data' => $response,
            'reference' => $response['purchase_units'][0]['reference_id'] ?? null,
        ];
    }

    public function verifyTransaction(string $reference): array
    {
        return $this->verifyPayment($reference);
    }

    public function prepareForTransfer(array $data): array
    {
        return $data;
    }

    public function transfer(?array $data = []): array
    {
        throw new UnsupportedGatewayMethodExeption('Method not Supported. PayPal does not support bank transfers via this package.');
    }

    public function getBanks(string $countryCode = 'NG'): array
    {
        throw new UnsupportedGatewayMethodExeption('Method not Supported. PayPal does not support this feature.');
    }

    public function verifyAccountNumber(?array $request = []): array
    {
        throw new UnsupportedGatewayMethodExeption('Method not Supported. PayPal does not support this feature.');
    }

    /**
     * Verify PayPal webhook request (offline signature verification).
     * See https://developer.paypal.com/api/rest/webhooks/rest/#link-selfverificationmethod
     */
    public function verifyWebhook(Request $request): void
    {
        $transmissionId = $request->header('paypal-transmission-id');
        $timeStamp = $request->header('paypal-transmission-time');
        $certUrl = $request->header('paypal-cert-url');
        $signature = $request->header('paypal-transmission-sig');
        $authAlgo = $request->header('paypal-auth-algo');
        $webhookId = config('ego.credentials.paypal.paypal_webhook_id');

        if (! ($transmissionId && $timeStamp && $certUrl && $signature && $authAlgo && $webhookId)) {
            abort(404);
        }

        $algo = match (strtoupper($authAlgo)) {
            'SHA256WITHRSA' => OPENSSL_ALGO_SHA256,
            'SHA1WITHRSA' => OPENSSL_ALGO_SHA1,
            'SHA512WITHRSA' => OPENSSL_ALGO_SHA512,
            default => null,
        };

        // The cert URL comes from the request, so it must point at PayPal; otherwise anyone
        // could sign a forged event with their own key and host the matching certificate.
        if (! $algo || ! $this->isTrustedCertUrl($certUrl)) {
            abort(404);
        }

        $crc32 = sprintf('%u', crc32($request->getContent()));
        $message = "{$transmissionId}|{$timeStamp}|{$webhookId}|{$crc32}";

        $publicKey = $this->getPublicKey($certUrl);

        if (! $publicKey) {
            abort(404);
        }

        // openssl_verify returns 1 (valid), 0 (invalid) or -1/false (error); only 1 is a pass.
        $verified = openssl_verify($message, (string) base64_decode($signature, true), $publicKey, $algo);

        if ($verified !== 1) {
            abort(404);
        }
    }

    protected function isTrustedCertUrl(string $certUrl): bool
    {
        $parts = parse_url($certUrl);

        return ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower($parts['host'] ?? ''), $this->trustedCertHosts, true)
            && ! isset($parts['port'])
            && ! isset($parts['user']);
    }

    /**
     * Get and cache the PayPal certificate for 24 hours
     */
    protected function getPublicKey(string $certUrl): ?string
    {
        $key = 'paypal_cert_'.md5($certUrl);

        if ($cert = Cache::get($key)) {
            return $cert;
        }

        $response = Http::get($certUrl);

        if (! $response->ok() || ! openssl_pkey_get_public($response->body())) {
            return null;
        }

        Cache::put($key, $response->body(), 86400);

        return $response->body();
    }
}
