<picture>
  <source media="(prefers-color-scheme: dark)" srcset=".github/img/dark.png">
  <source media="(prefers-color-scheme: light)" srcset=".github/img/light.png">
  <img alt="Obsidian" src=".github/img/light.png">
</picture>

<p align="center">
<a href="https://github.com/zenphporg/obsidian/blob/main/coverage.xml"><img src="https://img.shields.io/badge/dynamic/xml?color=success&label=coverage&query=round%28%2F%2Fcoverage%2Fproject%2Fmetrics%2F%40coveredelements%20div%20%2F%2Fcoverage%2Fproject%2Fmetrics%2F%40elements%20%2A%20100%29&suffix=%25&url=https%3A%2F%2Fraw.githubusercontent.com%2Fzenphporg%2Fobsidian%2Fmain%2Fcoverage.xml" alt="Coverage"></a>
<a href="https://github.com/zenphporg/obsidian/actions"><img src="https://img.shields.io/github/actions/workflow/status/zenphporg/obsidian/main.yml?branch=main" alt="Build Status"></a>
<a href="https://packagist.org/packages/zenphp/obsidian"><img src="https://img.shields.io/packagist/dt/zenphp/obsidian" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/zenphp/obsidian"><img src="https://img.shields.io/packagist/v/zenphp/obsidian" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/zenphp/obsidian"><img src="https://img.shields.io/packagist/l/zenphp/obsidian.svg" alt="License"></a>
</p>

# About Obsidian

Obsidian provides expressive, fluent subscription billing for Laravel applications using **CCBill** and **SegPay** payment processors. Built specifically for adult content platforms and high-risk merchants who need reliable, compliant payment processing.

## Gateway Support Status

| Gateway | Status | Subscriptions | One-Time Charges | Webhooks | Cancellation |
|---------|--------|---------------|------------------|----------|--------------|
| CCBill  | ✅ Implemented | ✅ | ✅ | ✅ | ✅ (via DataLink) |
| SegPay  | 🚧 Planned | ❌ | ❌ | ❌ | ❌ |
| Fake    | ✅ Implemented | ✅ | ✅ | ✅ | ✅ |

> **Note:** SegPay integration is planned for a future release. Currently, all SegPay gateway methods will throw a `GatewayException`.

## Features

- **Multiple Payment Gateways** - Support for CCBill with SegPay planned
- **Subscription Management** - Create, cancel, and manage recurring subscriptions
- **Trial Periods** - Built-in support for trial subscriptions
- **Webhook Handling** - Automatic webhook processing with signature validation
- **One-Time Charges** - Process single payments alongside subscriptions
- **Fake Gateway** - Test your billing logic without hitting real APIs
- **100% Test Coverage** - Fully tested with comprehensive mocked responses
- **Type Safe** - Full PHP 8.4 type coverage with PHPStan level max

## Requirements

- PHP 8.4 or higher
- Laravel 12.0 or higher
- A CCBill merchant account (SegPay support coming soon)

## Installation

Install the package via Composer:

```bash
composer require zenphp/obsidian
```

### Publish Configuration

Publish the configuration file and migrations:

```bash
php artisan vendor:publish --tag=obsidian-config
php artisan vendor:publish --tag=obsidian-migrations
```

Run the migrations:

```bash
php artisan migrate
```

### Environment Configuration

Add your payment gateway credentials to your `.env` file:

```env
# Default Gateway
OBSIDIAN_GATEWAY=ccbill

# CCBill Configuration
CCBILL_MERCHANT_ID=your_merchant_id
CCBILL_SUBACCOUNT_ID=your_subaccount_id
CCBILL_MERCHANT_APP_ID=your_merchant_application_id
CCBILL_SECRET_KEY=your_secret_key
CCBILL_DATALINK_USERNAME=your_datalink_username
CCBILL_DATALINK_PASSWORD=your_datalink_password
CCBILL_WEBHOOK_SECRET=your_webhook_secret

# Currency Settings
OBSIDIAN_CURRENCY=usd
OBSIDIAN_CURRENCY_LOCALE=en
```

### CCBill Requirements

To use the CCBill gateway, you'll need:

1. **Merchant Application ID & Secret Key** - For OAuth 2.0 authentication with the CCBill REST API
2. **DataLink Credentials** - Username and password for subscription cancellation via the legacy DataLink system
3. **Webhook Secret** - For validating incoming webhook signatures (HMAC SHA256)
4. **FlexForms** - A configured FlexForm for payment page generation

> **Important:** CCBill uses OAuth 2.0 for API authentication. The access token is automatically cached and refreshed as needed.

## Setup

### Add the Billable Trait

Add the `Billable` trait to your `User` model (or any model that should have subscriptions):

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Zen\Obsidian\Billable;

class User extends Authenticatable
{
    use Billable;

