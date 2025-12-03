<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Zen\Obsidian\Exceptions\GatewayException;
use Zen\Obsidian\Exceptions\WebhookValidationException;
use Zen\Obsidian\Gateways\CcbillGateway;

beforeEach(function (): void {
  config([
    'obsidian.ccbill.merchant_id' => '900100',
    'obsidian.ccbill.subaccount_id' => '0000',
    'obsidian.ccbill.merchant_app_id' => 'test_app_id',
    'obsidian.ccbill.secret_key' => 'test_secret_key',
    'obsidian.ccbill.datalink_username' => 'test_datalink_user',
    'obsidian.ccbill.datalink_password' => 'test_datalink_pass',
    'obsidian.ccbill.webhook_secret' => 'test_webhook_secret',
  ]);

  Cache::forget('ccbill_backend_access_token');
});

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
    'billedAmount' => '29.99',
    'billedCurrency' => 'USD',
  ]);

  expect($parsed)
    ->toHaveKey('type', 'subscription.created')
    ->toHaveKey('original_type', 'NewSaleSuccess')
    ->toHaveKey('subscription_id', 'sub_123')
    ->toHaveKey('transaction_id', 'txn_456')
    ->toHaveKey('amount', 2999)
    ->toHaveKey('currency', 'USD');
});

test('ccbill gateway normalizes webhook event types', function (): void {
  $gateway = new CcbillGateway;

  $events = [
    'NewSaleSuccess' => 'subscription.created',
    'NewSaleFailure' => 'subscription.failed',
    'RenewalSuccess' => 'payment.succeeded',
    'RenewalFailure' => 'payment.failed',
    'Cancellation' => 'subscription.cancelled',
    'Chargeback' => 'subscription.chargeback',
    'Refund' => 'payment.refunded',
    'Expiration' => 'subscription.expired',
  ];

  foreach ($events as $ccbillEvent => $normalizedEvent) {
    $parsed = $gateway->parseWebhookPayload(['eventType' => $ccbillEvent]);
    expect($parsed['type'])->toBe($normalizedEvent);
    expect($parsed['original_type'])->toBe($ccbillEvent);
  }
});

test('ccbill gateway can parse webhook payload without amount', function (): void {
  $gateway = new CcbillGateway;

  $parsed = $gateway->parseWebhookPayload([
    'eventType' => 'Cancellation',
    'subscriptionId' => 'sub_123',
  ]);

  expect($parsed)
    ->toHaveKey('type', 'subscription.cancelled')
    ->toHaveKey('subscription_id', 'sub_123')
    ->toHaveKey('amount')
    ->and($parsed['amount'])->toBeNull();
});

test('ccbill gateway can cancel subscription via datalink', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>1</results>'),
  ]);

  $gateway = new CcbillGateway;

  $result = $gateway->cancelSubscription('sub_123');

  expect($result)->toBeTrue();
});

test('ccbill gateway returns true when datalink credentials not configured', function (): void {
  config([
    'obsidian.ccbill.datalink_username' => '',
    'obsidian.ccbill.datalink_password' => '',
  ]);

  $gateway = new CcbillGateway;

  $result = $gateway->cancelSubscription('sub_123');

  expect($result)->toBeTrue();
});

test('ccbill gateway throws exception on datalink cancellation failure', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-3</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'No record found for the given subscription');
});

test('ccbill gateway can create subscription', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
      'token_type' => 'Bearer',
      'expires_in' => 3600,
    ]),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'approved' => true,
      'subscriptionId' => 'ccb_sub_123456',
      'paymentUniqueId' => 'txn_123456',
      'last4' => '4242',
      'nextRenewalDate' => '2025-01-03',
    ]),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  $result = $gateway->createSubscription([
    'user' => $user,
    'token' => 'payment_token_123',
    'plan' => 'plan_monthly',
    'amount' => 2999,
    'currency' => 'USD',
    'trial_days' => 7,
  ]);

  expect($result)
    ->toHaveKey('subscription_id', 'ccb_sub_123456')
    ->toHaveKey('transaction_id', 'txn_123456')
    ->toHaveKey('status', 'active')
    ->toHaveKey('payment_method');

  expect($result['payment_method'])
    ->toHaveKey('type', 'card')
    ->toHaveKey('last4', '4242');

  Http::assertSent(fn ($request): bool => $request->url() === 'https://api.ccbill.com/ccbill-auth/oauth/token');
});

