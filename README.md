# upi-gateway

> Provider-agnostic PHP library for UPI QR code generation and payment status polling.
> Switch payment gateways — and environments — with a single config change.

[![Latest Version](https://img.shields.io/packagist/v/phpcorelab/upi-gateway)](https://packagist.org/packages/PHPCoreLab/upi-gateway)
[![PHP Version](https://img.shields.io/packagist/php-v/phpcorelab/upi-gateway)](https://packagist.org/packages/PHPCoreLab/upi-gateway)
[![License](https://img.shields.io/packagist/l/phpcorelab/upi-gateway)](LICENSE)

## Installation

```bash
composer require phpcorelab/upi-gateway
```

Requires PHP 8.1+.

## Supported Providers

| Provider | Sandbox URL | Live URL | QR | Poll | Refund | Webhook |
|----------|-------------|----------|----|------|--------|---------|
| Razorpay | api.razorpay.com (test keys) | api.razorpay.com (live keys) | ✅ | ✅ | ✅ | ✅ |
| PhonePe  | api-preprod.phonepe.com | api.phonepe.com | ✅ | ✅ | ✅ | ✅ |
| Paytm    | pguat.paytm.io | securegw.paytm.in | 🚧 | 🚧 | 🚧 | 🚧 |

> **Razorpay note:** Razorpay uses the same base URL for both environments.
> Sandbox mode is activated by using test-mode API keys (`rzp_test_*`).
> Live mode uses production keys (`rzp_live_*`).

## Configuration

Provide **both** sandbox and live credentials upfront. The active environment
determines which set is used at runtime — no code changes needed when going live.

```php
use PHPCoreLab\UpiGateway\UpiGateway;
use PHPCoreLab\UpiGateway\Core\GatewayConfig;

$gateway = new UpiGateway(
    GatewayConfig::fromArray([
        'active_provider' => 'razorpay',  // change from admin panel to switch provider
        'environment'     => 'sandbox',   // 'sandbox' | 'live'  ← change to go live
        'providers' => [
            'razorpay' => [
                'sandbox_key_id'     => $_ENV['RAZORPAY_SANDBOX_KEY_ID'],
                'sandbox_key_secret' => $_ENV['RAZORPAY_SANDBOX_KEY_SECRET'],
                'live_key_id'        => $_ENV['RAZORPAY_LIVE_KEY_ID'],
                'live_key_secret'    => $_ENV['RAZORPAY_LIVE_KEY_SECRET'],
            ],
            'phonepe' => [
                'sandbox_merchant_id' => $_ENV['PHONEPE_SANDBOX_MERCHANT_ID'],
                'sandbox_salt_key'    => $_ENV['PHONEPE_SANDBOX_SALT_KEY'],
                'sandbox_salt_index'  => $_ENV['PHONEPE_SANDBOX_SALT_INDEX'] ?? 1,
                'live_merchant_id'    => $_ENV['PHONEPE_LIVE_MERCHANT_ID'],
                'live_salt_key'       => $_ENV['PHONEPE_LIVE_SALT_KEY'],
                'live_salt_index'     => $_ENV['PHONEPE_LIVE_SALT_INDEX'] ?? 1,
            ],
            'paytm' => [
                'sandbox_mid'          => $_ENV['PAYTM_SANDBOX_MID'],
                'sandbox_merchant_key' => $_ENV['PAYTM_SANDBOX_MERCHANT_KEY'],
                'live_mid'             => $_ENV['PAYTM_LIVE_MID'],
                'live_merchant_key'    => $_ENV['PAYTM_LIVE_MERCHANT_KEY'],
            ],
        ],
    ])
);
```

### Shorthand (single key pair)

If you only have one set of credentials (e.g. during initial development),
you can use the plain `key_id` / `key_secret` keys and the adapter will fall back to them:

```php
'razorpay' => [
    'key_id'     => $_ENV['RAZORPAY_KEY_ID'],
    'key_secret' => $_ENV['RAZORPAY_KEY_SECRET'],
],
```

## Usage

### 1. Generate a UPI QR code

```php
use PHPCoreLab\UpiGateway\DTOs\OrderPayload;

$order = new OrderPayload(
    orderId:       'ORD-2024-001',
    amountPaisa:   49900,           // INR 499.00 — always in paise
    customerName:  'Rahul Sharma',
    customerPhone: '9876543210',
);

$qr = $gateway->createQr($order);
echo $qr->transactionId;  // store this for polling
echo $qr->qrString;       // render as QR image
```

### 2. Poll for payment status

**Synchronous:**

```php
use PHPCoreLab\UpiGateway\DTOs\PaymentState;

$status = $gateway->pollUntilDone(
    transactionId: $qr->transactionId,
    intervalMs:    3000,
    maxAttempts:   20,
    backoff:       'exponential',
    onTick: fn($s, $i) => logger()->debug("Poll #{$i}: {$s->state->value}"),
);

if ($status->state === PaymentState::Success) {
    // fulfil order
}
```

**Async / queue-based (recommended for production):**

```php
// In your background job:
$status = $gateway->checkStatus($transactionId);
if ($status->isTerminal()) {
    // handle success / failure
}
// Otherwise re-queue with a delay.
```

### 3. Handle webhooks

```php
$status = $gateway->handleWebhook(
    rawBody: file_get_contents('php://input'),
    headers: getallheaders(),
);
// WebhookVerificationException thrown on bad signature.
```

### 4. Switch provider or environment at runtime

```php
// Change provider (e.g. from admin panel):
$gateway->switchProvider('phonepe');

// Check current environment:
echo $gateway->getEnvironment()->value; // 'sandbox' or 'live'
```

> **Important:** Never switch to `live` environment at runtime in a test or staging context.
> The environment should be set via config and treated as deployment-level configuration.

## Adding a Custom Provider

```php
use PHPCoreLab\UpiGateway\Contracts\UpiProviderInterface;
use PHPCoreLab\UpiGateway\Enums\Environment;

class CashfreeAdapter implements UpiProviderInterface
{
    public function __construct(array $config, Environment $environment) { ... }
    // implement generateQr, checkStatus, refund, parseWebhook, getName
}

$gateway->registerProvider('cashfree', new CashfreeAdapter($config, $gateway->getEnvironment()));
$gateway->switchProvider('cashfree');
```

## DTOs

| Class | Key Properties |
|-------|----------------|
| `OrderPayload` | `orderId`, `amountPaisa`, `currency`, `customerName`, `customerPhone` |
| `QrResult` | `transactionId`, `qrString`, `qrImageUrl`, `expiresAt`, `raw` |
| `PaymentStatus` | `transactionId`, `state` (enum), `amountPaisa`, `providerRef`, `failureReason` |
| `RefundResult` | `refundId`, `success`, `message`, `raw` |

`PaymentState` enum: `Pending · Success · Failed · Expired · Refunded`

`Environment` enum: `Sandbox · Live`

## Exceptions

| Exception | When thrown |
|-----------|-------------|
| `ProviderException` | HTTP / API call to provider fails |
| `WebhookVerificationException` | Signature or checksum mismatch |
| `ProviderNotFoundException` | Provider name not registered |

All extend `UpiGatewayException extends RuntimeException`.

## Running Tests

```bash
composer install
composer test
composer analyse   # PHPStan level 8
```


## License

MIT
