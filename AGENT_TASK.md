# Obsidian CCBill Integration Fix - Agent Task Document

## Executive Summary

This document provides complete instructions for fixing the CCBill payment gateway integration in the Obsidian package. The current implementation was built on assumptions about the CCBill API that don't match reality. This task involves:

1. Rewriting `CcbillGateway.php` to match the actual CCBill RESTful API
2. Updating `FakeGateway.php` to mirror the corrected interface
3. Updating all tests to reflect the new implementation
4. Marking SegPay as future work (WIP) in documentation
5. Ensuring 100% test coverage is maintained

---

## Project Context

**Package**: `zenphp/obsidian`  
**Purpose**: Laravel Cashier replacement for adult content platforms using CCBill and SegPay  
**Location**: `/Users/vince/WWW/dais/obsidian`  
**Quality Standards**: 100% test coverage, PHPStan level max, PHP 8.4+

---

## Part 1: CCBill API - Actual Implementation Details

### 1.1 Authentication

CCBill uses OAuth 2.0 with **two separate credential sets**:

- **Frontend credentials**: Used in browser/widget for payment token creation
- **Backend credentials**: Used server-to-server for charging tokens

**OAuth Token Endpoint:**
```
POST https://api.ccbill.com/ccbill-auth/oauth/token
Content-Type: application/x-www-form-urlencoded
Authorization: Basic {base64(merchant_app_id:secret_key)}

grant_type=client_credentials
```

**Response:**
```json
{
  "access_token": "eyJ...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

### 1.2 Charging a Payment Token (Creating a Subscription)

**Endpoint:**
```
POST https://api.ccbill.com/transactions/payment-tokens/{paymentTokenId}
```

**Required Headers:**
```
Accept: application/vnd.mcn.transaction-service.api.v.2+json
Authorization: Bearer {backend_access_token}
Cache-Control: no-cache
Content-Type: application/json
```

**Request Body:**
```json
{
  "clientAccnum": 900000,
  "clientSubacc": 1234,
  "initialPrice": 9.99,
  "initialPeriod": 30,
  "currencyCode": 840
}
```

**Currency Codes (numeric):**
- USD = 840
- EUR = 978
- GBP = 826
- CAD = 124
- AUD = 36
- JPY = 392

**Optional fields for recurring:**
```json
{
  "clientAccnum": 900000,
  "clientSubacc": 1234,
  "initialPrice": 9.99,
  "initialPeriod": 30,
  "recurringPrice": 19.99,
  "recurringPeriod": 30,
  "numRebills": 99,
  "currencyCode": 840
}
```

**Success Response:**
```json
{
  "declineCode": null,
  "declineText": null,
  "denialId": null,
  "approved": true,
  "paymentUniqueId": "xyz123",
  "sessionId": null,
  "subscriptionId": "0123456789",
  "newPaymentTokenId": null
}
```

**Error Response:**
```json
{
  "declineCode": 123,
  "declineText": "Card declined",
  "denialId": "abc123",
  "approved": false
}
```

### 1.3 Charging with 3DS Authentication

For 3DS-authenticated transactions, use a different endpoint:

**Endpoint:**
```
POST https://api.ccbill.com/transactions/payment-tokens/threeds/{paymentTokenId}
```

**Additional Required Fields:**
```json
{
  "clientAccnum": 900000,
  "clientSubacc": 1234,
  "initialPrice": 9.99,
  "initialPeriod": 30,
  "currencyCode": 840,
  "threedsEci": "05",
  "threedsStatus": "Y",
  "threedsSuccess": true,
  "threedsVersion": "2.2.0",
  "threedsAmount": 9.99,
  "threedsClientTransactionId": "id-xxx",
  "threedsCurrency": "840",
  "threedsSdkTransId": "uuid",
  "threedsAcsTransId": "uuid",
  "threedsDsTransId": "uuid",
  "threedsAuthenticationType": "",
  "threedsAuthenticationValue": "base64value"
}
```

### 1.4 Subscription Cancellation

**IMPORTANT**: CCBill does NOT have a RESTful cancellation endpoint. Cancellation must be done via the legacy DataLink system.

**DataLink Cancellation Endpoint:**
```
GET https://datalink.ccbill.com/utils/subscriptionManagement.cgi
```

**Required Parameters:**
- `clientAccnum` - 6-digit merchant account number
- `username` - DataLink username
- `password` - DataLink password
- `action` - Set to `cancelSubscription`
- `subscriptionId` - The subscription to cancel
- `usingSubacc` - 4-digit subaccount (optional)
- `returnXML` - Set to `1` for XML response

**Example Request:**
```
https://datalink.ccbill.com/utils/subscriptionManagement.cgi?clientAccnum=900000&username=myuser&password=mypass&action=cancelSubscription&subscriptionId=0123456789&returnXML=1
```

**Success Response (XML):**
```xml
<?xml version='1.0' standalone='yes'?>
<results>1</results>
```

**Error Codes:**
- `0` - Failed
- `-1` - Invalid auth
- `-2` - Invalid subscription ID
- `-3` - No record found
- `-4` - Subscription not for this account
- `-5` - Invalid arguments
- `-6` - Invalid action
- `-7` - Internal/database error
- `-8` - IP not whitelisted
- `-9` - Account deactivated
- `-10` - DataLink not set up
- `-12` - Too many failed logins

### 1.5 Webhook Event Types

CCBill sends webhooks for these events:

| Event Type | Description |
|------------|-------------|
| `NewSaleSuccess` | New subscription created |
| `NewSaleFailure` | Subscription creation failed |
| `RenewalSuccess` | Recurring payment succeeded |
| `RenewalFailure` | Recurring payment failed |
| `Cancellation` | Subscription cancelled |
| `Chargeback` | Chargeback received |
| `Refund` | Refund processed |
| `Expiration` | Subscription expired |
| `BillingDateChange` | Next billing date changed |
| `Upgrade` | Subscription upgraded |

**Webhook Payload Example (NewSaleSuccess):**
```json
{
  "eventType": "NewSaleSuccess",
  "subscriptionId": "0123456789",
  "transactionId": "0923456789",
  "clientAccnum": "900000",
  "clientSubacc": "0001",
  "timestamp": "2024-01-15T10:30:00Z",
  "billedAmount": "9.99",
  "billedCurrency": "USD",
  "accountingAmount": "9.99",
  "accountingCurrency": "USD",
  "nextRenewalDate": "2024-02-14",
  "email": "customer@example.com",
  "paymentType": "CREDIT",
  "cardType": "VISA",
  "last4": "1234",
  "expDate": "0125"
}
```

**Signature Validation:**
CCBill webhooks are signed with HMAC SHA256. The signature is in the `X-CCBill-Signature` header.

```php
$expectedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);
hash_equals($expectedSignature, $receivedSignature);
```

---

## Part 2: Required Configuration Changes

### 2.1 Update config/obsidian.php

The config needs to support the new credential structure:

```php
<?php

