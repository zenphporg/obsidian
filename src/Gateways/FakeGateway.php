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

  protected static bool $shouldFail = false;

  protected static ?string $failureMessage = null;

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
   * @param  array<string, mixed>  $params
   * @return array<string, mixed>
   */
  public function createSubscription(array $params): array
  {
    $this->checkForFailure();

    $subscriptionId = 'fake_sub_'.uniqid();
    $transactionId = 'fake_txn_'.uniqid();

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
   * @param  array<string, mixed>  $params
   * @return array<string, mixed>
   */
  public function charge(array $params): array
  {
    $this->checkForFailure();

    $chargeId = 'fake_ch_'.uniqid();

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
   * @param  array<string, mixed>  $payload
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
      /** @var string|float $billedAmount */
      $billedAmount = $payload['billedAmount'];
      $amount = (int) ((float) $billedAmount * 100);
    } elseif (isset($payload['amount'])) {
      /** @var int $payloadAmount */
      $payloadAmount = $payload['amount'];
      $amount = $payloadAmount;
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
