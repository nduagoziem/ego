<?php

namespace Emmy\Ego\Gateway\BudPay;

use Emmy\Ego\Exception\ApiException;
use Emmy\Ego\Gateway\Realm\Tollgate;
use Emmy\Ego\Interface\PaymentGatewayInterface;
use Emmy\Ego\Trait\Http;
use Emmy\Ego\Trait\Webhooker;
use Illuminate\Http\Request;

class BudPay extends Tollgate implements PaymentGatewayInterface
{
    use Http, Webhooker;

    protected $secretKey;

    protected $publicKey;

    protected $baseUrl = 'https://api.budpay.com/';

    public function __construct()
    {
        $this->secretKey ??= config('ego.credentials.budpay.secret_key');
        $this->publicKey ??= config('ego.credentials.budpay.public_key');
    }

    /**
     * @inheritDoc
     */
    public function setKey(string|array $key): void
    {
        $this->secretKey = $key;
    }

    public function checkForError(array $response): void
    {
        if (
            (isset($response['status']) && $response['status'] != true) ||
            (isset($response['success']) && $response['success'] != true)
        ) {
            throw new ApiException(json_encode($response));
        }
    }
    /**
     * @inheritDoc`
     */
    public function prepareForPayment(array $data): array
    {
        $email = searchArray('email', $data);
        $amount = searchArray('amount', $data);
        $currency = searchArray('currency', $data);
        $firstName = searchArray("first_name", $data) ?? searchArray("firstName", $data);
        $lastName = searchArray("last_name", $data) ?? searchArray("lastName", $data);
        $reference = searchArray('reference', $data);
        $callbackUrl = searchArray('callback', $data) ?? searchArray('callbackUrl', $data) ?? searchArray('callback_url', $data);

        $this->setEmail($email);
        $this->setAmount($amount);
        if ($currency) {
            $this->setCurrency($currency);
        }
        if ($firstName) {
            $this->setFirstName($firstName);
        }
        if ($lastName) {
            $this->setLastName($lastName);
        }
        if ($reference) {
            $this->setReference($reference);
        }
        $this->setCallbackURL($callbackUrl);

        return $this->builder;
    }

    /**
     * @inheritDoc
     */
    public function pay(array $array): array
    {
        $payload = $this->buildPayload($array);
        $response = $this->post('api/v2/transaction/initialize', $payload);

        return [
            "status" => true,
            "url" => $response["data"]["authorization_url"],
            "message" => "Payment URL created",
            'api_message' => $response,
        ];
    }

    /**
     * @inheritDoc
     *  Fetch all supported banks.
     */
    public function getBanks(string $bankcode = ''): array
    {
        return $this->get("api/v2/bank_list");
    }

    /**
     * Fetch supported banks via their country's currency codes.
     * 
     * Check BudPay docs for supported countries.
     * 
     * @see https://developer.budpay.com/making-payment/bank-list
     * 
     * @param string $currencyCode
     * 
     * @return array
     */
    public function getBanksByCurrency(string $currencyCode = "NGN"): array
    {
        return $this->get("api/v2/bank_list/{$currencyCode}");
    }

    /**
     * @inheritDoc
     * Verify the recipient's account exists.
     */
    public function verifyAccountNumber(array $request): array
    {
        $payload = $this->buildPayload($request);
        return $this->post("api/v2/account_name_verify", $payload);
    }

    /**
     * Calculate budpay transfer fees before making transfers.
     * 
     * @see https://developer.budpay.com/making-payment/payout-fees#fee-calculation-examples for more info.
     * 
     * @param array $data
     * 
     * @return array
     */
    public function calculateTransferFee(array $data): array
    {
        $currency = searchArray("currency", $data);
        $amount = searchArray("amount", $data);

        $this->setCurrency($currency);
        $this->setAmount($amount);

        return $this->post("api/v2/payout_fee", $this->buildPayload($data));
    }

