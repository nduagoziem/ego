<?php

uses(\Tests\TestCase::class);

use Emmy\Ego\Exception\ApiException;
use Emmy\Ego\Exception\UnsupportedGatewayMethodExeption;
use Emmy\Ego\Factory\PaymentFactory;
use Emmy\Ego\Gateway\Paypal\Paypal;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

const PAYPAL_BASE = 'https://api-m.sandbox.paypal.com';
const PAYPAL_CERT_URL = 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-test';

beforeEach(function () {
    config([
        'ego.credentials.paypal.url' => PAYPAL_BASE,
        'ego.credentials.paypal.client_id' => 'test_client_id',
        'ego.credentials.paypal.client_secret' => 'test_client_secret',
        'ego.credentials.paypal.bn_code' => 'TEST_BN_CODE',
        'ego.credentials.paypal.paypal_webhook_id' => 'WH-TEST-123',
    ]);

    Cache::flush();
    Http::preventStrayRequests();
});

function paypalTokenResponse(): array
{
    return [
        PAYPAL_BASE.'/v1/oauth2/token' => Http::response([
            'access_token' => 'A21AAtest_access_token',
            'token_type' => 'Bearer',
            'expires_in' => 32400,
        ]),
    ];
}

function paypalOrderResponse(string $status = 'PAYER_ACTION_REQUIRED', string $reference = 'REF-1', ?string $captureStatus = null): array
{
    $unit = [
        'reference_id' => $reference,
        'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
    ];

    if ($captureStatus) {
        $unit['payments']['captures'][] = ['id' => 'CAPTURE-1', 'status' => $captureStatus];
    }

    return [
        'id' => '5O190127TN364715T',
        'status' => $status,
        'purchase_units' => [$unit],
        'links' => [
            ['href' => PAYPAL_BASE.'/v2/checkout/orders/5O190127TN364715T', 'rel' => 'self', 'method' => 'GET'],
            ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', 'rel' => 'payer-action', 'method' => 'GET'],
        ],
    ];
}

/**
 * Creates a self-signed certificate + private key so webhook signatures can be produced in tests.
 */
function paypalSigningPair(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'messageverificationcerts.paypal.com'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($cert, $certPem);

    return [$key, $certPem];
}

function paypalWebhookRequest(string $body, array $headers): Request
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/webhook/paypal', 'POST', [], [], [], $server, $body);
}

function signedPaypalWebhook(string $body, $privateKey, array $overrides = []): Request
{
    $headers = array_merge([
        'paypal-transmission-id' => '69cd13f0-d67a-11e5-baa3-778b53f4ae55',
        'paypal-transmission-time' => '2026-11-08T12:00:00Z',
        'paypal-cert-url' => PAYPAL_CERT_URL,
        'paypal-auth-algo' => 'SHA256withRSA',
    ], $overrides);

    $message = implode('|', [
        $headers['paypal-transmission-id'],
        $headers['paypal-transmission-time'],
        'WH-TEST-123',
        sprintf('%u', crc32($body)),
    ]);
    openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    $headers['paypal-transmission-sig'] ??= base64_encode($signature);

    return paypalWebhookRequest($body, $headers);
}

function expectAbort(callable $callback, int $status = 404): void
{
    try {
        $callback();
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe($status);

        return;
    }

    test()->fail("Expected the request to be aborted with {$status}.");
}

test('paypal gateway is registered with the factory', function () {
    $paymentFactory = new PaymentFactory('paypal');

    expect($paymentFactory->getGatewayInstance())->toBeInstanceOf(Paypal::class);
});

