<?php

use Tests\Fixtures\User;
use Zen\Obsidian\Subscription;

test('user can create a subscription', function (): void {
  $user = User::factory()->create();

  $subscription = $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  expect($subscription)
    ->toBeInstanceOf(Subscription::class)
    ->and($subscription->name)->toBe('default')
    ->and($subscription->gateway)->toBe('fake')
    ->and($subscription->gateway_plan_id)->toBe('plan_123')
    ->and($subscription->status)->toBe('active');
});

test('user can check if subscribed', function (): void {
  $user = User::factory()->create();

  expect($user->subscribed())->toBeFalse();

  $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  expect($user->subscribed())->toBeTrue();
});

test('user can get active subscription', function (): void {
  $user = User::factory()->create();

  $subscription = $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  $retrieved = $user->subscription('default');

  expect($retrieved)
    ->toBeInstanceOf(Subscription::class)
    ->and($retrieved->id)->toBe($subscription->id);
});

test('subscription can be cancelled', function (): void {
  $user = User::factory()->create();

  $subscription = $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  expect($subscription->cancelled())->toBeFalse();

  $subscription->cancel();

  expect($subscription->cancelled())->toBeTrue()
    ->and($subscription->ends_at)->not->toBeNull();
});

test('subscription can be cancelled immediately', function (): void {
  $user = User::factory()->create();

  $subscription = $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  $subscription->cancelNow();

  expect($subscription->cancelled())->toBeTrue()
    ->and($subscription->expired())->toBeTrue()
    ->and($subscription->ends_at)->not->toBeNull();
});

test('subscription can have trial period', function (): void {
  $user = User::factory()->create();

  $subscription = $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->trialDays(14)
    ->create('fake_token_123');

  expect($subscription->onTrial())->toBeTrue()
    ->and($subscription->trial_ends_at)->not->toBeNull();
});

test('user can check if on trial', function (): void {
  $user = User::factory()->create();

  expect($user->onTrial())->toBeFalse();

  $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->trialDays(14)
    ->create('fake_token_123');

  expect($user->onTrial())->toBeTrue();
});

test('subscription can have metadata', function (): void {
  $user = User::factory()->create();

  $metadata = ['custom_field' => 'custom_value'];

  $subscription = $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->withMetadata($metadata)
    ->create('fake_token_123');

  expect($subscription->metadata)->toBe($metadata);
});

test('subscription is active when not cancelled or expired', function (): void {
  $user = User::factory()->create();

  $subscription = $user->newSubscription('default', 'plan_123')
    ->gateway('fake')
    ->create('fake_token_123');

  expect($subscription->active())->toBeTrue();

  $subscription->cancel();

  expect($subscription->active())->toBeTrue();

  $subscription->cancelNow();

  expect($subscription->active())->toBeFalse();
});
