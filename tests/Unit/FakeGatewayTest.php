<?php

use Zen\Obsidian\Gateways\FakeGateway;

test('fake gateway returns name', function (): void {
  $gateway = new FakeGateway;

  expect($gateway->name())->toBe('fake');
});

test('fake gateway can create subscription', function (): void {
  $gateway = new FakeGateway;

  $result = $gateway->createSubscription([
    'plan_id' => 'plan_123',
    'payment_token' => 'token_123',
    'email' => 'test@example.com',
  ]);

  expect($result)
    ->toHaveKey('subscription_id')
    ->toHaveKey('payment_token')
    ->and($result['subscription_id'])->toStartWith('fake_sub_')
    ->and($result['payment_token'])->toStartWith('fake_token_');
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
    'plan_id' => 'plan_123',
    'payment_token' => 'token_123',
  ]);

  $subscriptionId = $created['subscription_id'];

  // Cancel it
  $result = $gateway->cancelSubscription($subscriptionId);

  expect($result)->toBeTrue();

  $subscriptions = $gateway->getSubscriptions();
  expect($subscriptions[$subscriptionId]['status'])->toBe('cancelled');
});

test('fake gateway can charge', function (): void {
  $gateway = new FakeGateway;

  $result = $gateway->charge([
    'amount' => 2999,
    'payment_token' => 'fake_token_123',
  ]);

  expect($result)
    ->toHaveKey('transaction_id')
    ->toHaveKey('amount')
    ->and($result['transaction_id'])->toStartWith('fake_txn_')
    ->and($result['amount'])->toBe(2999);
});

test('fake gateway validates webhook signature', function (): void {
  $gateway = new FakeGateway;

  $result = $gateway->validateWebhookSignature('payload', 'signature');

  expect($result)->toBeTrue();
});

test('fake gateway parses webhook payload', function (): void {
  $gateway = new FakeGateway;

  $payload = [
    'event_type' => 'subscription.created',
    'subscription_id' => 'fake_sub_123',
  ];

  $result = $gateway->parseWebhookPayload($payload);

  expect($result)
    ->toHaveKey('event_type')
    ->toHaveKey('subscription_id')
    ->and($result['event_type'])->toBe('subscription.created')
    ->and($result['subscription_id'])->toBe('fake_sub_123');
});

test('fake gateway can get subscriptions', function (): void {
  $gateway = new FakeGateway;

  $gateway->createSubscription([
    'plan_id' => 'plan_123',
    'payment_token' => 'token_123',
  ]);

  $subscriptions = $gateway->getSubscriptions();

  expect($subscriptions)->toBeArray()
    ->and($subscriptions)->not->toBeEmpty();
});

test('fake gateway can get charges', function (): void {
  $gateway = new FakeGateway;

  $gateway->charge([
    'amount' => 1000,
    'payment_token' => 'token_123',
  ]);

  $charges = $gateway->getCharges();

  expect($charges)->toBeArray()
    ->and($charges)->not->toBeEmpty();
});