test('can make payment', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders' => Http::response(paypalOrderResponse(), 201),
    ]);

    $reference = (string) Str::uuid();
    $paymentFactory = new PaymentFactory('paypal');

    $prepare = $paymentFactory->prepareForPayment([
        'amount' => '100.00',
        'email' => 'buyer@example.com',
        'currency' => 'USD',
        'reference' => $reference,
        'callback_url' => 'https://example.com/callback',
    ]);

    $response = $paymentFactory->pay($prepare);

    expect($response['status'])->toBeTrue()
        ->and($response['url'])->toBe('https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T')
        ->and($response['id'])->toBe('5O190127TN364715T')
        ->and($response)->toHaveKey('api_message');

    Http::assertSent(function (ClientRequest $request) {
        return $request->url() === PAYPAL_BASE.'/v1/oauth2/token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test_client_id:test_client_secret'))
            && $request['grant_type'] === 'client_credentials';
    });

    Http::assertSent(function (ClientRequest $request) use ($reference) {
        if ($request->url() !== PAYPAL_BASE.'/v2/checkout/orders') {
            return false;
        }

        $unit = $request['purchase_units'][0];
        $paypal = $request['payment_source']['paypal'];

        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer A21AAtest_access_token')
            && $request->hasHeader('PayPal-Partner-Attribution-Id', 'TEST_BN_CODE')
            && $request->hasHeader('PayPal-Request-Id')
            && ! $request->hasHeader('PayPal-Auth-Assertion')
            && $request['intent'] === 'CAPTURE'
            && $unit['reference_id'] === $reference
            && $unit['amount'] === ['value' => '100.00', 'currency_code' => 'USD']
            && ! isset($unit['payee'])
            && $paypal['email_address'] === 'buyer@example.com'
            && $paypal['experience_context']['return_url'] === 'https://example.com/callback'
            && $paypal['experience_context']['cancel_url'] === 'https://example.com/callback';
    });
});

test('uses separate return and cancel urls when provided', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders' => Http::response(paypalOrderResponse(), 201),
    ]);

    $paymentFactory = new PaymentFactory('paypal');
    $paymentFactory->prepareForPayment([
        'amount' => 50,
        'email' => 'buyer@example.com',
        'currency' => 'USD',
        'reference' => 'REF-2',
        'return_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel',
    ]);
    $paymentFactory->pay();

    Http::assertSent(function (ClientRequest $request) {
        $context = $request['payment_source']['paypal']['experience_context'] ?? null;

        return $context
            && $context['return_url'] === 'https://example.com/success'
            && $context['cancel_url'] === 'https://example.com/cancel'
            && $request['purchase_units'][0]['amount']['value'] === '50';
    });
});

test('can make payment on behalf of a merchant', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders' => Http::response(paypalOrderResponse(), 201),
    ]);

    $paymentFactory = new PaymentFactory('paypal');
    $paymentFactory->prepareForPayment([
        'amount' => '20.00',
        'email' => 'buyer@example.com',
        'currency' => 'USD',
        'reference' => 'REF-3',
        'merchant_id' => 'MERCHANT123',
        'callback_url' => 'https://example.com/callback',
    ]);
    $paymentFactory->pay();

    Http::assertSent(function (ClientRequest $request) {
        if (! str_ends_with($request->url(), '/v2/checkout/orders')) {
            return false;
        }

        [$header, $payload, $signature] = explode('.', $request->header('PayPal-Auth-Assertion')[0]);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        return $request['purchase_units'][0]['payee'] === ['merchant_id' => 'MERCHANT123']
            && $claims === ['iss' => 'test_client_id', 'payer_id' => 'MERCHANT123']
            && $signature === '';
    });
});

test('custom purchase units and payment source are passed through', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders' => Http::response(paypalOrderResponse(), 201),
    ]);

    $purchaseUnits = [[
        'reference_id' => 'CUSTOM',
        'amount' => ['value' => '15.00', 'currency_code' => 'EUR'],
        'description' => 'Custom order',
    ]];
    $paymentSource = ['paypal' => ['experience_context' => ['user_action' => 'CONTINUE']]];

    $paymentFactory = new PaymentFactory('paypal');
    $paymentFactory->prepareForPayment([
        'amount' => '15.00',
        'currency' => 'EUR',
        'reference' => 'CUSTOM',
        'purchase_units' => $purchaseUnits,
        'payment_source' => $paymentSource,
    ]);
    $paymentFactory->pay();

    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['purchase_units'] === $purchaseUnits
        && $request['payment_source'] === $paymentSource);
});