return [
    'gateway' => env('OBSIDIAN_GATEWAY', 'ccbill'),
    'fallback_gateway' => env('OBSIDIAN_FALLBACK_GATEWAY'),
    
    'currency' => env('OBSIDIAN_CURRENCY', 'USD'),
    'currency_locale' => env('OBSIDIAN_CURRENCY_LOCALE', 'en'),

    'ccbill' => [
        // Main account identifiers
        'merchant_id' => env('CCBILL_MERCHANT_ID'),
        'subaccount_id' => env('CCBILL_SUBACCOUNT_ID'),
        
        // Backend API credentials (for server-to-server)
        'merchant_app_id' => env('CCBILL_MERCHANT_APP_ID'),
        'secret_key' => env('CCBILL_SECRET_KEY'),
        
        // DataLink credentials (for cancellations/management)
        'datalink_username' => env('CCBILL_DATALINK_USERNAME'),
        'datalink_password' => env('CCBILL_DATALINK_PASSWORD'),
        
        // Webhook validation
        'webhook_secret' => env('CCBILL_WEBHOOK_SECRET'),
        
        // Optional: Salt for FlexForms (if using hosted payment pages)
        'salt' => env('CCBILL_SALT'),
    ],

    'segpay' => [
        // Mark as WIP - not yet implemented
        'merchant_id' => env('SEGPAY_MERCHANT_ID'),
        'package_id' => env('SEGPAY_PACKAGE_ID'),
        'user_id' => env('SEGPAY_USER_ID'),
        'api_key' => env('SEGPAY_API_KEY'),
        'webhook_secret' => env('SEGPAY_WEBHOOK_SECRET'),
    ],
];
```

---

## Part 3: CcbillGateway.php - Complete Rewrite

Replace the entire `src/Gateways/CcbillGateway.php` with:

```php
<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Zen\Obsidian\Exceptions\GatewayException;
use Zen\Obsidian\Exceptions\WebhookValidationException;

class CcbillGateway implements PaymentGatewayInterface
{
    protected string $merchantId;
    protected string $subAccountId;
    protected string $merchantAppId;
    protected string $secretKey;
    protected string $datalinkUsername;
    protected string $datalinkPassword;
    
    protected const API_BASE = 'https://api.ccbill.com';
    protected const DATALINK_BASE = 'https://datalink.ccbill.com';
    protected const TOKEN_CACHE_KEY = 'ccbill_backend_access_token';
    protected const TOKEN_CACHE_TTL = 3500; // Just under 1 hour
    
    /**
     * Currency code mapping (ISO 4217 numeric)
     */
    protected const CURRENCY_CODES = [
        'USD' => 840,
        'EUR' => 978,
        'GBP' => 826,
        'CAD' => 124,
        'AUD' => 36,
        'JPY' => 392,
    ];

    public function __construct()
    {
        $this->merchantId = (string) config('obsidian.ccbill.merchant_id', '');
        $this->subAccountId = (string) config('obsidian.ccbill.subaccount_id', '');
        $this->merchantAppId = (string) config('obsidian.ccbill.merchant_app_id', '');
        $this->secretKey = (string) config('obsidian.ccbill.secret_key', '');
        $this->datalinkUsername = (string) config('obsidian.ccbill.datalink_username', '');
        $this->datalinkPassword = (string) config('obsidian.ccbill.datalink_password', '');
    }

    public function name(): string
    {
        return 'ccbill';
    }

