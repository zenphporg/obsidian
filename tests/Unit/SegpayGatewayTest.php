<?php

use Illuminate\Support\Facades\Http;
use Zen\Obsidian\Exceptions\WebhookValidationException;
use Zen\Obsidian\Gateways\SegpayGateway;

test('segpay gateway returns name', function (): void {
  $gateway = new SegpayGateway;

  expect($gateway->name())->toBe('segpay');
});

test('segpay gateway can parse webhook payload', function (): void {
  $gateway = new SegpayGateway;

  $parsed = $gateway->parseWebhookPayload([
    'action' => 'initial',
    'purchase-id' => 'pur_123',
    'transaction-id' => 'txn_456',
    'amount' => 29.99,
    'currency' => 'USD',
  ]);

  expect($parsed)
    ->toHaveKey('type')
    ->toHaveKey('subscription_id')
    ->toHaveKey('transaction_id')
    ->toHaveKey('amount')
    ->toHaveKey('currency')
    ->and($parsed['type'])->toBe('initial')
    ->and($parsed['subscription_id'])->toBe('pur_123')
    ->and($parsed['transaction_id'])->toBe('txn_456')
    ->and($parsed['amount'])->toBe(2999)
    ->and($parsed['currency'])->toBe('USD');
});

test('segpay gateway can parse webhook payload without amount', function (): void {
  $gateway = new SegpayGateway;

  $parsed = $gateway->parseWebhookPayload([
    'action' => 'cancel',
    'purchase-id' => 'pur_123',
  ]);

  expect($parsed)
    ->toHaveKey('type')
    ->toHaveKey('subscription_id')
    ->toHaveKey('amount')
    ->and($parsed['type'])->toBe('cancel')
    ->and($parsed['subscription_id'])->toBe('pur_123')
    ->and($parsed['amount'])->toBeNull();
});

test('segpay gateway can create subscription', function (): void {
  Http::fake([
    'api.segpay.com/purchase' => Http::response([
      'purchase-id' => 'seg_pur_123456',
      'card-last4' => '4242',
      'status' => 'active',
    ], 200),
  ]);

  $gateway = new SegpayGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  $result = $gateway->createSubscription([
    'user' => $user,
    'token' => 'payment_token_123',
    'plan' => 'plan_monthly',
    'trial_days' => 7,
  ]);

  expect($result)
    ->toHaveKey('subscription_id')
    ->toHaveKey('status')
    ->toHaveKey('payment_method')
    ->and($result['subscription_id'])->toBe('seg_pur_123456')
    ->and($result['status'])->toBe('active')
    ->and($result['payment_method']['type'])->toBe('card')
    ->and($result['payment_method']['last4'])->toBe('4242');
});

test('segpay gateway can cancel subscription', function (): void {
  Http::fake([
    'api.segpay.com/cancel' => Http::response([
      'status' => 'cancelled',
    ], 200),
  ]);

  $gateway = new SegpayGateway;

  $result = $gateway->cancelSubscription('pur_123');

  expect($result)->toBeTrue();
});

test('segpay gateway can charge', function (): void {
  Http::fake([
    'api.segpay.com/charge' => Http::response([
      'transaction-id' => 'txn_789',
      'status' => 'approved',
    ], 200),
  ]);

  $gateway = new SegpayGateway;

  $result = $gateway->charge([
    'token' => 'payment_token_123',
    'amount' => 2999,
    'currency' => 'USD',
  ]);

  expect($result)
    ->toHaveKey('id')
    ->toHaveKey('amount')
    ->toHaveKey('currency')
    ->toHaveKey('status')
    ->and($result['id'])->toBe('txn_789')
    ->and($result['amount'])->toBe(2999)
    ->and($result['currency'])->toBe('USD')
    ->and($result['status'])->toBe('succeeded');
});

test('segpay gateway validates webhook signature correctly', function (): void {
  config(['obsidian.segpay.webhook_secret' => 'test_secret']);

  $gateway = new SegpayGateway;
  $payload = 'test_payload';
  $signature = hash_hmac('sha256', $payload, 'test_secret');

  $result = $gateway->validateWebhookSignature($payload, $signature);

  expect($result)->toBeTrue();
});

test('segpay gateway throws exception for invalid webhook signature', function (): void {
  config(['obsidian.segpay.webhook_secret' => 'test_secret']);

  $gateway = new SegpayGateway;

  try {
    $gateway->validateWebhookSignature('test_payload', 'invalid_signature');
    expect(true)->toBeFalse('Should have thrown an exception');
  } catch (WebhookValidationException $e) {
    expect($e->getMessage())->toBe('The webhook signature is invalid.');
  }
});

test('segpay gateway throws exception when subscription creation fails', function (): void {
  Http::fake([
    'api.segpay.com/purchase' => Http::response([
      'error' => 'Invalid payment token',
    ], 400),
  ]);

  $gateway = new SegpayGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  $gateway->createSubscription([
    'user' => $user,
    'token' => 'invalid_token',
    'plan' => 'plan_monthly',
  ]);
})->throws(Exception::class);

test('segpay gateway throws exception when cancellation fails', function (): void {
  Http::fake([
    'api.segpay.com/cancel' => Http::response([
      'error' => 'Subscription not found',
    ], 404),
  ]);

  $gateway = new SegpayGateway;

  $gateway->cancelSubscription('invalid_sub_id');
})->throws(Exception::class);

test('segpay gateway throws exception when charge fails', function (): void {
  Http::fake([
    'api.segpay.com/charge' => Http::response([
      'error' => 'Insufficient funds',
    ], 402),
  ]);

  $gateway = new SegpayGateway;

  $gateway->charge([
    'token' => 'payment_token_123',
    'amount' => 2999,
  ]);
})->throws(Exception::class);
