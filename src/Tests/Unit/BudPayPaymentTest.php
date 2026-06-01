<?php

uses(\Tests\TestCase::class);

use Emmy\Ego\Factory\PaymentFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'ego.credentials.budpay.secret_key' => 'test_secret_key',
        'ego.credentials.budpay.public_key' => 'test_public_key',
    ]);

    Http::preventStrayRequests();
});

test("can make payment", function () {
    Http::fake([
        'https://api.budpay.com/api/v2/transaction/initialize' => Http::response([
            'status' => true,
            'message' => 'Payment initialized',
            'data' => [
                'authorization_url' => 'https://checkout.budpay.com/test',
            ],
        ]),
    ]);

    $paymentFactory = new PaymentFactory("budpay");

    $prepare = $paymentFactory->prepareForPayment([
        "amount" => "100000",
        "email" => "test@example.com",
        'currency' => 'NGN',
        'callback' => 'http://goggle.com',
        'reference' => Str::uuid(),
    ]);

    $response = $paymentFactory->pay($prepare);

    $this->assertTrue($response['status']);
    $this->assertSame('https://checkout.budpay.com/test', $response['url']);
});

test("can get supported banks", function () {
    Http::fake([
        'https://api.budpay.com/api/v2/bank_list' => Http::response([
            'success' => true,
            'message' => 'Banks retrieved',
            'data' => [
                [
                    'bank_name' => 'GUARANTY TRUST BANK',
                    'bank_code' => '000013',
                ],
            ],
        ]),
    ]);

    $paymentFactory = new PaymentFactory("budpay");
    $banks = $paymentFactory->getBanks();

    $this->assertTrue($banks['success'], "Bank list retrieved");
});

test("can make transfer", function () {
    Http::fake([
        'https://api.budpay.com/api/v2/bank_transfer' => Http::response([
            'success' => true,
            'message' => 'Transfer successfully logged and Processing',
            'data' => [
                'reference' => 'trf_test_reference',
                'currency' => 'NGN',
                'amount' => '100',
                'bank_code' => '000013',
                'bank_name' => 'GUARANTY TRUST BANK',
                'account_number' => '0050883605',
                'status' => 'pending',
            ],
        ]),
    ]);

    $paymentFactory = new PaymentFactory("budpay");

    $prepare = $paymentFactory->prepareForTransfer([
        "amount" => "100",
        "bank_code" => "000013",
        "account_number" => "0050883605",
        'currency' => 'NGN',
        'bank_name' => 'GUARANTY TRUST BANK',
        'narration' => 'Test transfer',
        'reference' => Str::uuid(),
    ]);

    $response = $paymentFactory->transfer($prepare);

    $this->assertTrue($response['success']);
    $this->assertSame('pending', data_get($response, 'data.status'));
});

test("calculate transfer fee", function () {
    Http::fake([
        'https://api.budpay.com/api/v2/payout_fee' => Http::response([
            'success' => true,
            'message' => 'Fee calculated',
            'data' => [
                'currency' => 'NGN',
                'amount' => '100',
                'fee' => '10',
            ],
        ]),
    ]);

    $data = [
        "amount" => "100",
        "currency" => "NGN",
    ];

    $paymentFactory = new PaymentFactory("budpay");

    $response = $paymentFactory->calculateTransferFee($data);

    $this->assertTrue($response['success']);
    $this->assertSame('10', data_get($response, 'data.fee'));
});

test("can make bulk transfer", function () {
    Http::fake([
        'https://api.budpay.com/api/v2/bulk_bank_transfer' => Http::response([
            'success' => true,
            'message' => 'Bulk transfer successfully logged and Processing',
            'data' => [
                'reference' => 'bulk_trf_test_reference',
                'status' => 'pending',
            ],
        ]),
    ]);

    $paymentFactory = new PaymentFactory("budpay");

    $prepare = $paymentFactory->prepareForBulkTransfer([
        "currency" => "NGN",
        "transfers" => [
            [
                "amount" => "200",
                "bank_code" => "000013",
                "bank_name" => "GUARANTY TRUST BANK",
                "account_number" => "0050883605",
                "narration" => "January Salary"
            ],
            [
                "amount" => "100",
                "bank_code" => "000013",
                "bank_name" => "GUARANTY TRUST BANK",
                "account_number" => "0050883605",
                "narration" => "February Salary"
            ],
            [
                "amount" => "100",
                "bank_code" => "000013",
                "bank_name" => "GUARANTY TRUST BANK",
                "account_number" => "0050883605",
                "narration" => "March Salary"
            ],
        ]
    ]);

    $response = $paymentFactory->bulkTransfer($prepare);

    $this->assertTrue($response['success']);
    $this->assertSame('pending', data_get($response, 'data.status'));
});

test("can verify transfer", function () {
    Http::fake([
        'https://api.budpay.com/api/v2/payout/wdwdwdwd' => Http::response([
            'status' => true,
            'message' => 'Transfer verified',
            'data' => [
                'reference' => 'wdwdwdwd',
                'status' => 'success',
            ],
        ]),
    ]);

    $paymentFactory = new PaymentFactory("budpay");

    $response = $paymentFactory->verifyTransfer("wdwdwdwd");

    $this->assertTrue($response["status"]);
});

test("can verify payment", function () {
    Http::fake([
        'https://api.budpay.com/api/v2/transaction/verify/1780311387205904' => Http::response([
            'status' => true,
            'message' => 'Payment verified',
            'data' => [
                'reference' => '1780311387205904',
                'status' => 'success',
            ],
        ]),
    ]);

    $paymentFactory = new PaymentFactory("budpay");

    $response = $paymentFactory->verifyPayment("1780311387205904");

    $this->assertTrue($response["status"]);
});