test('access token is cached between requests', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders' => Http::response(paypalOrderResponse(), 201),
    ]);

    $data = ['amount' => '10.00', 'email' => 'buyer@example.com', 'currency' => 'USD', 'reference' => 'REF'];

    (new PaymentFactory('paypal'))->pay($data);
    (new PaymentFactory('paypal'))->pay($data);

    Http::assertSentCount(3);
    expect(Http::recorded(fn (ClientRequest $r) => str_ends_with($r->url(), '/oauth2/token')))->toHaveCount(1);
});

test('access token cache is scoped to the client credentials', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders' => Http::response(paypalOrderResponse(), 201),
    ]);

    $data = ['amount' => '10.00', 'email' => 'buyer@example.com', 'currency' => 'USD', 'reference' => 'REF'];

    (new PaymentFactory('paypal'))->pay($data);
    (new PaymentFactory('paypal', ['client_id' => 'other_client', 'client_secret' => 'other_secret']))->pay($data);

    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/oauth2/token')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('other_client:other_secret')));
    expect(Http::recorded(fn (ClientRequest $r) => str_ends_with($r->url(), '/oauth2/token')))->toHaveCount(2);
});

test('cannot make payment with invalid credentials', function () {
    Http::fake([
        PAYPAL_BASE.'/v1/oauth2/token' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'Client Authentication failed',
        ], 401),
    ]);

    $paymentFactory = new PaymentFactory('paypal', 'invalid_secret');

    expect(fn () => $paymentFactory->pay([
        'amount' => '10.00',
        'email' => 'buyer@example.com',
        'currency' => 'USD',
        'reference' => 'REF',
    ]))->toThrow(ApiException::class, 'PayPal authentication failed');

    expect(Cache::get('ego-paypal-token-'.md5('test_client_id')))->toBeNull();
});

test('throws api exception when order creation fails', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'CURRENCY_NOT_SUPPORTED']],
        ], 422),
    ]);

    $paymentFactory = new PaymentFactory('paypal');

    expect(fn () => $paymentFactory->pay([
        'amount' => '10.00',
        'currency' => 'XYZ',
        'reference' => 'REF',
    ]))->toThrow(ApiException::class, 'CURRENCY_NOT_SUPPORTED');
});

test('can verify a completed payment without capturing again', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders/5O190127TN364715T' => Http::response(paypalOrderResponse('COMPLETED', 'REF-1', 'COMPLETED')),
    ]);

    $paymentStatus = (new PaymentFactory('paypal'))->verifyPayment('5O190127TN364715T');

    expect($paymentStatus['status'])->toBe('SUCCESS')
        ->and($paymentStatus['reference'])->toBe('REF-1')
        ->and($paymentStatus)->toHaveKeys(['data', 'status', 'reference', 'message']);

    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/capture'));
});

test('captures an approved order when verifying payment', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders/5O190127TN364715T/capture' => Http::response(paypalOrderResponse('COMPLETED', 'REF-1', 'COMPLETED'), 201),
        PAYPAL_BASE.'/v2/checkout/orders/5O190127TN364715T' => Http::response(paypalOrderResponse('APPROVED')),
    ]);

    // PayPal appends ?token=<order id> to the return url, so the query array can be passed directly.
    $paymentStatus = (new PaymentFactory('paypal'))->verifyPayment(['token' => '5O190127TN364715T', 'PayerID' => 'PAYER1']);

    expect($paymentStatus['status'])->toBe('SUCCESS')
        ->and($paymentStatus['data']['status'])->toBe('COMPLETED');

    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/capture')
        && $request->method() === 'POST'
        && $request->body() === '{}'
        && $request->hasHeader('PayPal-Request-Id', 'ego-capture-5O190127TN364715T'));
});

test('payment awaiting payer action is pending', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders/5O190127TN364715T' => Http::response(paypalOrderResponse('PAYER_ACTION_REQUIRED')),
    ]);

    $paymentStatus = (new PaymentFactory('paypal'))->verifyTransaction('5O190127TN364715T');

    expect($paymentStatus['status'])->toBe('PENDING');
    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/capture'));
});