    /**
     * Get OAuth access token (cached for ~1 hour)
     */
    protected function getAccessToken(): string
    {
        $token = Cache::get(self::TOKEN_CACHE_KEY);
        
        if ($token !== null) {
            return (string) $token;
        }

        $credentials = base64_encode("{$this->merchantAppId}:{$this->secretKey}");

        $response = Http::withHeaders([
            'Authorization' => "Basic {$credentials}",
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->asForm()->post(self::API_BASE . '/ccbill-auth/oauth/token', [
            'grant_type' => 'client_credentials',
        ]);

        if (! $response->successful()) {
            throw new GatewayException(
                'Failed to obtain CCBill access token: ' . $response->body()
            );
        }

        $accessToken = $response->json('access_token');
        
        if (! is_string($accessToken)) {
            throw new GatewayException('Invalid access token response from CCBill');
        }

        Cache::put(self::TOKEN_CACHE_KEY, $accessToken, self::TOKEN_CACHE_TTL);

        return $accessToken;
    }

    /**
     * Get numeric currency code
     */
    protected function getCurrencyCode(string $currency): int
    {
        $currency = strtoupper($currency);
        
        if (! isset(self::CURRENCY_CODES[$currency])) {
            throw new GatewayException("Unsupported currency: {$currency}");
        }
        
        return self::CURRENCY_CODES[$currency];
    }

    /**
     * Build the standard API headers
     */
    protected function buildApiHeaders(): array
    {
        return [
            'Accept' => 'application/vnd.mcn.transaction-service.api.v.2+json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Create a subscription by charging a payment token
     *
     * @param array<string, mixed> $params ['user', 'token', 'plan', 'trial_days', 'amount', 'currency', 'recurring_amount', 'recurring_period']
     * @return array<string, mixed> ['subscription_id', 'status', 'payment_method', 'next_billing_date']
     */
    public function createSubscription(array $params): array
    {
        /** @var object{email: string} $user */
        $user = $params['user'];
        
        /** @var string $token */
        $token = $params['token'];
        
        /** @var int $initialPeriod */
        $initialPeriod = $params['trial_days'] ?? $params['initial_period'] ?? 30;
        
        /** @var float $initialPrice */
        $initialPrice = isset($params['amount']) ? (float) $params['amount'] / 100 : 0.00;
        
        /** @var string $currency */
        $currency = $params['currency'] ?? 'USD';

        $payload = [
            'clientAccnum' => (int) $this->merchantId,
            'clientSubacc' => (int) $this->subAccountId,
            'initialPrice' => $initialPrice,
            'initialPeriod' => $initialPeriod,
            'currencyCode' => $this->getCurrencyCode($currency),
        ];

        // Add recurring billing terms if provided
        if (isset($params['recurring_amount'])) {
            $payload['recurringPrice'] = (float) $params['recurring_amount'] / 100;
            $payload['recurringPeriod'] = $params['recurring_period'] ?? 30;
            $payload['numRebills'] = $params['num_rebills'] ?? 99;
        }

        // Add customer email for notifications
        if (isset($user->email)) {
            $payload['email'] = $user->email;
        }

        $response = Http::withHeaders($this->buildApiHeaders())
            ->post(self::API_BASE . "/transactions/payment-tokens/{$token}", $payload);

        if (! $response->successful()) {
            $this->handleApiError($response, 'Subscription creation failed');
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        if (($data['approved'] ?? false) !== true) {
            throw new GatewayException(
                'CCBill declined the transaction: ' . ($data['declineText'] ?? 'Unknown reason'),
                (int) ($data['declineCode'] ?? 0)
            );
        }

        return [
            'subscription_id' => $data['subscriptionId'] ?? null,
            'transaction_id' => $data['paymentUniqueId'] ?? null,
            'status' => 'active',
            'payment_method' => [
                'type' => 'card',
                'last4' => $data['last4'] ?? null,
            ],
            'next_billing_date' => $data['nextRenewalDate'] ?? null,
        ];
    }

    /**
     * Cancel a subscription via DataLink
     */
    public function cancelSubscription(string $subscriptionId): bool
    {
        if (empty($this->datalinkUsername) || empty($this->datalinkPassword)) {
            // If DataLink credentials aren't configured, we can only mark locally
            // The subscription will naturally expire at CCBill
            return true;
        }

        $queryParams = http_build_query([
            'clientAccnum' => $this->merchantId,
            'username' => $this->datalinkUsername,
            'password' => $this->datalinkPassword,
            'action' => 'cancelSubscription',
            'subscriptionId' => $subscriptionId,
            'returnXML' => '1',
        ]);

        $response = Http::get(self::DATALINK_BASE . "/utils/subscriptionManagement.cgi?{$queryParams}");

        if (! $response->successful()) {
            throw new GatewayException(
                'CCBill DataLink request failed: ' . $response->body()
            );
        }

        $body = $response->body();
        
        // Parse XML response - success returns <results>1</results>
        if (str_contains($body, '<results>1</results>')) {
            return true;
        }

        // Check for error codes
        if (preg_match('/<results>(-?\d+)<\/results>/', $body, $matches)) {
            $errorCode = (int) $matches[1];
            $errorMessage = $this->getDataLinkErrorMessage($errorCode);
            throw new GatewayException("CCBill cancellation failed: {$errorMessage}", $errorCode);
        }

        throw new GatewayException('CCBill cancellation failed: Unknown response');
    }

    /**
     * Get human-readable DataLink error message
     */
    protected function getDataLinkErrorMessage(int $code): string
    {
        return match ($code) {
            0 => 'The requested action failed',
            -1 => 'Invalid or missing authentication credentials',
            -2 => 'Invalid subscription ID or unsupported subscription type',
            -3 => 'No record found for the given subscription',
            -4 => 'Subscription does not belong to this account',
            -5 => 'Invalid or missing action arguments',
            -6 => 'Invalid action requested',
            -7 => 'Internal or database error',
            -8 => 'IP address not in valid range',
            -9 => 'Account deactivated or not permitted for this action',
            -10 => 'DataLink not set up for this account',
            -12 => 'Too many failed login attempts, wait 1 hour',
            default => "Unknown error code: {$code}",
        };
    }

    /**
     * Charge a payment token for a one-time purchase
     *
     * @param array<string, mixed> $params ['token', 'amount', 'currency', 'description']
     * @return array<string, mixed> ['id', 'amount', 'currency', 'status']
     */
    public function charge(array $params): array
    {
        /** @var string $token */
        $token = $params['token'];
        
        /** @var int $amountCents */
        $amountCents = $params['amount'];
        $amount = $amountCents / 100;
        
        /** @var string $currency */
        $currency = $params['currency'] ?? 'USD';

        $payload = [
            'clientAccnum' => (int) $this->merchantId,
            'clientSubacc' => (int) $this->subAccountId,
            'initialPrice' => $amount,
            'initialPeriod' => 0, // One-time charge
            'currencyCode' => $this->getCurrencyCode($currency),
        ];

        $response = Http::withHeaders($this->buildApiHeaders())
            ->post(self::API_BASE . "/transactions/payment-tokens/{$token}", $payload);

        if (! $response->successful()) {
            $this->handleApiError($response, 'Charge failed');
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        if (($data['approved'] ?? false) !== true) {
            throw new GatewayException(
                'CCBill declined the charge: ' . ($data['declineText'] ?? 'Unknown reason'),
                (int) ($data['declineCode'] ?? 0)
            );
        }

        return [
            'id' => $data['paymentUniqueId'] ?? $data['transactionId'] ?? null,
            'subscription_id' => $data['subscriptionId'] ?? null,
            'amount' => $amountCents,
            'currency' => $currency,
            'status' => 'succeeded',
        ];
    }

    /**
     * Handle API error responses
     */
    protected function handleApiError(Response $response, string $context): never
    {
        $body = $response->json() ?? [];
        $message = $body['message'] ?? $body['error'] ?? $response->body();
        
        throw new GatewayException("{$context}: {$message}", $response->status());
    }

    /**
     * Validate webhook signature
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        /** @var string $secret */
        $secret = config('obsidian.ccbill.webhook_secret', '');
        
        if (empty($secret)) {
            throw WebhookValidationException::missingSecret();
        }
        
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            throw WebhookValidationException::invalidSignature();
        }

        return true;
    }

    /**
     * Parse webhook payload into normalized format
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> ['type', 'subscription_id', 'transaction_id', 'amount', 'currency', 'data']
     */
    public function parseWebhookPayload(array $payload): array
    {
        $eventType = $payload['eventType'] ?? 'unknown';
        
        // Normalize event type to internal format
        $normalizedType = match ($eventType) {
            'NewSaleSuccess' => 'subscription.created',
            'NewSaleFailure' => 'subscription.failed',
            'RenewalSuccess' => 'payment.succeeded',
            'RenewalFailure' => 'payment.failed',
            'Cancellation' => 'subscription.cancelled',
            'Chargeback' => 'subscription.chargeback',
            'Refund' => 'payment.refunded',
            'Expiration' => 'subscription.expired',
            default => $eventType,
        };

        // Parse amount - CCBill sends as decimal string
        $amount = null;
        if (isset($payload['billedAmount'])) {
            $amount = (int) ((float) $payload['billedAmount'] * 100);
        } elseif (isset($payload['accountingAmount'])) {
            $amount = (int) ((float) $payload['accountingAmount'] * 100);
        }

        return [
            'type' => $normalizedType,
            'original_type' => $eventType,
            'subscription_id' => $payload['subscriptionId'] ?? null,
            'transaction_id' => $payload['transactionId'] ?? null,
            'amount' => $amount,
            'currency' => $payload['billedCurrency'] ?? $payload['accountingCurrency'] ?? 'USD',
            'next_billing_date' => $payload['nextRenewalDate'] ?? null,
            'customer_email' => $payload['email'] ?? null,
            'card_last4' => $payload['last4'] ?? null,
            'card_type' => $payload['cardType'] ?? null,
            'data' => $payload,
        ];
    }

    /**
     * View subscription status via DataLink
     *
     * @return array<string, mixed>
     */
    public function getSubscriptionStatus(string $subscriptionId): array
    {
        if (empty($this->datalinkUsername) || empty($this->datalinkPassword)) {
            throw new GatewayException('DataLink credentials not configured');
        }

        $queryParams = http_build_query([
            'clientAccnum' => $this->merchantId,
            'username' => $this->datalinkUsername,
            'password' => $this->datalinkPassword,
            'action' => 'viewSubscriptionStatus',
            'subscriptionId' => $subscriptionId,
            'returnXML' => '1',
        ]);

        $response = Http::get(self::DATALINK_BASE . "/utils/subscriptionManagement.cgi?{$queryParams}");

        if (! $response->successful()) {
            throw new GatewayException('CCBill DataLink request failed: ' . $response->body());
        }

        // Parse XML response
        $xml = simplexml_load_string($response->body());
        
        if ($xml === false) {
            throw new GatewayException('Failed to parse CCBill response');
        }

        return [
            'subscription_id' => $subscriptionId,
            'status' => (string) ($xml->subscriptionStatus ?? '0') === '1' ? 'active' : 'cancelled',
            'cancel_date' => (string) ($xml->cancelDate ?? '') ?: null,
            'signup_date' => (string) ($xml->signupDate ?? '') ?: null,
            'expiration_date' => (string) ($xml->expirationDate ?? '') ?: null,
            'is_recurring' => (string) ($xml->recurringSubscription ?? '0') === '1',
            'times_rebilled' => (int) ($xml->timesRebilled ?? 0),
            'chargebacks_issued' => (int) ($xml->chargebacksIssued ?? 0),
            'refunds_issued' => (int) ($xml->refundsIssued ?? 0),
            'voids_issued' => (int) ($xml->voidsIssued ?? 0),
        ];
    }

    /**
     * Refund a transaction via DataLink
     */
    public function refundTransaction(string $subscriptionId, ?int $amountCents = null): bool
    {
        if (empty($this->datalinkUsername) || empty($this->datalinkPassword)) {
            throw new GatewayException('DataLink credentials not configured');
        }

        $params = [
            'clientAccnum' => $this->merchantId,
            'username' => $this->datalinkUsername,
            'password' => $this->datalinkPassword,
            'action' => 'refundTransaction',
            'subscriptionId' => $subscriptionId,
            'returnXML' => '1',
        ];

        // Partial refund if amount specified
        if ($amountCents !== null) {
            $params['amount'] = number_format($amountCents / 100, 2, '.', '');
        }

        $queryParams = http_build_query($params);
        $response = Http::get(self::DATALINK_BASE . "/utils/subscriptionManagement.cgi?{$queryParams}");

        if (! $response->successful()) {
            throw new GatewayException('CCBill DataLink request failed: ' . $response->body());
        }

        $body = $response->body();
        
        if (str_contains($body, '<results>1</results>')) {
            return true;
        }

        if (preg_match('/<results>(-?\d+)<\/results>/', $body, $matches)) {
            $errorCode = (int) $matches[1];
            $errorMessage = $this->getDataLinkErrorMessage($errorCode);
            throw new GatewayException("CCBill refund failed: {$errorMessage}", $errorCode);
        }

        throw new GatewayException('CCBill refund failed: Unknown response');
    }
}
```

---

## Part 4: Update PaymentGatewayInterface.php

Update the interface to include the new methods:

```php
<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

interface PaymentGatewayInterface
{
    /**
     * Get the gateway name
     */
    public function name(): string;

    /**
     * Create a subscription with a payment token
     *
     * @param array<string, mixed> $params ['user', 'token', 'plan', 'trial_days', 'amount', 'currency']
     * @return array<string, mixed> ['subscription_id', 'status', 'next_billing_date', 'payment_method']
     */
    public function createSubscription(array $params): array;

    /**
     * Cancel a subscription (stop future billing)
     */
    public function cancelSubscription(string $subscriptionId): bool;

    /**
     * Charge a payment token one-time
     *
     * @param array<string, mixed> $params ['token', 'amount', 'currency', 'description']
     * @return array<string, mixed> ['id', 'status', 'amount', 'currency']
     */
    public function charge(array $params): array;

    /**
     * Validate webhook signature
     */
    public function validateWebhookSignature(string $payload, string $signature): bool;

    /**
     * Parse webhook payload into normalized format
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> ['type', 'subscription_id', 'transaction_id', 'amount', 'currency', 'data']
     */
    public function parseWebhookPayload(array $payload): array;
}
```

---

## Part 5: Update FakeGateway.php

The FakeGateway should mirror the real CCBill implementation for testing:

```php
<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

use Zen\Obsidian\Exceptions\GatewayException;
use Zen\Obsidian\Exceptions\WebhookValidationException;

class FakeGateway implements PaymentGatewayInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected static array $subscriptions = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected static array $charges = [];

    /**
     * @var bool
     */
    protected static bool $shouldFail = false;

    /**
     * @var string|null
     */
    protected static ?string $failureMessage = null;

    /**
     * @var int
     */
    protected static int $failureCode = 0;

    /**
     * Reset the fake gateway state
     */
    public static function reset(): void
    {
        self::$subscriptions = [];
        self::$charges = [];
        self::$shouldFail = false;
        self::$failureMessage = null;
        self::$failureCode = 0;
    }

    /**
     * Force the next operation to fail
     */
    public static function shouldFail(string $message = 'Simulated failure', int $code = 100): void
    {
        self::$shouldFail = true;
        self::$failureMessage = $message;
        self::$failureCode = $code;
    }

    /**
     * Get all subscriptions for assertions
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscriptions(): array
    {
        return self::$subscriptions;
    }

    /**
     * Get all charges for assertions
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getCharges(): array
    {
        return self::$charges;
    }

    public function name(): string
    {
        return 'fake';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createSubscription(array $params): array
    {
        $this->checkForFailure();

        $subscriptionId = 'fake_sub_' . uniqid();
        $transactionId = 'fake_txn_' . uniqid();

        /** @var int $trialDays */
        $trialDays = $params['trial_days'] ?? 0;
        
        /** @var int $initialPeriod */
        $initialPeriod = $params['initial_period'] ?? 30;

        $nextBillingDate = now()
            ->addDays($trialDays > 0 ? $trialDays : $initialPeriod)
            ->format('Y-m-d');

        $subscription = [
            'subscription_id' => $subscriptionId,
            'transaction_id' => $transactionId,
            'status' => 'active',
            'token' => $params['token'] ?? null,
            'plan' => $params['plan'] ?? null,
            'amount' => $params['amount'] ?? 0,
            'currency' => $params['currency'] ?? 'USD',
            'trial_days' => $trialDays,
            'next_billing_date' => $nextBillingDate,
            'payment_method' => [
                'type' => 'card',
                'last4' => '4242',
                'brand' => 'visa',
            ],
            'created_at' => now()->toIso8601String(),
        ];

        self::$subscriptions[$subscriptionId] = $subscription;

        return [
            'subscription_id' => $subscriptionId,
            'transaction_id' => $transactionId,
            'status' => 'active',
            'payment_method' => $subscription['payment_method'],
            'next_billing_date' => $nextBillingDate,
        ];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        $this->checkForFailure();

        if (isset(self::$subscriptions[$subscriptionId])) {
            self::$subscriptions[$subscriptionId]['status'] = 'cancelled';
            self::$subscriptions[$subscriptionId]['cancelled_at'] = now()->toIso8601String();
        }

        return true;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function charge(array $params): array
    {
        $this->checkForFailure();

        $chargeId = 'fake_ch_' . uniqid();

        /** @var int $amount */
        $amount = $params['amount'];
        
        /** @var string $currency */
        $currency = $params['currency'] ?? 'USD';

        $charge = [
            'id' => $chargeId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'succeeded',
            'token' => $params['token'] ?? null,
            'description' => $params['description'] ?? null,
            'created_at' => now()->toIso8601String(),
        ];

        self::$charges[$chargeId] = $charge;

        return [
            'id' => $chargeId,
            'subscription_id' => null,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'succeeded',
        ];
    }

    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        // For testing, accept 'valid_signature' or compute actual HMAC with test secret
        if ($signature === 'valid_signature') {
            return true;
        }

        $testSecret = 'test_webhook_secret';
        $expectedSignature = hash_hmac('sha256', $payload, $testSecret);

        if (! hash_equals($expectedSignature, $signature)) {
            throw WebhookValidationException::invalidSignature();
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function parseWebhookPayload(array $payload): array
    {
        $eventType = $payload['eventType'] ?? $payload['type'] ?? 'unknown';

        // Normalize event type to internal format (mirrors CCBill normalization)
        $normalizedType = match ($eventType) {
            'NewSaleSuccess' => 'subscription.created',
            'NewSaleFailure' => 'subscription.failed',
            'RenewalSuccess' => 'payment.succeeded',
            'RenewalFailure' => 'payment.failed',
            'Cancellation' => 'subscription.cancelled',
            'Chargeback' => 'subscription.chargeback',
            'Refund' => 'payment.refunded',
            'Expiration' => 'subscription.expired',
            default => $eventType,
        };

        $amount = null;
        if (isset($payload['billedAmount'])) {
            $amount = (int) ((float) $payload['billedAmount'] * 100);
        } elseif (isset($payload['amount'])) {
            $amount = (int) $payload['amount'];
        }

        return [
            'type' => $normalizedType,
            'original_type' => $eventType,
            'subscription_id' => $payload['subscriptionId'] ?? $payload['subscription_id'] ?? null,
            'transaction_id' => $payload['transactionId'] ?? $payload['transaction_id'] ?? null,
            'amount' => $amount,
            'currency' => $payload['billedCurrency'] ?? $payload['currency'] ?? 'USD',
            'next_billing_date' => $payload['nextRenewalDate'] ?? null,
            'customer_email' => $payload['email'] ?? null,
            'card_last4' => $payload['last4'] ?? null,
            'card_type' => $payload['cardType'] ?? null,
            'data' => $payload,
        ];
    }

    /**
     * Check if we should simulate a failure
     */
    protected function checkForFailure(): void
    {
        if (self::$shouldFail) {
            $message = self::$failureMessage ?? 'Simulated failure';
            $code = self::$failureCode;
            
            // Reset for next call
            self::$shouldFail = false;
            self::$failureMessage = null;
            self::$failureCode = 0;
            
            throw new GatewayException($message, $code);
        }
    }
}
```

---

## Part 6: Add GatewayException

Create `src/Exceptions/GatewayException.php`:

```php
<?php

declare(strict_types=1);

namespace Zen\Obsidian\Exceptions;

use Exception;

class GatewayException extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
```

---

## Part 7: Update WebhookValidationException

Update `src/Exceptions/WebhookValidationException.php`:

```php
<?php

declare(strict_types=1);

namespace Zen\Obsidian\Exceptions;

use Exception;

class WebhookValidationException extends Exception
{
    public static function invalidSignature(): self
    {
        return new self('Invalid webhook signature', 403);
    }

    public static function missingSecret(): self
    {
        return new self('Webhook secret not configured', 500);
    }

    public static function missingSignature(): self
    {
        return new self('Webhook signature header missing', 400);
    }
}
```

---

## Part 8: Update SegpayGateway.php (Mark as WIP)

Replace `src/Gateways/SegpayGateway.php` with a placeholder that throws:

```php
<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

use Zen\Obsidian\Exceptions\GatewayException;

/**
 * SegPay Gateway - Work In Progress
 *
 * SegPay uses a hosted payment page model with postback webhooks,
 * which requires a different integration approach than token-based APIs.
 *
 * This gateway is not yet implemented. See README for roadmap.
 *
 * @see https://gethelp.segpay.com/docs/Content/DeveloperDocs/ProcessingAPI/Home-ProcessingAPI.htm
 */
class SegpayGateway implements PaymentGatewayInterface
{
    public function __construct()
    {
        // Configuration placeholder for future implementation
    }

    public function name(): string
    {
        return 'segpay';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createSubscription(array $params): array
    {
        throw new GatewayException(
            'SegPay gateway is not yet implemented. SegPay uses a hosted payment page model. See README for roadmap.'
        );
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        throw new GatewayException(
            'SegPay gateway is not yet implemented. See README for roadmap.'
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function charge(array $params): array
    {
        throw new GatewayException(
            'SegPay gateway is not yet implemented. See README for roadmap.'
        );
    }

    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        throw new GatewayException(
            'SegPay gateway is not yet implemented. See README for roadmap.'
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function parseWebhookPayload(array $payload): array
    {
        throw new GatewayException(
            'SegPay gateway is not yet implemented. See README for roadmap.'
        );
    }
}
```

---

## Part 9: Update Tests

### 9.1 Update tests/TestCase.php

Ensure FakeGateway is reset before each test:

```php
<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Zen\Obsidian\Gateways\FakeGateway;
use Zen\Obsidian\ObsidianServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Reset fake gateway state before each test
        FakeGateway::reset();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ObsidianServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('obsidian.gateway', 'fake');
        $app['config']->set('obsidian.ccbill.webhook_secret', 'test_webhook_secret');
    }
}
```

### 9.2 Update tests/Feature/CcbillWebhookTest.php

```php
<?php

use Illuminate\Support\Facades\Event;
use Tests\Fixtures\User;
use Zen\Obsidian\Events\PaymentFailed;
use Zen\Obsidian\Events\PaymentSucceeded;
use Zen\Obsidian\Events\SubscriptionCancelled;
use Zen\Obsidian\Events\SubscriptionCreated;

beforeEach(function (): void {
    config(['obsidian.ccbill.webhook_secret' => 'test_secret']);
});

function generateCcbillSignature(array $payload, string $secret = 'test_secret'): string
{
    return hash_hmac('sha256', json_encode($payload), $secret);
}

test('ccbill webhook handles NewSaleSuccess', function (): void {
    Event::fake();

    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'gateway' => 'ccbill',
        'gateway_subscription_id' => 'sub_123',
        'gateway_plan_id' => 'plan_123',
        'status' => 'pending',
    ]);

    $payload = [
        'eventType' => 'NewSaleSuccess',
        'subscriptionId' => 'sub_123',
        'transactionId' => 'txn_456',
        'billedAmount' => '29.99',
        'billedCurrency' => 'USD',
        'nextRenewalDate' => '2024-02-15',
    ];

    $signature = generateCcbillSignature($payload);

    $response = $this->postJson('/webhooks/ccbill', $payload, [
        'X-CCBill-Signature' => $signature,
    ]);

    $response->assertStatus(200);

    $subscription->refresh();
    expect($subscription->status)->toBe('active');

    Event::assertDispatched(SubscriptionCreated::class);
    Event::assertDispatched(PaymentSucceeded::class);
});

test('ccbill webhook handles RenewalSuccess', function (): void {
    Event::fake();

    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'gateway' => 'ccbill',
        'gateway_subscription_id' => 'sub_123',
        'gateway_plan_id' => 'plan_123',
        'status' => 'active',
    ]);

    $payload = [
        'eventType' => 'RenewalSuccess',
        'subscriptionId' => 'sub_123',
        'transactionId' => 'txn_789',
        'billedAmount' => '29.99',
        'billedCurrency' => 'USD',
    ];

    $signature = generateCcbillSignature($payload);

    $response = $this->postJson('/webhooks/ccbill', $payload, [
        'X-CCBill-Signature' => $signature,
    ]);

    $response->assertStatus(200);

    Event::assertDispatched(PaymentSucceeded::class);
});

test('ccbill webhook handles RenewalFailure', function (): void {
    Event::fake();

    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'gateway' => 'ccbill',
        'gateway_subscription_id' => 'sub_123',
        'gateway_plan_id' => 'plan_123',
        'status' => 'active',
    ]);

    $payload = [
        'eventType' => 'RenewalFailure',
        'subscriptionId' => 'sub_123',
    ];

    $signature = generateCcbillSignature($payload);

    $response = $this->postJson('/webhooks/ccbill', $payload, [
        'X-CCBill-Signature' => $signature,
    ]);

    $response->assertStatus(200);

    $subscription->refresh();
    expect($subscription->status)->toBe('past_due');

    Event::assertDispatched(PaymentFailed::class);
});