    /**
     * @inheritDoc
     * Prepare for a 'single' budpay transfer.
     */
    public function prepareForTransfer(array $data): array
    {
        $currency = searchArray("currency", $data);
        $amount = searchArray("amount", $data);
        $accountNumber = searchArray("account_number", $data);
        $bankCode = searchArray("bank_code", $data);
        $narration = searchArray("narration", $data);
        $bankName = searchArray("bank_name", $data);
        $metadata = searchArrayAsArray("meta_data", $data);
        $paymentMode = searchArray("payment_mode", $data); // Available for Kenya only at the time of building.

        $this->setCurrency($currency);
        $this->setAmount($amount);
        $this->setBankCode($bankCode);
        $this->setNarration($narration);
        $this->setAccountNumber($accountNumber);
        $this->setBankName($bankName);
        if ($metadata) {
            $this->setMetadata($metadata);
        }
        if ($paymentMode) {
            $this->setPaymentMode($paymentMode);
        }

        return $this->builder;
    }

    /**
     * Prepare for a 'bulk' budpay transfer.
     * @param array $data
     * @return array
     */
    public function prepareForBulkTransfer(array $data): array
    {
        $currency = searchArray("currency", $data);
        $transfers = searchArrayAsArray("transfers", $data);

        $this->setCurrency($currency);
        $this->setTransfers($transfers);

        return $this->builder;
    }


    /**
     * @inheritDoc
     * Handles 'single' budpay transfers.
     */
    public function transfer(array $data): array
    {
        $payload = $this->buildPayload($data);

        ksort($payload);

        $hmacSignature = hash_hmac(
            'sha512',
            json_encode($payload),
            $this->publicKey
        );

        return $this->post('api/v2/bank_transfer', $payload, [
            'Encryption' => $hmacSignature,
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Handles 'bulk' budpay transfers.
     * @param array $data
     * @return array
     */
    public function bulkTransfer(array $data): array
    {
        $payload = $this->buildPayload($data);

        ksort($payload);

        $hmacSignature = hash_hmac(
            'sha512',
            json_encode($payload),
            $this->publicKey
        );

        return $this->post('api/v2/bulk_bank_transfer', $payload, [
            'Encryption' => $hmacSignature,
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Verifies if a transfer is successful or not - both single and bulk transfers.
     * @param string $transferReference
     * @return array
     */
    public function verifyTransfer(string $transferReference): array
    {
        return $this->get("api/v2/payout/{$transferReference}");
    }

    /**
     * @inheritDoc
     */
    public function verifyPayment(array|string $budPayReference, ?string $paymentType = null): array
    {

        if ($paymentType !== null && $paymentType !== 'transaction') {
            throw new \InvalidArgumentException("Invalid Payment type.\n Payment type should be 'transaction'");
        }

        $route = $paymentType ?? 'transaction';

        $reference = \is_array($budPayReference) ? ($budPayReference['data']['reference'] ?? $budPayReference['reference'] ?? null)
            : $budPayReference;

        if (!$reference) {
            throw new \InvalidArgumentException('Payment reference is required');
        }

        return $this->get("api/v2/{$route}/verify/{$reference}");
    }

    /**
     * @inheritDoc
     */
    public function verifyWebhook(Request $request): void
    {
        if (!$this->shouldValidateWebhook()) {
            return;
        };

        if (!$request->isMethod('POST')) {
            abort(401);
        }

        $event = strtolower((string) $request->input('notify'));
        $reference = $request->input('data.reference')
            ?? $request->input('transferDetails.paymentReference')
            ?? $request->input('reference');

        if (!$event || !$reference || !\in_array($event, ['transaction', 'virtual_account', 'payout'], true)) {
            abort(401);
        }

        try {
            $verification = $event === 'payout'
                ? $this->verifyTransfer($reference)
                : $this->verifyPayment($reference);
        } catch (\Throwable) {
            abort(401, "Webhook not verified");
        }

        $verifiedReference = $verification['data']['reference'] ?? $verification['reference'] ?? null;
        if (!$verifiedReference || !hash_equals((string) $reference, (string) $verifiedReference)) {
            abort(401, "Webhook not verified");
        }

        $webhookStatus = $request->input('data.status');
        $verifiedStatus = $verification['data']['status'] ?? $verification['status'] ?? null;
        if ($webhookStatus && $verifiedStatus && !hash_equals((string) $webhookStatus, (string) $verifiedStatus)) {
            abort(401, "Webhook not verified");
        }
    }
}
