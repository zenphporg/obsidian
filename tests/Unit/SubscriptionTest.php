<?php

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

test('subscription can be cancelled with API failure for segpay', function (): void {
  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'segpay',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  // SegPay gateway is not yet implemented, so it will throw GatewayException
  try {
    $subscription->cancel();
    expect(true)->toBeFalse('Should have thrown an exception');
  } catch (Exception $e) {
    // Exception should be thrown - SegPay is not implemented
    expect($e->getMessage())->toContain('SegPay gateway is not yet implemented');
  }

  // Subscription should still be marked as cancelled locally
  $subscription->refresh();
  expect($subscription->status)->toBe('cancelled')
    ->and($subscription->ends_at)->not->toBeNull();
});