test('ccbill webhook handles Cancellation', function (): void {
    Event::fake();

    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'gateway' => 'ccbill',
        'gateway_subscription_id' => 'sub_123',
        'gateway_plan_id' => 'plan_123',
        'status' => 'active',
    ]);

    $payload = [
        'eventType' => 'Cancellation',
        'subscriptionId' => 'sub_123',
    ];

    $signature = generateCcbillSignature($payload);

    $response = $this->postJson('/webhooks/ccbill', $payload, [
        'X-CCBill-Signature' => $signature,
    ]);

    $response->assertStatus(200);

    $subscription->refresh();
    expect($subscription->status)->toBe('cancelled');

    Event::assertDispatched(SubscriptionCancelled::class);
});

test('ccbill webhook handles Chargeback', function (): void {
    Event::fake();

    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'gateway' => 'ccbill',
        'gateway_subscription_id' => 'sub_123',
        'gateway_plan_id' => 'plan_123',
        'status' => 'active',
    ]);

    $payload = [
        'eventType' => 'Chargeback',
        'subscriptionId' => 'sub_123',
    ];

    $signature = generateCcbillSignature($payload);

    $response = $this->postJson('/webhooks/ccbill', $payload, [
        'X-CCBill-Signature' => $signature,
    ]);

    $response->assertStatus(200);

    $subscription->refresh();
    expect($subscription->status)->toBe('cancelled');

    Event::assertDispatched(SubscriptionCancelled::class);
});

