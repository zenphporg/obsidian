<?php

use Zen\Obsidian\Exceptions\GatewayException;
use Zen\Obsidian\Exceptions\WebhookValidationException;
use Zen\Obsidian\Gateways\FakeGateway;

beforeEach(function (): void {
  FakeGateway::reset();
});

test('fake gateway returns name', function (): void {
  $gateway = new FakeGateway;

  expect($gateway->name())->toBe('fake');
});

test('fake gateway can create subscription', function (): void {
  $gateway = new FakeGateway;

  $result = $gateway->createSubscription([
    'token' => 'token_123',
    'plan' => 'plan_123',
    'amount' => 2999,
    'currency' => 'USD',
  ]);

  expect($result)
    ->toHaveKey('subscription_id')
    ->toHaveKey('transaction_id')
    ->toHaveKey('status', 'active')
    ->toHaveKey('payment_method')
    ->toHaveKey('next_billing_date');

  expect($result['subscription_id'])->toStartWith('fake_sub_');
  expect($result['transaction_id'])->toStartWith('fake_txn_');
  expect($result['payment_method'])->toHaveKey('last4', '4242');
});

test('fake gateway can cancel subscription', function (): void {
  $gateway = new FakeGateway;

  $result = $gateway->cancelSubscription('fake_sub_123');

  expect($result)->toBeTrue();
});

test('fake gateway can cancel existing subscription and update status', function (): void {
  $gateway = new FakeGateway;

  // Create a subscription first
  $created = $gateway->createSubscription([
    'token' => 'token_123',
    'plan' => 'plan_123',
    'amount' => 2999,
  ]);

  $subscriptionId = $created['subscription_id'];

  // Cancel it
  $result = $gateway->cancelSubscription($subscriptionId);

  expect($result)->toBeTrue();

  $subscriptions = FakeGateway::getSubscriptions();
  expect($subscriptions[$subscriptionId]['status'])->toBe('cancelled');
  expect($subscriptions[$subscriptionId])->toHaveKey('cancelled_at');
});

test('fake gateway can charge', function (): void {
  $gateway = new FakeGateway;

  $result = $gateway->charge([
    'token' => 'fake_token_123',
    'amount' => 2999,
    'currency' => 'USD',
  ]);

  expect($result)
    ->toHaveKey('id')
    ->toHaveKey('amount', 2999)
    ->toHaveKey('currency', 'USD')
    ->toHaveKey('status', 'succeeded');

  expect($result['id'])->toStartWith('fake_ch_');
});

test('fake gateway validates webhook signature with valid_signature', function (): void {
  $gateway = new FakeGateway;

  $result = $gateway->validateWebhookSignature('payload', 'valid_signature');

  expect($result)->toBeTrue();
});

test('fake gateway validates webhook signature with hmac', function (): void {
  $gateway = new FakeGateway;
  $payload = 'test_payload';
  $signature = hash_hmac('sha256', $payload, 'test_webhook_secret');

  $result = $gateway->validateWebhookSignature($payload, $signature);

  expect($result)->toBeTrue();
});

test('fake gateway throws exception for invalid webhook signature', function (): void {
  $gateway = new FakeGateway;

  expect(fn (): bool => $gateway->validateWebhookSignature('payload', 'invalid_signature'))
    ->toThrow(WebhookValidationException::class);
});

test('fake gateway parses webhook payload', function (): void {
  $gateway = new FakeGateway;

  $payload = [
    'eventType' => 'NewSaleSuccess',
    'subscriptionId' => 'fake_sub_123',
    'transactionId' => 'fake_txn_123',
    'billedAmount' => '29.99',
    'billedCurrency' => 'USD',
  ];

  $result = $gateway->parseWebhookPayload($payload);

  expect($result)
    ->toHaveKey('type', 'subscription.created')
    ->toHaveKey('original_type', 'NewSaleSuccess')
    ->toHaveKey('subscription_id', 'fake_sub_123')
    ->toHaveKey('transaction_id', 'fake_txn_123')
    ->toHaveKey('amount', 2999)
    ->toHaveKey('currency', 'USD');
});

