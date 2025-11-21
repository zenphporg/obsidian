<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

use Exception;
use Illuminate\Support\Facades\Http;
use Zen\Obsidian\Exceptions\WebhookValidationException;

class SegpayGateway implements PaymentGatewayInterface
{
  protected string $merchantId;

  protected string $packageId;

  protected string $userId;

  protected string $apiKey;

  public function __construct()
  {
    /** @var string $merchantId */
    $merchantId = config('obsidian.segpay.merchant_id');
    $this->merchantId = $merchantId;

    /** @var string $packageId */
    $packageId = config('obsidian.segpay.package_id');
    $this->packageId = $packageId;

    /** @var string $userId */
    $userId = config('obsidian.segpay.user_id');
    $this->userId = $userId;

    /** @var string $apiKey */
    $apiKey = config('obsidian.segpay.api_key');
    $this->apiKey = $apiKey;
  }

  public function name(): string
  {
    return 'segpay';
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

    /** @var int $trialDays */
    $trialDays = $params['trial_days'] ?? 0;

    // Use SegPay Purchase API
    $response = Http::withHeaders([
      'X-Authentication' => $this->apiKey,
    ])->post('https://api.segpay.com/purchase', [
      'merchant-id' => $this->merchantId,
      'package-id' => $this->packageId,
      'user-id' => $this->userId,
      'payment-token' => $token,
      'email' => $user->email,
      'trial-days' => $trialDays,
    ]);

    if (! $response->successful()) {
      throw new Exception('SegPay subscription creation failed: '.$response->body());
    }

    /** @var array<string, mixed> $data */
    $data = $response->json();

    return [
      'subscription_id' => $data['purchase-id'] ?? uniqid('seg_'),
      'status' => 'active',
      'payment_method' => [
        'type' => 'card',
        'last4' => $data['card-last4'] ?? null,
      ],
    ];
  }

  public function cancelSubscription(string $subscriptionId): bool
  {
    // SegPay cancellation via API
    $response = Http::withHeaders([
      'X-Authentication' => $this->apiKey,
    ])->post('https://api.segpay.com/cancel', [
      'merchant-id' => $this->merchantId,
      'purchase-id' => $subscriptionId,
    ]);

    if (! $response->successful()) {
      throw new Exception('SegPay cancellation failed: '.$response->body());
    }

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

    $response = Http::withHeaders([
      'X-Authentication' => $this->apiKey,
    ])->post('https://api.segpay.com/charge', [
      'merchant-id' => $this->merchantId,
      'payment-token' => $token,
      'amount' => $amount,
      'currency' => $params['currency'] ?? 'USD',
    ]);

    if (! $response->successful()) {
      throw new Exception('SegPay charge failed: '.$response->body());
    }

    /** @var array<string, mixed> $data */
    $data = $response->json();

    return [
      'id' => $data['transaction-id'] ?? uniqid('seg_charge_'),
      'amount' => $params['amount'],
      'currency' => $params['currency'] ?? 'USD',
      'status' => 'succeeded',
    ];
  }

  public function validateWebhookSignature(string $payload, string $signature): bool
  {
    /** @var string $secret */
    $secret = config('obsidian.segpay.webhook_secret');
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
    // SegPay postback format
    $amount = null;
    if (isset($payload['amount'])) {
      /** @var float|int $payloadAmount */
      $payloadAmount = $payload['amount'];
      $amount = (int) ((float) $payloadAmount * 100);
    }

    return [
      'type' => $payload['action'] ?? 'unknown',
      'subscription_id' => $payload['purchase-id'] ?? null,
      'transaction_id' => $payload['transaction-id'] ?? null,
      'amount' => $amount,
      'currency' => $payload['currency'] ?? 'USD',
      'data' => $payload,
    ];
  }
}