test('ccbill webhook handles Refund', function (): void {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'gateway' => 'ccbill',
        'gateway_subscription_id' => 'sub_123',
        'gateway_plan_id' => 'plan_123',
        'status' => 'active',
    ]);

    $payload = [
        'eventType' => 'Refund',
        'subscriptionId' => 'sub_123',
        'billedAmount' => '29.99',
    ];

    $signature = generateCcbillSignature($payload);

    $response = $this->postJson('/webhooks/ccbill', $payload, [
        'X-CCBill-Signature' => $signature,
    ]);

    $response->assertStatus(200);
});

test('ccbill webhook rejects invalid signature', function (): void {
    $payload = [
        'eventType' => 'NewSaleSuccess',
        'subscriptionId' => 'sub_123',
    ];

    $response = $this->postJson('/webhooks/ccbill', $payload, [
        'X-CCBill-Signature' => 'invalid_signature',
    ]);

    $response->assertStatus(403);
});

test('ccbill webhook handles unknown event type gracefully', function (): void {
    $payload = [
        'eventType' => 'UnknownEvent',
    ];

    $signature = generateCcbillSignature($payload);

    $response = $this->postJson('/webhooks/ccbill', $payload, [
        'X-CCBill-Signature' => $signature,
    ]);

    $response->assertStatus(200);
});