test('fake gateway can get subscriptions', function (): void {
  $gateway = new FakeGateway;

  $gateway->createSubscription([
    'token' => 'token_123',
    'plan' => 'plan_123',
    'amount' => 2999,
  ]);

  $subscriptions = FakeGateway::getSubscriptions();

  expect($subscriptions)->toBeArray()
    ->and($subscriptions)->not->toBeEmpty();
});

test('fake gateway can get charges', function (): void {
  $gateway = new FakeGateway;

  $gateway->charge([
    'token' => 'token_123',
    'amount' => 1000,
  ]);

  $charges = FakeGateway::getCharges();

  expect($charges)->toBeArray()
    ->and($charges)->not->toBeEmpty();
});

test('fake gateway reset clears all state', function (): void {
  $gateway = new FakeGateway;

  $gateway->createSubscription([
    'token' => 'token_123',
    'amount' => 2999,
  ]);

  $gateway->charge([
    'token' => 'token_123',
    'amount' => 1000,
  ]);

  FakeGateway::reset();

  expect(FakeGateway::getSubscriptions())->toBeEmpty();
  expect(FakeGateway::getCharges())->toBeEmpty();
});

test('fake gateway shouldFail causes next operation to throw', function (): void {
  $gateway = new FakeGateway;

  FakeGateway::shouldFail('Test failure message', 500);

  expect(fn (): array => $gateway->createSubscription([
    'token' => 'token_123',
    'amount' => 2999,
  ]))->toThrow(GatewayException::class, 'Test failure message');
});

test('fake gateway shouldFail only affects next operation', function (): void {
  $gateway = new FakeGateway;

  FakeGateway::shouldFail('Test failure');

  // First call throws
  try {
    $gateway->createSubscription(['token' => 'token_123', 'amount' => 2999]);
  } catch (GatewayException) {
    // Expected
  }

  // Second call succeeds
  $result = $gateway->createSubscription(['token' => 'token_123', 'amount' => 2999]);

  expect($result)->toHaveKey('subscription_id');
});

test('fake gateway shouldFail affects charge operation', function (): void {
  $gateway = new FakeGateway;

  FakeGateway::shouldFail('Charge failed');

  expect(fn (): array => $gateway->charge([
    'token' => 'token_123',
    'amount' => 2999,
  ]))->toThrow(GatewayException::class, 'Charge failed');
});

test('fake gateway shouldFail affects cancel operation', function (): void {
  $gateway = new FakeGateway;

  FakeGateway::shouldFail('Cancel failed');

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Cancel failed');
});

test('fake gateway parses all ccbill event types', function (): void {
  $gateway = new FakeGateway;

  $eventMappings = [
    'NewSaleSuccess' => 'subscription.created',
    'NewSaleFailure' => 'subscription.failed',
    'RenewalSuccess' => 'payment.succeeded',
    'RenewalFailure' => 'payment.failed',
    'Cancellation' => 'subscription.cancelled',
    'Chargeback' => 'subscription.chargeback',
    'Refund' => 'payment.refunded',
    'Expiration' => 'subscription.expired',
    'UnknownEvent' => 'UnknownEvent', // Unknown events pass through
  ];

  foreach ($eventMappings as $ccbillType => $normalizedType) {
    $result = $gateway->parseWebhookPayload([
      'eventType' => $ccbillType,
      'subscriptionId' => 'sub_123',
    ]);

    expect($result['type'])->toBe($normalizedType);
    expect($result['original_type'])->toBe($ccbillType);
  }
});

test('fake gateway parses webhook with type key instead of eventType', function (): void {
  $gateway = new FakeGateway;

  $result = $gateway->parseWebhookPayload([
    'type' => 'custom_event',
    'subscription_id' => 'sub_123',
    'transaction_id' => 'txn_123',
    'amount' => 2999,
    'currency' => 'EUR',
  ]);

  expect($result)
    ->toHaveKey('type', 'custom_event')
    ->toHaveKey('subscription_id', 'sub_123')
    ->toHaveKey('transaction_id', 'txn_123')
    ->toHaveKey('amount', 2999)
    ->toHaveKey('currency', 'EUR');
});
