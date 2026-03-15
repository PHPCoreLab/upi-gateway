<?php

declare(strict_types=1);

namespace PHPCoreLab\UpiGateway\Adapters\Razorpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPCoreLab\UpiGateway\Contracts\UpiProviderInterface;
use PHPCoreLab\UpiGateway\DTOs\OrderPayload;
use PHPCoreLab\UpiGateway\DTOs\PaymentState;
use PHPCoreLab\UpiGateway\DTOs\PaymentStatus;
use PHPCoreLab\UpiGateway\DTOs\QrResult;
use PHPCoreLab\UpiGateway\DTOs\RefundResult;
use PHPCoreLab\UpiGateway\Enums\Environment;
use PHPCoreLab\UpiGateway\Exceptions\ProviderException;
use PHPCoreLab\UpiGateway\Exceptions\WebhookVerificationException;

final class RazorpayAdapter implements UpiProviderInterface
{
    /**
     * Razorpay uses the same API base URL for both sandbox and live.
     * Sandbox mode is activated by using test-mode API keys (prefix: rzp_test_).
     * Live mode uses production keys (prefix: rzp_live_).
     *
     * @see https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/
     */
    private const BASE_URL = 'https://api.razorpay.com/v1/';

    private Client $http;
    private string $keyId;
    private string $keySecret;
    private Environment $environment;

    public function __construct(array $config, Environment $environment = Environment::Sandbox)
    {
        $this->environment = $environment;

        // Automatically pick the right key pair based on environment
        if ($environment->isLive()) {
            $this->keyId     = $config['live_key_id']     ?? $config['key_id']     ?? throw new \InvalidArgumentException('razorpay live_key_id required for live environment');
            $this->keySecret = $config['live_key_secret'] ?? $config['key_secret'] ?? throw new \InvalidArgumentException('razorpay live_key_secret required for live environment');
        } else {
            $this->keyId     = $config['sandbox_key_id']     ?? $config['key_id']     ?? throw new \InvalidArgumentException('razorpay sandbox_key_id required');
            $this->keySecret = $config['sandbox_key_secret'] ?? $config['key_secret'] ?? throw new \InvalidArgumentException('razorpay sandbox_key_secret required');
        }

        $this->http = new Client([
            'base_uri' => self::BASE_URL,
            'auth'     => [$this->keyId, $this->keySecret],
            'headers'  => ['Content-Type' => 'application/json'],
            'timeout'  => 10,
        ]);
    }

    public function generateQr(OrderPayload $order): QrResult
    {
        try {
            $response = $this->http->post('payments/qr_codes', ['json' => [
                'type'           => 'upi_qr',
                'name'           => $order->customerName ?? 'Payment',
                'usage'          => 'single_use',
                'fixed_amount'   => true,
                'payment_amount' => $order->amountPaisa,
                'description'    => $order->orderId,
                'close_by'       => time() + 900,
            ]]);

            $data = json_decode((string) $response->getBody(), true);

            $upiString = sprintf(
                'upi://pay?pa=%s&pn=%s&am=%s&cu=INR&tr=%s',
                urlencode($config['vpa'] ?? 'yourmerchant@razorpay'), // your merchant VPA
                urlencode($config['merchant_name'] ?? 'Merchant'),
                number_format($order->amountPaisa / 100, 2, '.', ''),
                $data['id'] // QR code ID as transaction reference
            );

            return new QrResult(
                transactionId: $data['id'],
                qrString:      $upiString,
                qrImageUrl:    $data['image_url'],
                expiresAt:     $data['close_by'] ?? null,
                raw:           $data,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function checkStatus(string $transactionId): PaymentStatus
    {
        try {
            $response = $this->http->get("payments/qr_codes/{$transactionId}/payments");
            $data     = json_decode((string) $response->getBody(), true);

            $payment = $data['items'][0] ?? null;
            $state   = match ($payment['status'] ?? 'created') {
                'captured' => PaymentState::Success,
                'failed'   => PaymentState::Failed,
                default    => PaymentState::Pending,
            };

            return new PaymentStatus(
                transactionId: $transactionId,
                state:         $state,
                amountPaisa:   $payment['amount'] ?? null,
                providerRef:   $payment['id']     ?? null,
                raw:           $data,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function refund(string $transactionId, int $amountPaisa): RefundResult
    {
        try {
            $response = $this->http->post("payments/{$transactionId}/refund", [
                'json' => ['amount' => $amountPaisa],
            ]);
            $data = json_decode((string) $response->getBody(), true);

            return new RefundResult(
                refundId: $data['id'],
                success:  true,
                raw:      $data,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), previous: $e);
        }
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentStatus
    {
        $signature = $headers['X-Razorpay-Signature'] ?? '';
        $expected  = hash_hmac('sha256', $rawBody, $this->keySecret);

        if (!hash_equals($expected, $signature)) {
            throw new WebhookVerificationException('Razorpay webhook signature mismatch.');
        }

        $data    = json_decode($rawBody, true);
        $payment = $data['payload']['payment']['entity'] ?? [];

        $state = match ($data['event'] ?? '') {
            'payment.captured' => PaymentState::Success,
            'payment.failed'   => PaymentState::Failed,
            default            => PaymentState::Pending,
        };

        return new PaymentStatus(
            transactionId: $payment['id'] ?? '',
            state:         $state,
            amountPaisa:   $payment['amount'] ?? null,
            providerRef:   $payment['acquirer_data']['upi_transaction_id'] ?? null,
            raw:           $data,
        );
    }

    public function getEnvironment(): Environment
    {
        return $this->environment;
    }

    public function getName(): string
    {
        return 'razorpay';
    }
}
