<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

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

  protected const string API_BASE = 'https://api.ccbill.com';

  protected const string DATALINK_BASE = 'https://datalink.ccbill.com';

  protected const string TOKEN_CACHE_KEY = 'ccbill_backend_access_token';

  protected const int TOKEN_CACHE_TTL = 3500; // Just under 1 hour

  /**
   * Currency code mapping (ISO 4217 numeric)
   *
   * @var array<string, int>
   */
  protected const array CURRENCY_CODES = [
    'USD' => 840,
    'EUR' => 978,
    'GBP' => 826,
    'CAD' => 124,
    'AUD' => 36,
    'JPY' => 392,
  ];

  public function __construct()
  {
    /** @var string $merchantId */
    $merchantId = config('obsidian.ccbill.merchant_id', '');
    $this->merchantId = $merchantId;

    /** @var string $subAccountId */
    $subAccountId = config('obsidian.ccbill.subaccount_id', '');
    $this->subAccountId = $subAccountId;

    /** @var string $merchantAppId */
    $merchantAppId = config('obsidian.ccbill.merchant_app_id', '');
    $this->merchantAppId = $merchantAppId;

    /** @var string $secretKey */
    $secretKey = config('obsidian.ccbill.secret_key', '');
    $this->secretKey = $secretKey;

    /** @var string $datalinkUsername */
    $datalinkUsername = config('obsidian.ccbill.datalink_username', '');
    $this->datalinkUsername = $datalinkUsername;

    /** @var string $datalinkPassword */
    $datalinkPassword = config('obsidian.ccbill.datalink_password', '');
    $this->datalinkPassword = $datalinkPassword;
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
    /** @var string|null $token */
    $token = Cache::get(self::TOKEN_CACHE_KEY);

    if ($token !== null) {
      return $token;
    }

    $credentials = base64_encode("{$this->merchantAppId}:{$this->secretKey}");

    $response = Http::withHeaders([
      'Authorization' => "Basic {$credentials}",
      'Content-Type' => 'application/x-www-form-urlencoded',
    ])->asForm()->post(self::API_BASE.'/ccbill-auth/oauth/token', [
      'grant_type' => 'client_credentials',
    ]);

    if (! $response->successful()) {
      throw new GatewayException(
        'Failed to obtain CCBill access token: '.$response->body()
      );
    }

    $accessToken = $response->json('access_token');

    throw_unless(is_string($accessToken), GatewayException::class, 'Invalid access token response from CCBill');

    Cache::put(self::TOKEN_CACHE_KEY, $accessToken, self::TOKEN_CACHE_TTL);

    return $accessToken;
  }

  /**
   * Get numeric currency code
   */
  protected function getCurrencyCode(string $currency): int
  {
    $currency = strtoupper($currency);

    throw_unless(isset(self::CURRENCY_CODES[$currency]), GatewayException::class, "Unsupported currency: {$currency}");

    return self::CURRENCY_CODES[$currency];
  }

  /**
   * Build the standard API headers
   *
   * @return array<string, string>
   */
  protected function buildApiHeaders(): array
  {
    return [
      'Accept' => 'application/vnd.mcn.transaction-service.api.v.2+json',
      'Authorization' => 'Bearer '.$this->getAccessToken(),
      'Cache-Control' => 'no-cache',
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * Create a subscription by charging a payment token
   *
   * @param  array<string, mixed>  $params  ['user', 'token', 'plan', 'trial_days', 'amount', 'currency', 'recurring_amount', 'recurring_period']
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

    /** @var int|float $amountValue */
    $amountValue = $params['amount'] ?? 0;
    $initialPrice = (float) $amountValue / 100;

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
      /** @var int|float $recurringAmount */
      $recurringAmount = $params['recurring_amount'];
      $payload['recurringPrice'] = (float) $recurringAmount / 100;
      $payload['recurringPeriod'] = $params['recurring_period'] ?? 30;
      $payload['numRebills'] = $params['num_rebills'] ?? 99;
    }

    // Add customer email for notifications
    if (isset($user->email)) {
      $payload['email'] = $user->email;
    }

    $response = Http::withHeaders($this->buildApiHeaders())
      ->post(self::API_BASE."/transactions/payment-tokens/{$token}", $payload);

    if (! $response->successful()) {
      $this->handleApiError($response, 'Subscription creation failed');
    }

    /** @var array<string, mixed> $data */
    $data = $response->json();

    if (($data['approved'] ?? false) !== true) {
      /** @var string $declineText */
      $declineText = $data['declineText'] ?? 'Unknown reason';
      /** @var int $declineCode */
      $declineCode = $data['declineCode'] ?? 0;
      throw new GatewayException(
        'CCBill declined the transaction: '.$declineText,
        $declineCode
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
    if ($this->datalinkUsername === '' || $this->datalinkUsername === '0' || ($this->datalinkPassword === '' || $this->datalinkPassword === '0')) {
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

    $response = Http::get(self::DATALINK_BASE."/utils/subscriptionManagement.cgi?{$queryParams}");

    if (! $response->successful()) {
      throw new GatewayException(
        'CCBill DataLink request failed: '.$response->body()
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
   * @param  array<string, mixed>  $params  ['token', 'amount', 'currency', 'description']
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
      ->post(self::API_BASE."/transactions/payment-tokens/{$token}", $payload);

    if (! $response->successful()) {
      $this->handleApiError($response, 'Charge failed');
    }

    /** @var array<string, mixed> $data */
    $data = $response->json();

    if (($data['approved'] ?? false) !== true) {
      /** @var string $declineText */
      $declineText = $data['declineText'] ?? 'Unknown reason';
      /** @var int $declineCode */
      $declineCode = $data['declineCode'] ?? 0;
      throw new GatewayException(
        'CCBill declined the charge: '.$declineText,
        $declineCode
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
    /** @var array<string, mixed> $body */
    $body = $response->json() ?? [];

    /** @var string $message */
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
   * @param  array<string, mixed>  $payload
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
      /** @var string|float $billedAmount */
      $billedAmount = $payload['billedAmount'];
      $amount = (int) ((float) $billedAmount * 100);
    } elseif (isset($payload['accountingAmount'])) {
      /** @var string|float $accountingAmount */
      $accountingAmount = $payload['accountingAmount'];
      $amount = (int) ((float) $accountingAmount * 100);
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
}
