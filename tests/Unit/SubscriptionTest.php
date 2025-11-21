<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\User;

test('subscription belongs to user', function (): void {
  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'fake',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  expect($subscription->user)->toBeInstanceOf(User::class)
    ->and($subscription->user->id)->toBe($user->id);
});

test('subscription can be cancelled with API failure', function (): void {
  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'segpay',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  // Mock HTTP to fail the cancellation
  Http::fake([
    'api.segpay.com/cancel' => Http::response([
      'error' => 'API Error',
    ], 500),
  ]);

  try {
    $subscription->cancel();
    expect(true)->toBeFalse('Should have thrown an exception');
  } catch (Exception $e) {
    // Exception should be thrown
    expect($e->getMessage())->toContain('SegPay cancellation failed');
  }

  // Subscription should still be marked as cancelled
  $subscription->refresh();
  expect($subscription->status)->toBe('cancelled')
    ->and($subscription->ends_at)->not->toBeNull();
});
