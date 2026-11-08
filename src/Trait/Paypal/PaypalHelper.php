<?php

namespace Emmy\Ego\Trait\Paypal;

use Emmy\Ego\Exception\ApiException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait PaypalHelper
{
    /**
     * PayPal API host, e.g. https://api-m.sandbox.paypal.com or https://api-m.paypal.com
     */
    public ?string $baseUrl = null;

    protected string $version = 'v2';

    private ?string $token = null;

    private ?string $clientId = null;

    private ?string $secret = null;

    private ?string $bnCode = null;

    private ?string $merchantId = null;

    protected $http;

    protected function versionedBaseUrl(): string
    {
        return rtrim((string) $this->baseUrl, '/')."/{$this->version}";
    }

    /**
     * The token cache is scoped to the client id so that switching credentials
     * (e.g. via setKey()) never re-uses another account's token.
     */
    protected function tokenCacheKey(): string
    {
        return 'ego-paypal-token-'.md5((string) $this->clientId);
    }

    public function getAuthToken(): string
    {
        $token = Cache::get($this->tokenCacheKey());

        if (empty($token)) {
            $token = $this->getAuthTokenFromApi();
        }

        return $this->token = $token;
    }

    public function getAuthTokenFromApi(): string
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withBasicAuth((string) $this->clientId, (string) $this->secret)
                ->post(rtrim((string) $this->baseUrl, '/').'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $e) {
            throw new ApiException('Unable to connect to PayPal: '.$e->getMessage());
        }

        $token = $response->json('access_token');

        if ($response->failed() || empty($token)) {
            throw new ApiException("PayPal authentication failed ({$response->status()}): {$response->body()}");
        }

        // Expire our copy slightly before PayPal does so we never send a stale token.
        $ttl = max(((int) $response->json('expires_in', 0)) - 60, 0);
        if ($ttl > 0) {
            Cache::put($this->tokenCacheKey(), $token, $ttl);
        }

        return $token;
    }

    public function clearAuthToken(): void
    {
        Cache::forget($this->tokenCacheKey());
        $this->token = null;
    }

    public function setMerchantId(?string $merchantId): void
    {
        $this->merchantId = $merchantId;
        $this->builder['merchant_id'] = $merchantId;
    }

    public function buildRequest(): void
    {
        $this->token = $this->getAuthToken();
        $this->http = Http::withToken($this->token)->acceptJson();
    }

    protected function makeRequest(
        string $method,
        string $endpoint,
        ?array $data = [],
        array $header = []
    ): array {
        $defaultHeaders = array_filter([
            'PayPal-Partner-Attribution-Id' => $this->bnCode,
            'PayPal-Request-Id' => Str::uuid()->toString(),
            'PayPal-Auth-Assertion' => $this->merchantId
                ? $this->generatePayPalAuthAssertion($this->merchantId)
                : null,
        ]);

        $this->buildRequest();
        $httpClient = $this->http
            ->withHeaders(array_merge($defaultHeaders, $header))
            ->timeout(30);

        try {
            $response = match (strtoupper($method)) {
                'GET' => $httpClient->get($endpoint, $data),
                // An empty array would be encoded as "[]"; PayPal expects "{}" for empty bodies.
                'POST' => $httpClient->post($endpoint, empty($data) ? (object) [] : $data),
                'PUT' => $httpClient->put($endpoint, $data ?? []),
                'PATCH' => $httpClient->patch($endpoint, $data ?? []),
                'DELETE' => $httpClient->delete($endpoint, $data ?? []),
                default => throw new Exception("Unsupported HTTP method: {$method}")
            };
        } catch (ConnectionException $e) {
            throw new ApiException('Unable to connect to PayPal: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            // Token was revoked/expired early; make sure the next call fetches a fresh one.
            $this->clearAuthToken();
        }

        if ($response->failed()) {
            throw new ApiException("PayPal API Error ({$response->status()}): {$response->body()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Generate the PayPal-Auth-Assertion header value.
     *
     * @param  string  $merchantPayerId The merchant's PayPal payer_id (the merchant you're acting on behalf of)
     * @param  bool  $signed Whether to sign the JWT using your client secret (optional)
     * @return string The complete PayPal-Auth-Assertion header value
     */
    public function generatePayPalAuthAssertion(
        string $merchantPayerId,
        bool $signed = false
    ): string {
        $header = ['alg' => $signed ? 'HS256' : 'none'];

        $payload = [
            'iss' => $this->clientId,
            'payer_id' => $merchantPayerId,
        ];

        $base64UrlEncode = fn (string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        $encodedHeader = $base64UrlEncode(json_encode($header));
        $encodedPayload = $base64UrlEncode(json_encode($payload));

        $encodedSignature = $signed
            ? $base64UrlEncode(hash_hmac('sha256', "$encodedHeader.$encodedPayload", (string) $this->secret, true))
            : '';

        return "$encodedHeader.$encodedPayload.$encodedSignature";
    }

    /**
     * Find a HATEOAS link in a PayPal response by its "rel".
     */
    protected function findLink(array $response, array $rels): ?string
    {
        foreach ($rels as $rel) {
            foreach ($response['links'] ?? [] as $link) {
                if (($link['rel'] ?? null) === $rel) {
                    return $link['href'] ?? null;
                }
            }
        }

        return null;
    }

    protected function mapStatus(array $response): string
    {
        $captureStatus = $response['purchase_units'][0]['payments']['captures'][0]['status'] ?? null;

        if (in_array($captureStatus, ['DECLINED', 'FAILED'], true)) {
            return 'FAILED';
        }

        return match ($response['status'] ?? null) {
            // An APPROVED order has not been captured yet, so no money has moved.
            'COMPLETED' => $captureStatus === 'PENDING' ? 'PENDING' : 'SUCCESS',
            'VOIDED' => 'FAILED',
            default => 'PENDING',
        };
    }
}