test('ccbill gateway throws exception when subscription is declined', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ]),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'approved' => false,
      'declineCode' => 100,
      'declineText' => 'Card declined',
    ]),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  expect(fn (): array => $gateway->createSubscription([
    'user' => $user,
    'token' => 'invalid_token',
    'plan' => 'plan_monthly',
    'amount' => 2999,
    'currency' => 'USD',
  ]))->toThrow(GatewayException::class, 'CCBill declined the transaction: Card declined');
});

test('ccbill gateway can charge', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ]),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'approved' => true,
      'paymentUniqueId' => 'txn_789',
      'subscriptionId' => 'sub_789',
    ]),
  ]);

  $gateway = new CcbillGateway;

  $result = $gateway->charge([
    'token' => 'payment_token_123',
    'amount' => 2999,
    'currency' => 'USD',
  ]);

  expect($result)
    ->toHaveKey('id', 'txn_789')
    ->toHaveKey('amount', 2999)
    ->toHaveKey('currency', 'USD')
    ->toHaveKey('status', 'succeeded');
});

test('ccbill gateway throws exception when charge is declined', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ]),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'approved' => false,
      'declineCode' => 200,
      'declineText' => 'Insufficient funds',
    ]),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): array => $gateway->charge([
    'token' => 'payment_token_123',
    'amount' => 2999,
    'currency' => 'USD',
  ]))->toThrow(GatewayException::class, 'CCBill declined the charge: Insufficient funds');
});

test('ccbill gateway validates webhook signature correctly', function (): void {
  $gateway = new CcbillGateway;
  $payload = 'test_payload';
  $signature = hash_hmac('sha256', $payload, 'test_webhook_secret');

  $result = $gateway->validateWebhookSignature($payload, $signature);

  expect($result)->toBeTrue();
});

test('ccbill gateway throws exception for invalid webhook signature', function (): void {
  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->validateWebhookSignature('test_payload', 'invalid_signature'))
    ->toThrow(WebhookValidationException::class, 'Invalid webhook signature');
});

test('ccbill gateway throws exception when webhook secret not configured', function (): void {
  config(['obsidian.ccbill.webhook_secret' => '']);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->validateWebhookSignature('test_payload', 'signature'))
    ->toThrow(WebhookValidationException::class, 'Webhook secret not configured');
});

test('ccbill gateway throws exception when oauth token request fails', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'error' => 'Invalid credentials',
    ], 401),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  expect(fn (): array => $gateway->createSubscription([
    'user' => $user,
    'token' => 'payment_token_123',
    'plan' => 'plan_monthly',
    'amount' => 2999,
    'currency' => 'USD',
  ]))->toThrow(GatewayException::class);
});

test('ccbill gateway throws exception for unsupported currency', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ]),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  expect(fn (): array => $gateway->createSubscription([
    'user' => $user,
    'token' => 'payment_token_123',
    'plan' => 'plan_monthly',
    'amount' => 2999,
    'currency' => 'XYZ',
  ]))->toThrow(GatewayException::class, 'Unsupported currency: XYZ');
});

test('ccbill gateway caches access token', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ]),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'approved' => true,
      'paymentUniqueId' => 'txn_123',
      'subscriptionId' => 'sub_123',
    ]),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  // First call
  $gateway->createSubscription([
    'user' => $user,
    'token' => 'token1',
    'amount' => 2999,
    'currency' => 'USD',
  ]);

  // Second call - should use cached token
  $gateway->createSubscription([
    'user' => $user,
    'token' => 'token2',
    'amount' => 2999,
    'currency' => 'USD',
  ]);

  // OAuth endpoint should only be called once
  Http::assertSentCount(3); // 1 oauth + 2 subscription calls
});

