<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

class FakeGateway implements PaymentGatewayInterface
{
  /** @var array<string, array<string, mixed>> */
  protected array $subscriptions = [];

  /** @var array<string, array<string, mixed>> */
  protected array $charges = [];

  public function name(): string
  {
    return 'fake';
  }

  /**
   * @param  array<string, mixed>  $params
   * @return array<string, mixed>
   */
  public function createSubscription(array $params): array
  {
    $subscriptionId = 'fake_sub_'.uniqid();
    $paymentToken = 'fake_token_'.uniqid();

    /** @var int $trialDays */
    $trialDays = $params['trial_days'] ?? 0;

    $this->subscriptions[$subscriptionId] = [
      'id' => $subscriptionId,
      'plan_id' => $params['plan_id'] ?? $params['plan'] ?? null,
      'payment_token' => $params['payment_token'] ?? $params['token'] ?? $paymentToken,
      'status' => 'active',
      'trial_days' => $trialDays,
    ];

    return [
      'subscription_id' => $subscriptionId,
      'payment_token' => $paymentToken,
      'status' => 'active',
      'next_billing_date' => now()->addDays($trialDays ?: 30)->toDateTimeString(),
    ];
  }

  public function cancelSubscription(string $subscriptionId): bool
  {
    // Always return true for fake gateway
    if (isset($this->subscriptions[$subscriptionId])) {
      $this->subscriptions[$subscriptionId]['status'] = 'cancelled';
    }

    return true;
  }

  /**
   * @param  array<string, mixed>  $params
   * @return array<string, mixed>
   */
  public function charge(array $params): array
  {
    $transactionId = 'fake_txn_'.uniqid();

    $this->charges[$transactionId] = [
      'id' => $transactionId,
      'amount' => $params['amount'],
      'currency' => $params['currency'] ?? 'USD',
      'payment_token' => $params['payment_token'] ?? $params['token'] ?? null,
      'status' => 'succeeded',
    ];

    return [
      'transaction_id' => $transactionId,
      'status' => 'succeeded',
      'amount' => $params['amount'],
    ];
  }

  public function validateWebhookSignature(string $payload, string $signature): bool
  {
    // For testing, always return true
    return true;
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return array<string, mixed>
   */
  public function parseWebhookPayload(array $payload): array
  {
    return [
      'event_type' => $payload['event_type'] ?? 'unknown',
      'subscription_id' => $payload['subscription_id'] ?? null,
      'transaction_id' => $payload['transaction_id'] ?? null,
      'amount' => $payload['amount'] ?? 0,
      'currency' => $payload['currency'] ?? 'USD',
      'data' => $payload,
    ];
  }

  /**
   * Get all subscriptions (for testing)
   *
   * @return array<string, array<string, mixed>>
   */
  public function getSubscriptions(): array
  {
    return $this->subscriptions;
  }

  /**
   * Get all charges (for testing)
   *
   * @return array<string, array<string, mixed>>
   */
  public function getCharges(): array
  {
    return $this->charges;
  }
}
