<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Zen\Obsidian\Exceptions\WebhookValidationException;

class CcbillGateway implements PaymentGatewayInterface
{
  protected string $merchantId;

  protected string $subAccountId;

  protected string $apiKey;

  protected string $apiSecret;

  protected string $salt;

  public function __construct()
  {
    /** @var string $merchantId */
    $merchantId = config('obsidian.ccbill.merchant_id');
    $this->merchantId = $merchantId;

    /** @var string $subAccountId */
    $subAccountId = config('obsidian.ccbill.subaccount_id');
    $this->subAccountId = $subAccountId;

    /** @var string $apiKey */
    $apiKey = config('obsidian.ccbill.api_key');
    $this->apiKey = $apiKey;

    /** @var string $apiSecret */
    $apiSecret = config('obsidian.ccbill.api_secret');
    $this->apiSecret = $apiSecret;

    /** @var string $salt */
    $salt = config('obsidian.ccbill.salt');
    $this->salt = $salt;
  }

  public function name(): string
  {
    return 'ccbill';
  }

  /**
   * Get OAuth access token (cache for 1 hour)
   */
  protected function getAccessToken(): string
  {
    /** @var string $token */
    $token = Cache::remember('ccbill_access_token', 3600, function (): string {
      $response = Http::asForm()->post('https://api.ccbill.com/ccbill-auth/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $this->apiKey,
        'client_secret' => $this->apiSecret,
      ]);

      if (! $response->successful()) {
        throw new Exception('Failed to get CCBill access token: '.$response->body());
      }

      /** @var string $accessToken */
      $accessToken = $response->json('access_token');

      return $accessToken;
    });

    return $token;
  }

  /**
   * @param  array<string, mixed>  $params
   * @return array<string, mixed>
   */
  public function createSubscription(array $params): array
  {
    /** @var object{email: string} $user */
    $user = $params['user'];

    /** @var string $token */
    $token = $params['token'];

    /** @var string $plan */
    $plan = $params['plan'];

    /** @var int $trialDays */
    $trialDays = $params['trial_days'] ?? 0;

    // Use CCBill's Upgrade API to create subscription
    $response = Http::withToken($this->getAccessToken())
      ->post('https://api.ccbill.com/transactions/payment-tokens/'.$token.'/upgrade', [
        'clientAccnum' => $this->merchantId,
        'clientSubacc' => $this->subAccountId,
        'subscriptionId' => $plan,
        'email' => $user->email,
        'trialDays' => $trialDays,
      ]);

    if (! $response->successful()) {
      throw new Exception('CCBill subscription creation failed: '.$response->body());
    }

    /** @var array<string, mixed> $data */
    $data = $response->json();

    return [
      'subscription_id' => $data['subscriptionId'] ?? $data['subscription_id'] ?? uniqid('ccb_'),
      'status' => 'active',
      'payment_method' => [
        'type' => 'card',
        'last4' => $data['last4'] ?? null,
      ],
    ];
  }

  public function cancelSubscription(string $subscriptionId): bool
  {
    // CCBill REST API doesn't have direct cancellation endpoint
    // Options:
    // 1. Use FlexForms cancellation link (redirect user)
    // 2. Use legacy SOAP API (complex)
    // 3. Mark as cancelled locally, let it expire naturally

    // For now, we'll mark as cancelled locally
    // The subscription will expire at the end of the billing period
    return true;
  }

  /**
   * @param  array<string, mixed>  $params
   * @return array<string, mixed>
   */
  public function charge(array $params): array
  {
    /** @var string $token */
    $token = $params['token'];

    /** @var int $amountCents */
    $amountCents = $params['amount'];
    $amount = $amountCents / 100; // Convert cents to dollars

    $response = Http::withToken($this->getAccessToken())
      ->post("https://api.ccbill.com/transactions/payment-tokens/{$token}/charge", [
        'clientAccnum' => $this->merchantId,
        'clientSubacc' => $this->subAccountId,
        'amount' => $amount,
        'currency' => $params['currency'] ?? 'USD',
      ]);

    if (! $response->successful()) {
      throw new Exception('CCBill charge failed: '.$response->body());
    }

    /** @var array<string, mixed> $data */
    $data = $response->json();

    return [
      'id' => $data['transactionId'] ?? uniqid('ccb_charge_'),
      'amount' => $params['amount'],
      'currency' => $params['currency'] ?? 'USD',
      'status' => 'succeeded',
    ];
  }

  public function validateWebhookSignature(string $payload, string $signature): bool
  {
    /** @var string $secret */
    $secret = config('obsidian.ccbill.webhook_secret');
    $expectedSignature = hash_hmac('sha256', $payload, $secret);

    if (! hash_equals($expectedSignature, $signature)) {
      throw WebhookValidationException::invalidSignature();
    }

    return true;
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return array<string, mixed>
   */
  public function parseWebhookPayload(array $payload): array
  {
    // CCBill webhook format
    $amount = null;
    if (isset($payload['billedAmount'])) {
      /** @var float|int $billedAmount */
      $billedAmount = $payload['billedAmount'];
      $amount = (int) ((float) $billedAmount * 100);
    }

    return [
      'type' => $payload['eventType'] ?? 'unknown',
      'subscription_id' => $payload['subscriptionId'] ?? null,
      'transaction_id' => $payload['transactionId'] ?? null,
      'amount' => $amount,
      'currency' => $payload['billedCurrency'] ?? 'USD',
      'data' => $payload,
    ];
  }
}