test('ccbill webhook parses amount correctly', function (): void {
    Event::fake();

    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'gateway' => 'ccbill',
        'gateway_subscription_id' => 'sub_123',
        'gateway_plan_id' => 'plan_123',
        'status' => 'active',
    ]);

    $payload = [
        'eventType' => 'RenewalSuccess',
        'subscriptionId' => 'sub_123',
        'billedAmount' => '49.99',
        'billedCurrency' => 'USD',
    ];

    $signature = generateCcbillSignature($payload);

    $response = $this->postJson('/webhooks/ccbill', $payload, [
        'X-CCBill-Signature' => $signature,
    ]);

    $response->assertStatus(200);

    Event::assertDispatched(PaymentSucceeded::class, function ($event) {
        return $event->amount === 4999; // Cents
    });
});
```

### 9.3 Add tests/Unit/CcbillGatewayTest.php

```php
<?php

use Illuminate\Support\Facades\Http;
use Zen\Obsidian\Exceptions\GatewayException;
use Zen\Obsidian\Exceptions\WebhookValidationException;
use Zen\Obsidian\Gateways\CcbillGateway;

beforeEach(function (): void {
    config([
        'obsidian.ccbill.merchant_id' => '900000',
        'obsidian.ccbill.subaccount_id' => '0001',
        'obsidian.ccbill.merchant_app_id' => 'test_app_id',
        'obsidian.ccbill.secret_key' => 'test_secret_key',
        'obsidian.ccbill.datalink_username' => 'test_user',
        'obsidian.ccbill.datalink_password' => 'test_pass',
        'obsidian.ccbill.webhook_secret' => 'test_webhook_secret',
    ]);
});

test('gateway returns correct name', function (): void {
    $gateway = new CcbillGateway();
    expect($gateway->name())->toBe('ccbill');
});

test('createSubscription sends correct request', function (): void {
    Http::fake([
        'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
            'access_token' => 'test_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
        'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
            'approved' => true,
            'subscriptionId' => 'sub_123456',
            'paymentUniqueId' => 'pay_789',
            'nextRenewalDate' => '2024-02-15',
        ]),
    ]);

    $gateway = new CcbillGateway();
    
    $user = new class {
        public string $email = 'test@example.com';
    };

    $result = $gateway->createSubscription([
        'user' => $user,
        'token' => 'test_payment_token',
        'plan' => 'monthly',
        'amount' => 2999,
        'currency' => 'USD',
    ]);

    expect($result)
        ->toHaveKey('subscription_id', 'sub_123456')
        ->toHaveKey('status', 'active')
        ->toHaveKey('transaction_id', 'pay_789');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'payment-tokens/test_payment_token')
            && $request['clientAccnum'] === 900000
            && $request['clientSubacc'] === 1
            && $request['initialPrice'] === 29.99
            && $request['currencyCode'] === 840;
    });
});

test('createSubscription throws on declined transaction', function (): void {
    Http::fake([
        'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
            'access_token' => 'test_token',
        ]),
        'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
            'approved' => false,
            'declineCode' => 101,
            'declineText' => 'Card declined',
        ]),
    ]);

    $gateway = new CcbillGateway();
    
    $user = new class {
        public string $email = 'test@example.com';
    };

    $gateway->createSubscription([
        'user' => $user,
        'token' => 'test_payment_token',
        'amount' => 2999,
    ]);
})->throws(GatewayException::class, 'CCBill declined the transaction');

test('charge sends correct request', function (): void {
    Http::fake([
        'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
            'access_token' => 'test_token',
        ]),
        'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
            'approved' => true,
            'paymentUniqueId' => 'charge_123',
        ]),
    ]);

    $gateway = new CcbillGateway();

    $result = $gateway->charge([
        'token' => 'test_token',
        'amount' => 1999,
        'currency' => 'USD',
    ]);

    expect($result)
        ->toHaveKey('id', 'charge_123')
        ->toHaveKey('amount', 1999)
        ->toHaveKey('status', 'succeeded');

    Http::assertSent(function ($request) {
        return $request['initialPeriod'] === 0; // One-time charge
    });
});

