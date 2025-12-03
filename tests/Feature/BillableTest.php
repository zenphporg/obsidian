<?php

use Tests\Fixtures\User;

test('user can create multiple subscriptions', function (): void {
  $user = User::factory()->create();

  $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  $user->newSubscription('premium', 'plan_456')
    ->gateway('fake')
    ->create('fake_token_456');

  expect($user->subscriptions)->toHaveCount(2);
});

test('user can get specific subscription by name', function (): void {
  $user = User::factory()->create();

  $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  $user->newSubscription('premium', 'plan_456')
    ->gateway('fake')
    ->create('fake_token_456');

  $default = $user->subscription('default');
  $premium = $user->subscription('premium');

  expect($default->name)->toBe('default')
    ->and($premium->name)->toBe('premium');
});

test('user can check subscription status by name', function (): void {
  $user = User::factory()->create();

  expect($user->subscribed('default'))->toBeFalse();

  $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  expect($user->subscribed('default'))->toBeTrue()
    ->and($user->subscribed('premium'))->toBeFalse();
});

test('user can charge one-time payment', function (): void {
  $user = User::factory()->create();

  $result = $user->charge(2999, 'fake_token_123');

  expect($result)
    ->toHaveKey('id')
    ->toHaveKey('amount')
    ->and($result['amount'])->toBe(2999);
});

test('user can update default payment method', function (): void {
  $user = User::factory()->create();

  $subscription = $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  $user->updateDefaultPaymentMethod('new_token_456');

  $subscription->refresh();

  expect($subscription->gateway_payment_token)->toBe('new_token_456');
});

test('user can check generic trial status', function (): void {
  $user = User::factory()->create([
    'trial_ends_at' => now()->addDays(7),
  ]);

  expect($user->onGenericTrial())->toBeTrue();

  $user->trial_ends_at = now()->subDay();
  $user->save();

  expect($user->onGenericTrial())->toBeFalse();
});
