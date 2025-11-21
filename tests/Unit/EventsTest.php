<?php

use Tests\Fixtures\User;
use Zen\Obsidian\Events\PaymentFailed;
use Zen\Obsidian\Events\PaymentSucceeded;
use Zen\Obsidian\Events\SubscriptionCancelled;
use Zen\Obsidian\Events\SubscriptionCreated;

test('payment succeeded event can be created', function (): void {
  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'fake',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $event = new PaymentSucceeded($subscription, 1000);

  expect($event->subscription)->toBe($subscription);
  expect($event->amount)->toBe(1000);
});

test('payment failed event can be created', function (): void {
  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'fake',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $event = new PaymentFailed($subscription, 1000);

  expect($event->subscription)->toBe($subscription);
  expect($event->amount)->toBe(1000);
});

test('subscription created event can be created', function (): void {
  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'fake',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $event = new SubscriptionCreated($subscription);

  expect($event->subscription)->toBe($subscription);
});

test('subscription cancelled event can be created', function (): void {
  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'fake',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $event = new SubscriptionCancelled($subscription);

  expect($event->subscription)->toBe($subscription);
});