test('cancelSubscription via DataLink', function (): void {
    Http::fake([
        'datalink.ccbill.com/*' => Http::response(
            "<?xml version='1.0' standalone='yes'?><results>1</results>"
        ),
    ]);

    $gateway = new CcbillGateway();
    $result = $gateway->cancelSubscription('sub_123456');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        $url = $request->url();
        return str_contains($url, 'action=cancelSubscription')
            && str_contains($url, 'subscriptionId=sub_123456');
    });
});

test('cancelSubscription throws on DataLink error', function (): void {
    Http::fake([
        'datalink.ccbill.com/*' => Http::response(
            "<?xml version='1.0' standalone='yes'?><results>-3</results>"
        ),
    ]);

    $gateway = new CcbillGateway();
    $gateway->cancelSubscription('invalid_sub');
})->throws(GatewayException::class, 'No record found');

test('validateWebhookSignature accepts valid signature', function (): void {
    $gateway = new CcbillGateway();
    $payload = '{"eventType":"NewSaleSuccess"}';
    $signature = hash_hmac('sha256', $payload, 'test_webhook_secret');

    $result = $gateway->validateWebhookSignature($payload, $signature);
    expect($result)->toBeTrue();
});

test('validateWebhookSignature rejects invalid signature', function (): void {
    $gateway = new CcbillGateway();
    $payload = '{"eventType":"NewSaleSuccess"}';

    $gateway->validateWebhookSignature($payload, 'invalid_signature');
})->throws(WebhookValidationException::class);

test('parseWebhookPayload normalizes event types', function (): void {
    $gateway = new CcbillGateway();

    $result = $gateway->parseWebhookPayload([
        'eventType' => 'NewSaleSuccess',
        'subscriptionId' => 'sub_123',
        'billedAmount' => '29.99',
    ]);

    expect($result)
        ->toHaveKey('type', 'subscription.created')
        ->toHaveKey('original_type', 'NewSaleSuccess')
        ->toHaveKey('subscription_id', 'sub_123')
        ->toHaveKey('amount', 2999);
});

test('parseWebhookPayload handles all event types', function (): void {
    $gateway = new CcbillGateway();

    $eventMappings = [
        'NewSaleSuccess' => 'subscription.created',
        'NewSaleFailure' => 'subscription.failed',
        'RenewalSuccess' => 'payment.succeeded',
        'RenewalFailure' => 'payment.failed',
        'Cancellation' => 'subscription.cancelled',
        'Chargeback' => 'subscription.chargeback',
        'Refund' => 'payment.refunded',
        'Expiration' => 'subscription.expired',
    ];

    foreach ($eventMappings as $ccbillType => $normalizedType) {
        $result = $gateway->parseWebhookPayload(['eventType' => $ccbillType]);
        expect($result['type'])->toBe($normalizedType);
    }
});

test('currency codes are converted correctly', function (): void {
    Http::fake([
        'api.ccbill.com/ccbill-auth/oauth/token' => Http::response(['access_token' => 'test']),
        'api.ccbill.com/transactions/payment-tokens/*' => Http::response(['approved' => true, 'subscriptionId' => 'sub_1']),
    ]);

    $gateway = new CcbillGateway();
    $user = new class { public string $email = 'test@example.com'; };

    // Test EUR
    $gateway->createSubscription([
        'user' => $user,
        'token' => 'token',
        'amount' => 1000,
        'currency' => 'EUR',
    ]);

    Http::assertSent(fn ($r) => $r['currencyCode'] === 978);
});
```

### 9.4 Add tests/Unit/FakeGatewayTest.php

```php
<?php

use Zen\Obsidian\Exceptions\GatewayException;
use Zen\Obsidian\Exceptions\WebhookValidationException;
use Zen\Obsidian\Gateways\FakeGateway;

beforeEach(function (): void {
    FakeGateway::reset();
});

test('fake gateway returns correct name', function (): void {
    $gateway = new FakeGateway();
    expect($gateway->name())->toBe('fake');
});

test('createSubscription returns subscription data', function (): void {
    $gateway = new FakeGateway();
    
    $user = new class { public string $email = 'test@example.com'; };

    $result = $gateway->createSubscription([
        'user' => $user,
        'token' => 'test_token',
        'plan' => 'monthly',
        'amount' => 2999,
    ]);

    expect($result)
        ->toHaveKey('subscription_id')
        ->toHaveKey('status', 'active')
        ->toHaveKey('payment_method');

    expect($result['subscription_id'])->toStartWith('fake_sub_');
});

test('createSubscription stores subscription for assertions', function (): void {
    $gateway = new FakeGateway();
    
    $user = new class { public string $email = 'test@example.com'; };

    $result = $gateway->createSubscription([
        'user' => $user,
        'token' => 'test_token',
    ]);

    $subscriptions = FakeGateway::getSubscriptions();
    expect($subscriptions)->toHaveKey($result['subscription_id']);
});

test('cancelSubscription updates subscription status', function (): void {
    $gateway = new FakeGateway();
    
    $user = new class { public string $email = 'test@example.com'; };

    $result = $gateway->createSubscription([
        'user' => $user,
        'token' => 'test_token',
    ]);

    $cancelled = $gateway->cancelSubscription($result['subscription_id']);
    expect($cancelled)->toBeTrue();

    $subscriptions = FakeGateway::getSubscriptions();
    expect($subscriptions[$result['subscription_id']]['status'])->toBe('cancelled');
});

test('charge returns charge data', function (): void {
    $gateway = new FakeGateway();

    $result = $gateway->charge([
        'token' => 'test_token',
        'amount' => 1999,
        'currency' => 'USD',
    ]);

    expect($result)
        ->toHaveKey('id')
        ->toHaveKey('amount', 1999)
        ->toHaveKey('status', 'succeeded');

    expect($result['id'])->toStartWith('fake_ch_');
});

test('charge stores charge for assertions', function (): void {
    $gateway = new FakeGateway();

    $result = $gateway->charge([
        'token' => 'test_token',
        'amount' => 1999,
    ]);

    $charges = FakeGateway::getCharges();
    expect($charges)->toHaveKey($result['id']);
});

test('shouldFail causes next operation to throw', function (): void {
    FakeGateway::shouldFail('Test failure', 500);

    $gateway = new FakeGateway();
    
    $user = new class { public string $email = 'test@example.com'; };

    $gateway->createSubscription([
        'user' => $user,
        'token' => 'test_token',
    ]);
})->throws(GatewayException::class, 'Test failure');

test('shouldFail resets after one failure', function (): void {
    FakeGateway::shouldFail('Test failure');

    $gateway = new FakeGateway();
    
    $user = new class { public string $email = 'test@example.com'; };

    try {
        $gateway->createSubscription(['user' => $user, 'token' => 'token']);
    } catch (GatewayException) {
        // Expected
    }

    // Second call should succeed
    $result = $gateway->createSubscription(['user' => $user, 'token' => 'token']);
    expect($result)->toHaveKey('subscription_id');
});

test('reset clears all state', function (): void {
    $gateway = new FakeGateway();
    
    $user = new class { public string $email = 'test@example.com'; };
    
    $gateway->createSubscription(['user' => $user, 'token' => 'token']);
    $gateway->charge(['token' => 'token', 'amount' => 100]);

    expect(FakeGateway::getSubscriptions())->not->toBeEmpty();
    expect(FakeGateway::getCharges())->not->toBeEmpty();

    FakeGateway::reset();

    expect(FakeGateway::getSubscriptions())->toBeEmpty();
    expect(FakeGateway::getCharges())->toBeEmpty();
});