test('ccbill gateway throws exception for invalid access token response', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => null, // Invalid - not a string
    ]),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  expect(fn (): array => $gateway->createSubscription([
    'user' => $user,
    'token' => 'token1',
    'amount' => 2999,
    'currency' => 'USD',
  ]))->toThrow(GatewayException::class, 'Invalid access token response from CCBill');
});

test('ccbill gateway can create subscription with recurring billing', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ]),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'approved' => true,
      'subscriptionId' => 'sub_123',
      'paymentUniqueId' => 'txn_123',
    ]),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  $result = $gateway->createSubscription([
    'user' => $user,
    'token' => 'token1',
    'amount' => 999,
    'currency' => 'USD',
    'recurring_amount' => 2999,
    'recurring_period' => 30,
    'num_rebills' => 12,
  ]);

  expect($result)->toHaveKey('subscription_id', 'sub_123');

  Http::assertSent(function ($request): bool {
    $body = $request->data();

    return isset($body['recurringPrice']) && $body['recurringPrice'] === 29.99;
  });
});

test('ccbill gateway handles subscription creation http failure', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ]),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'message' => 'Server error',
    ], 500),
  ]);

  $gateway = new CcbillGateway;

  $user = new stdClass;
  $user->email = 'test@example.com';

  expect(fn (): array => $gateway->createSubscription([
    'user' => $user,
    'token' => 'token1',
    'amount' => 2999,
    'currency' => 'USD',
  ]))->toThrow(GatewayException::class, 'Subscription creation failed');
});

test('ccbill gateway handles datalink http failure', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('Connection failed', 500),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'CCBill DataLink request failed');
});

test('ccbill gateway handles unknown datalink response', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<unexpected>format</unexpected>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'CCBill cancellation failed: Unknown response');
});

test('ccbill gateway handles datalink error code 0', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>0</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'The requested action failed');
});

test('ccbill gateway handles datalink error code -3', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-3</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'No record found for the given subscription');
});

test('ccbill gateway handles datalink unknown error code', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-99</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Unknown error code: -99');
});

test('ccbill gateway handles datalink error code -1', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-1</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Invalid or missing authentication credentials');
});

test('ccbill gateway handles datalink error code -2', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-2</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Invalid subscription ID or unsupported subscription type');
});

test('ccbill gateway handles datalink error code -4', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-4</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Subscription does not belong to this account');
});

test('ccbill gateway handles datalink error code -5', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-5</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Invalid or missing action arguments');
});

test('ccbill gateway handles datalink error code -6', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-6</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Invalid action requested');
});

test('ccbill gateway handles datalink error code -7', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-7</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Internal or database error');
});

test('ccbill gateway handles datalink error code -8', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-8</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'IP address not in valid range');
});

test('ccbill gateway handles datalink error code -9', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-9</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Account deactivated or not permitted for this action');
});

test('ccbill gateway handles datalink error code -10', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-10</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'DataLink not set up for this account');
});

test('ccbill gateway handles datalink error code -12', function (): void {
  Http::fake([
    'datalink.ccbill.com/*' => Http::response('<results>-12</results>'),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'Too many failed login attempts, wait 1 hour');
});

test('ccbill gateway handles charge http failure', function (): void {
  Http::fake([
    'api.ccbill.com/ccbill-auth/oauth/token' => Http::response([
      'access_token' => 'test_access_token',
    ]),
    'api.ccbill.com/transactions/payment-tokens/*' => Http::response([
      'error' => 'Service unavailable',
    ], 503),
  ]);

  $gateway = new CcbillGateway;

  expect(fn (): array => $gateway->charge([
    'token' => 'token1',
    'amount' => 2999,
    'currency' => 'USD',
  ]))->toThrow(GatewayException::class, 'Charge failed');
});

test('ccbill gateway parses webhook with accountingAmount', function (): void {
  $gateway = new CcbillGateway;

  $parsed = $gateway->parseWebhookPayload([
    'eventType' => 'RenewalSuccess',
    'subscriptionId' => 'sub_123',
    'accountingAmount' => '20.00',
    'accountingCurrency' => 'EUR',
  ]);

  expect($parsed)
    ->toHaveKey('amount', 2000)
    ->toHaveKey('currency', 'EUR');
});