    // ... rest of your model
}
```

### Database Columns

The migrations will add the following columns to your users table:

- `trial_ends_at` - For generic trial periods (optional)

And create the following tables:

- `subscriptions` - Stores subscription records
- `subscription_items` - Stores subscription line items (for future use)

## Usage

### Creating Subscriptions

Create a new subscription for a user:

```php
use Illuminate\Http\Request;

Route::post('/subscribe', function (Request $request) {
    $user = $request->user();

    $subscription = $user->newSubscription('default', 'plan_monthly')
        ->create($request->payment_token);

    return redirect('/dashboard');
});
```

### With Trial Period

Add a trial period to a subscription:

```php
$subscription = $user->newSubscription('default', 'plan_monthly')
    ->trialDays(14)
    ->create($request->payment_token);
```

### Specify Gateway

Choose a specific payment gateway:

```php
$subscription = $user->newSubscription('default', 'plan_monthly')
    ->gateway('segpay')
    ->create($request->payment_token);
```

### With Metadata

Attach custom metadata to a subscription:

```php
$subscription = $user->newSubscription('default', 'plan_monthly')
    ->withMetadata([
        'user_ip' => $request->ip(),
        'referral_code' => 'SUMMER2024',
    ])
    ->create($request->payment_token);
```

### Checking Subscription Status

Check if a user has an active subscription:

```php
if ($user->subscribed('default')) {
    // User has an active subscription
}

// Check for a specific subscription name
if ($user->subscribed('premium')) {
    // User has an active premium subscription
}
```

### Check Trial Status

```php
if ($user->onTrial('default')) {
    // User is on trial
}
```

### Get Subscription

Retrieve a user's subscription:

```php
$subscription = $user->subscription('default');

if ($subscription && $subscription->active()) {
    // Subscription is active
}
```

### Cancelling Subscriptions

Cancel a subscription at the end of the billing period:

```php
$subscription = $user->subscription('default');
$subscription->cancel();
```

Cancel immediately:

```php
$subscription->cancelNow();
```

### One-Time Charges

Process a one-time payment:

```php
$result = $user->charge(2999, $paymentToken, [
    'description' => 'Premium content purchase',
]);

// Result contains:
// - transaction_id
// - amount
// - status
```

## Webhooks

Obsidian automatically handles webhooks from CCBill and SegPay to keep your subscription status in sync.

### Webhook URLs

Configure these webhook URLs in your payment processor dashboards:

- **CCBill**: `https://yourdomain.com/webhooks/ccbill`
- **SegPay**: `https://yourdomain.com/webhooks/segpay`

### Webhook Events

Obsidian dispatches the following events that you can listen to:

- `Zen\Obsidian\Events\SubscriptionCreated` - New subscription activated
- `Zen\Obsidian\Events\PaymentSucceeded` - Successful payment processed
- `Zen\Obsidian\Events\PaymentFailed` - Payment failed
- `Zen\Obsidian\Events\SubscriptionCancelled` - Subscription cancelled

### Listening to Events

Create an event listener:

```php
<?php

namespace App\Listeners;

use Zen\Obsidian\Events\PaymentSucceeded;

class SendPaymentReceipt
{
    public function handle(PaymentSucceeded $event): void
    {
        $subscription = $event->subscription;
        $amount = $event->amount;

        // Send receipt email to user
        $subscription->user->notify(new PaymentReceiptNotification($amount));
    }
}
```

Register in `EventServiceProvider`:

```php
protected $listen = [
    \Zen\Obsidian\Events\PaymentSucceeded::class => [
        \App\Listeners\SendPaymentReceipt::class,
    ],
];
```

## Testing

Obsidian includes a `FakeGateway` for testing your billing logic without hitting real payment APIs.

### Using the Fake Gateway

In your tests or local environment:

```php
// In your .env or test configuration
OBSIDIAN_GATEWAY=fake

// In your test
$subscription = $user->newSubscription('default', 'plan_monthly')
    ->gateway('fake')
    ->create('fake_token_123');

expect($subscription->active())->toBeTrue();
```

### Mocking HTTP Responses

For testing with real gateways, use Laravel's HTTP fake:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.ccbill.com/*' => Http::response([
        'subscriptionId' => 'sub_123',
        'status' => 'active',
    ], 200),
]);

$subscription = $user->newSubscription('default', 'plan_monthly')
    ->gateway('ccbill')
    ->create('test_token');
```

### Running Tests

```bash
# Run all tests
composer test

# Run tests with coverage
composer test:coverage

# Run type coverage
composer test:types