test('declined capture is reported as failed', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders/5O190127TN364715T' => Http::response(paypalOrderResponse('COMPLETED', 'REF-1', 'DECLINED')),
    ]);

    expect((new PaymentFactory('paypal'))->verifyPayment('5O190127TN364715T')['status'])->toBe('FAILED');
});

test('verifying an unknown order throws api exception', function () {
    Http::fake(paypalTokenResponse() + [
        PAYPAL_BASE.'/v2/checkout/orders/*' => Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404),
    ]);

    expect(fn () => (new PaymentFactory('paypal'))->verifyPayment('UNKNOWN'))
        ->toThrow(ApiException::class, 'RESOURCE_NOT_FOUND');
});

test('banking methods are not supported', function (string $method, array $args) {
    expect(fn () => (new PaymentFactory('paypal'))->$method(...$args))
        ->toThrow(UnsupportedGatewayMethodExeption::class);
})->with([
    'transfer' => ['transfer', [[]]],
    'getBanks' => ['getBanks', []],
    'verifyAccountNumber' => ['verifyAccountNumber', [[]]],
]);

test('can use magic methods to create payload', function () {
    $paymentFactory = new PaymentFactory('paypal');
    $paymentFactory->setAmount('10.00');
    $paymentFactory->setCurrency('USD');

    expect($paymentFactory->getPayload())->toMatchArray(['amount' => '10.00', 'currency' => 'USD']);
});

test('accepts a valid webhook signature', function () {
    [$privateKey, $certPem] = paypalSigningPair();
    Http::fake([PAYPAL_CERT_URL => Http::response($certPem)]);

    $body = json_encode(['id' => 'WH-EVENT-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED']);

    (new PaymentFactory('paypal'))->verifyWebhook(signedPaypalWebhook($body, $privateKey));

    expect(Cache::has('paypal_cert_'.md5(PAYPAL_CERT_URL)))->toBeTrue();
});

test('rejects a webhook whose body was tampered with', function () {
    [$privateKey, $certPem] = paypalSigningPair();
    Http::fake([PAYPAL_CERT_URL => Http::response($certPem)]);

    $request = signedPaypalWebhook('{"amount":"1.00"}', $privateKey);
    $tampered = paypalWebhookRequest('{"amount":"1000.00"}', array_map(
        fn ($values) => $values[0],
        array_intersect_key($request->headers->all(), array_flip([
            'paypal-transmission-id', 'paypal-transmission-time', 'paypal-cert-url',
            'paypal-auth-algo', 'paypal-transmission-sig',
        ]))
    ));

    expectAbort(fn () => (new PaymentFactory('paypal'))->verifyWebhook($tampered));
});

test('rejects a webhook signed with a certificate not hosted by paypal', function () {
    [$privateKey, $certPem] = paypalSigningPair();
    Http::fake(['*' => Http::response($certPem)]);

    $request = signedPaypalWebhook('{}', $privateKey, ['paypal-cert-url' => 'https://attacker.example.com/cert.pem']);

    expectAbort(fn () => (new PaymentFactory('paypal'))->verifyWebhook($request));
    Http::assertNothingSent();
});

test('rejects a webhook with an invalid signature', function () {
    [, $certPem] = paypalSigningPair();
    [$otherKey] = paypalSigningPair();
    Http::fake([PAYPAL_CERT_URL => Http::response($certPem)]);

    expectAbort(fn () => (new PaymentFactory('paypal'))->verifyWebhook(signedPaypalWebhook('{}', $otherKey)));
});

test('rejects a webhook with missing headers or unknown algorithm', function (array $headers) {
    expectAbort(fn () => (new PaymentFactory('paypal'))->verifyWebhook(paypalWebhookRequest('{}', $headers)));
    Http::assertNothingSent();
})->with([
    'no headers' => [[]],
    'unknown algorithm' => [[
        'paypal-transmission-id' => 'id',
        'paypal-transmission-time' => 'time',
        'paypal-cert-url' => PAYPAL_CERT_URL,
        'paypal-auth-algo' => 'MD5withRSA',
        'paypal-transmission-sig' => base64_encode('sig'),
    ]],
]);
