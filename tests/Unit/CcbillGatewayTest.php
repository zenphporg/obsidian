<?php

use Illuminate\Support\Facades\Http;
use Zen\Obsidian\Exceptions\WebhookValidationException;
use Zen\Obsidian\Gateways\CcbillGateway;

test('ccbill gateway returns name', function (): void {
  $gateway = new CcbillGateway;

  expect($gateway->name())->toBe('ccbill');
});

test('ccbill gateway can parse webhook payload', function (): void {
  $gateway = new CcbillGateway;

  $parsed = $gateway->parseWebhookPayload([
    'eventType' => 'NewSaleSuccess',
    'subscriptionId' => 'sub_123',
    'transactionId' => 'txn_456',
    'billedAmount' => 29.99,
    'billedCurrency' => 'USD',
  ]);

  expect($parsed)
    ->toHaveKey('type')
    ->toHaveKey('subscription_id')
    ->toHaveKey('transaction_id')
    ->toHaveKey('amount')
    ->toHaveKey('currency')
    ->and($parsed['type'])->toBe('NewSaleSuccess')
    ->and($parsed['subscription_id'])->toBe('sub_123')
    ->and($parsed['transaction_id'])->toBe('txn_456')
    ->and($parsed['amount'])->toBe(2999)
    ->and($parsed['currency'])->toBe('USD');
});

test('ccbill gateway can parse webhook payload without amount', function (): void {
  $gateway = new CcbillGateway;

  $parsed = $gateway->parseWebhookPayload([
    'eventType' => 'Cancellation',
    'subscriptionId' => 'sub_123',
  ]);

  expect($parsed)
    ->toHaveKey('type')
    ->toHaveKey('subscription_id')
    ->toHaveKey('amount')
    ->and($parsed['type'])->toBe('Cancellation')
    ->and($parsed['subscription_id'])->toBe('sub_123')
    ->and($parsed['amount'])->toBeNull();
});

test('ccbill gateway can cancel subscription', function (): void {
  $gateway = new CcbillGateway;

  $result = $gateway->cancelSubscription('sub_123');

  expect($result)->toBeTrue();
});

test('ccbill gateway can create subscription', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
      'token_type' => 'Bearer',
      'expires_in' => 3600,
    ], 200),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'subscriptionId' => 'ccb_sub_123456',
      'last4' => '4242',
      'status' => 'active',
    ], 200),
  ]);

  $gateway = new CcbillGateway;

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
    ->and($result['subscription_id'])->toBe('ccb_sub_123456')
    ->and($result['status'])->toBe('active')
    ->and($result['payment_method']['type'])->toBe('card')
    ->and($result['payment_method']['last4'])->toBe('4242');

  Http::assertSent(fn ($request): bool => $request->url() === 'https://api.ccbill.com/ccbill-auth/oauth/token');
});

test('ccbill gateway can charge', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ], 200),
    'api.ccbill.com/transactions/payment-tokens/*/charge' => Http::response([
      'transactionId' => 'txn_789',
      'status' => 'approved',
    ], 200),
  ]);

  $gateway = new CcbillGateway;

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

test('ccbill gateway validates webhook signature correctly', function (): void {
  config(['obsidian.ccbill.webhook_secret' => 'test_secret']);

  $gateway = new CcbillGateway;
  $payload = 'test_payload';
  $signature = hash_hmac('sha256', $payload, 'test_secret');

  $result = $gateway->validateWebhookSignature($payload, $signature);

  expect($result)->toBeTrue();
});

test('ccbill gateway throws exception for invalid webhook signature', function (): void {
  config(['obsidian.ccbill.webhook_secret' => 'test_secret']);

  $gateway = new CcbillGateway;

  try {
    $gateway->validateWebhookSignature('test_payload', 'invalid_signature');
    expect(true)->toBeFalse('Should have thrown an exception');
  } catch (WebhookValidationException $e) {
    expect($e->getMessage())->toBe('The webhook signature is invalid.');
  }
});

test('ccbill gateway throws exception when subscription creation fails', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ], 200),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'error' => 'Invalid payment token',
    ], 400),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  $gateway->createSubscription([
    'user' => $user,
    'token' => 'invalid_token',
    'plan' => 'plan_monthly',
  ]);
})->throws(Exception::class);

test('ccbill gateway throws exception when charge fails', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ], 200),
    'api.ccbill.com/transactions/payment-tokens/*/charge' => Http::response([
      'error' => 'Insufficient funds',
    ], 402),
  ]);

  $gateway = new CcbillGateway;

  $gateway->charge([
    'token' => 'payment_token_123',
    'amount' => 2999,
  ]);
})->throws(Exception::class);

test('ccbill gateway throws exception when oauth token request fails', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'error' => 'Invalid credentials',
    ], 401),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  $gateway->createSubscription([
    'user' => $user,
    'token' => 'payment_token_123',
    'plan' => 'plan_monthly',
  ]);
})->throws(Exception::class);