# Run static analysis
composer test:static
```

## API Reference

### Billable Trait Methods

The `Billable` trait provides the following methods:

#### `subscriptions()`

Get all subscriptions for the user.

```php
$subscriptions = $user->subscriptions;
```

#### `subscription(string $name = 'default')`

Get a specific subscription by name.

```php
$subscription = $user->subscription('premium');
```

#### `subscribed(string $name = 'default')`

Check if the user has an active subscription.

```php
if ($user->subscribed()) {
    // User is subscribed
}
```

#### `onTrial(string $name = 'default')`

Check if the user is on a trial period.

```php
if ($user->onTrial()) {
    // User is on trial
}
```

#### `onGenericTrial()`

Check if the user is on a generic trial (not tied to a subscription).

```php
if ($user->onGenericTrial()) {
    // User has a generic trial
}
```

#### `newSubscription(string $name, string $plan)`

Start building a new subscription.

```php
$builder = $user->newSubscription('default', 'plan_monthly');
```

#### `charge(int $amount, string $token, array $options = [])`

Process a one-time charge.

```php
$result = $user->charge(2999, 'payment_token');
```

### Subscription Methods

#### `active()`

Check if the subscription is active.

```php
if ($subscription->active()) {
    // Subscription is active
}
```

#### `cancelled()`

Check if the subscription has been cancelled.

```php
if ($subscription->cancelled()) {
    // Subscription is cancelled
}
```

#### `expired()`

Check if the subscription has expired.

```php
if ($subscription->expired()) {
    // Subscription has expired
}
```

#### `onTrial()`

Check if the subscription is on a trial period.

```php
if ($subscription->onTrial()) {
    // Subscription is on trial
}
```

#### `cancel()`

Cancel the subscription at the end of the billing period.

```php
$subscription->cancel();
```

#### `cancelNow()`

Cancel the subscription immediately.

```php
$subscription->cancelNow();
```

## Gateway Configuration

### CCBill

CCBill uses OAuth 2.0 for API authentication and supports:

- Payment token charging for subscriptions and one-time payments
- Subscription cancellation via DataLink (legacy CGI system)
- Webhook events with HMAC SHA256 signature validation
- ISO 4217 numeric currency codes (USD=840, EUR=978, GBP=826, etc.)

**Webhook Events Supported:**

| CCBill Event | Normalized Type |
|--------------|-----------------|
| `NewSaleSuccess` | `subscription.created` |
| `NewSaleFailure` | `subscription.failed` |
| `RenewalSuccess` | `payment.succeeded` |
| `RenewalFailure` | `payment.failed` |
| `Cancellation` | `subscription.cancelled` |
| `Chargeback` | `subscription.chargeback` |
| `Refund` | `payment.refunded` |
| `Expiration` | `subscription.expired` |

### SegPay

> **🚧 Coming Soon:** SegPay integration is planned for a future release. All SegPay gateway methods currently throw a `GatewayException` with the message "SegPay gateway is not yet implemented".

### Fake Gateway

The `FakeGateway` is perfect for testing and development:

- No external API calls
- Instant responses
- Predictable behavior
- Static state storage for test assertions
- `shouldFail()` method for simulating failures
- `reset()` method for test isolation

```php
use Zen\Obsidian\Gateways\FakeGateway;

// Reset state between tests
FakeGateway::reset();

// Simulate a failure
FakeGateway::shouldFail('Payment declined', 402);

// Access internal state
$subscriptions = FakeGateway::getSubscriptions();
$charges = FakeGateway::getCharges();
```

## Security

### Webhook Signature Validation

All webhooks are validated using HMAC SHA256 signatures to ensure they come from your payment processor.

Configure your webhook secrets in `.env`:

```env
CCBILL_WEBHOOK_SECRET=your_secret_here
SEGPAY_WEBHOOK_SECRET=your_secret_here
```

### Redirect URL Validation

The `VerifyRedirectUrl` middleware prevents open redirect vulnerabilities by ensuring redirect URLs match your application's host.

## Troubleshooting

### Webhooks Not Working

1. Verify webhook URLs are configured correctly in your payment processor dashboard
2. Check webhook secrets match between your `.env` and processor settings
3. Review logs for signature validation errors
4. Ensure your application is accessible from the internet (use ngrok for local testing)

### Subscription Not Activating

1. Check that the subscription exists in your database
2. Verify the `gateway_subscription_id` matches the processor's ID
3. Review webhook logs to ensure events are being received
4. Check that the subscription status is being updated correctly

### Payment Failures

1. Verify API credentials are correct in `.env`
2. Check that payment tokens are valid and not expired
3. Review gateway-specific error messages in logs
4. Ensure your merchant account is active and in good standing

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](https://github.com/zenphporg/obsidian/security/policy) on how to report security vulnerabilities.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- Built by Jetstream Labs
- Inspired by Laravel Cashier
- Designed for adult content platforms and high-risk merchants

## Support

- **Documentation**: Coming soon ...
- **Issues**: [GitHub Issues](https://github.com/zenphporg/obsidian/issues)