test('validateWebhookSignature accepts valid_signature', function (): void {
    $gateway = new FakeGateway();
    
    $result = $gateway->validateWebhookSignature('{"test": true}', 'valid_signature');
    expect($result)->toBeTrue();
});

test('validateWebhookSignature accepts HMAC signature', function (): void {
    $gateway = new FakeGateway();
    
    $payload = '{"test": true}';
    $signature = hash_hmac('sha256', $payload, 'test_webhook_secret');
    
    $result = $gateway->validateWebhookSignature($payload, $signature);
    expect($result)->toBeTrue();
});

test('validateWebhookSignature rejects invalid signature', function (): void {
    $gateway = new FakeGateway();
    
    $gateway->validateWebhookSignature('{"test": true}', 'invalid');
})->throws(WebhookValidationException::class);

test('parseWebhookPayload normalizes CCBill event types', function (): void {
    $gateway = new FakeGateway();

    $result = $gateway->parseWebhookPayload([
        'eventType' => 'NewSaleSuccess',
        'subscriptionId' => 'sub_123',
        'billedAmount' => '19.99',
    ]);

    expect($result)
        ->toHaveKey('type', 'subscription.created')
        ->toHaveKey('original_type', 'NewSaleSuccess')
        ->toHaveKey('subscription_id', 'sub_123')
        ->toHaveKey('amount', 1999);
});
```

### 9.5 Update tests/Feature/SegpayWebhookTest.php

Replace with a placeholder that documents the WIP status:

```php
<?php

use Zen\Obsidian\Gateways\SegpayGateway;
use Zen\Obsidian\Exceptions\GatewayException;

test('segpay gateway is not yet implemented', function (): void {
    $gateway = new SegpayGateway();
    
    $user = new class { public string $email = 'test@example.com'; };
    
    $gateway->createSubscription([
        'user' => $user,
        'token' => 'test',
    ]);
})->throws(GatewayException::class, 'not yet implemented');

test('segpay cancelSubscription throws not implemented', function (): void {
    $gateway = new SegpayGateway();
    $gateway->cancelSubscription('sub_123');
})->throws(GatewayException::class, 'not yet implemented');

test('segpay charge throws not implemented', function (): void {
    $gateway = new SegpayGateway();
    $gateway->charge(['token' => 'test', 'amount' => 100]);
})->throws(GatewayException::class, 'not yet implemented');

test('segpay validateWebhookSignature throws not implemented', function (): void {
    $gateway = new SegpayGateway();
    $gateway->validateWebhookSignature('payload', 'sig');
})->throws(GatewayException::class, 'not yet implemented');

test('segpay parseWebhookPayload throws not implemented', function (): void {
    $gateway = new SegpayGateway();
    $gateway->parseWebhookPayload(['test' => true]);
})->throws(GatewayException::class, 'not yet implemented');
```

---

## Part 10: Update README.md

Add the following sections to the README:

### After the Features section, add:

```markdown
## Gateway Support Status

| Gateway | Status | Notes |
|---------|--------|-------|
| CCBill | ✅ Supported | Full REST API + DataLink integration |
| SegPay | 🚧 In Progress | Requires hosted payment page integration |
| Fake | ✅ Supported | For testing and development |

### CCBill Requirements

To use CCBill, you'll need:
- A CCBill Merchant Account
- Backend API credentials (Merchant Application ID + Secret Key)
- DataLink credentials (for subscription management)
- Webhook secret for validating postbacks

Contact CCBill Support to obtain these credentials.

### SegPay Roadmap

SegPay uses a different integration model (hosted payment pages + postbacks) which requires additional work to support. The SegPay gateway will be implemented in a future release.

If you need SegPay support urgently, please open an issue on GitHub.
```

### Update the Environment Configuration section:

```markdown
### Environment Configuration

Add your payment gateway credentials to your `.env` file:

```env
# Default Gateway
OBSIDIAN_GATEWAY=ccbill
OBSIDIAN_FALLBACK_GATEWAY=

# CCBill Configuration
CCBILL_MERCHANT_ID=900000
CCBILL_SUBACCOUNT_ID=0001
CCBILL_MERCHANT_APP_ID=your_merchant_app_id
CCBILL_SECRET_KEY=your_secret_key
CCBILL_DATALINK_USERNAME=your_datalink_username
CCBILL_DATALINK_PASSWORD=your_datalink_password
CCBILL_WEBHOOK_SECRET=your_webhook_secret

# SegPay Configuration (Coming Soon)
# SEGPAY_MERCHANT_ID=your_merchant_id
# SEGPAY_PACKAGE_ID=your_package_id
# SEGPAY_USER_ID=your_user_id
# SEGPAY_API_KEY=your_api_key
# SEGPAY_WEBHOOK_SECRET=your_webhook_secret

# Currency Settings
OBSIDIAN_CURRENCY=USD
OBSIDIAN_CURRENCY_LOCALE=en
```
```

---

## Part 11: Verification Checklist

After making all changes, verify:

1. [ ] `composer test:static` passes (PHPStan level max)
2. [ ] `composer test:types` passes (100% type coverage)
3. [ ] `composer test:feat` passes (all tests pass)
4. [ ] `composer test:coverage` passes (100% coverage)
5. [ ] `composer test:lint` passes (code style)
6. [ ] All existing functionality still works with FakeGateway
7. [ ] README accurately reflects current state
8. [ ] SegPay gateway throws helpful "not implemented" errors

---

## Part 12: Files to Create/Modify Summary

### Create:
- `src/Exceptions/GatewayException.php`

### Modify:
- `config/obsidian.php` - Update credential structure
- `src/Gateways/PaymentGatewayInterface.php` - Minor updates
- `src/Gateways/CcbillGateway.php` - Complete rewrite
- `src/Gateways/FakeGateway.php` - Complete rewrite
- `src/Gateways/SegpayGateway.php` - Replace with WIP stub
- `src/Exceptions/WebhookValidationException.php` - Add methods
- `tests/TestCase.php` - Add FakeGateway reset
- `tests/Feature/CcbillWebhookTest.php` - Update for new format
- `tests/Feature/SegpayWebhookTest.php` - Replace with WIP tests
- `tests/Unit/CcbillGatewayTest.php` - Create new
- `tests/Unit/FakeGatewayTest.php` - Create new
- `README.md` - Update documentation

---

## Notes for the Agent

1. **Maintain existing public API** - The `Billable` trait methods and `Subscription` model should continue to work without changes to consuming code.

2. **Currency handling** - CCBill uses numeric currency codes (ISO 4217). The gateway should accept string codes ('USD') and convert internally.

3. **Error handling** - All gateway errors should throw `GatewayException` with meaningful messages.

4. **DataLink fallback** - If DataLink credentials aren't configured, `cancelSubscription()` should return true (subscription will expire naturally at CCBill).

5. **Test isolation** - Each test should be isolated. Use `FakeGateway::reset()` in setUp.

6. **HTTP Faking** - Use Laravel's `Http::fake()` for unit testing the CCBill gateway without hitting real endpoints.

7. **Preserve backwards compatibility** - Existing tests in `tests/Feature/BillableTest.php` and `tests/Feature/SubscriptionTest.php` should continue to pass.
