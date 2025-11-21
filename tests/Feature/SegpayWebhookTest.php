<?php

use Illuminate\Support\Facades\Event;
use Tests\Fixtures\User;
use Zen\Obsidian\Events\PaymentFailed;
use Zen\Obsidian\Events\PaymentSucceeded;
use Zen\Obsidian\Events\SubscriptionCancelled;
use Zen\Obsidian\Events\SubscriptionCreated;

beforeEach(function (): void {
  config(['obsidian.segpay.webhook_secret' => 'test_secret']);
});

test('segpay webhook handles initial purchase', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'segpay',
    'gateway_subscription_id' => 'pur_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'pending',
  ]);

  $payload = json_encode([
    'action' => 'initial',
    'purchase-id' => 'pur_123',
    'amount' => '29.99',
  ]);

  $signature = hash_hmac('sha256', $payload, 'test_secret');

  $response = $this->postJson('/webhooks/segpay', json_decode($payload, true), [
    'X-SegPay-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  $subscription->refresh();
  expect($subscription->status)->toBe('active');

  Event::assertDispatched(SubscriptionCreated::class);
  Event::assertDispatched(PaymentSucceeded::class);
});

test('segpay webhook handles rebill', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'segpay',
    'gateway_subscription_id' => 'pur_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'action' => 'rebill',
    'purchase-id' => 'pur_123',
    'amount' => '29.99',
  ]);

  $signature = hash_hmac('sha256', $payload, 'test_secret');

  $response = $this->postJson('/webhooks/segpay', json_decode($payload, true), [
    'X-SegPay-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  Event::assertDispatched(PaymentSucceeded::class);
});

test('segpay webhook handles decline', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'segpay',
    'gateway_subscription_id' => 'pur_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'action' => 'decline',
    'purchase-id' => 'pur_123',
  ]);

  $signature = hash_hmac('sha256', $payload, 'test_secret');

  $response = $this->postJson('/webhooks/segpay', json_decode($payload, true), [
    'X-SegPay-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  $subscription->refresh();
  expect($subscription->status)->toBe('past_due');

  Event::assertDispatched(PaymentFailed::class);
});

test('segpay webhook handles cancel', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'segpay',
    'gateway_subscription_id' => 'pur_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'action' => 'cancel',
    'purchase-id' => 'pur_123',
  ]);

  $signature = hash_hmac('sha256', $payload, 'test_secret');

  $response = $this->postJson('/webhooks/segpay', json_decode($payload, true), [
    'X-SegPay-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  $subscription->refresh();
  expect($subscription->status)->toBe('cancelled');

  Event::assertDispatched(SubscriptionCancelled::class);
});

test('segpay webhook handles chargeback', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'segpay',
    'gateway_subscription_id' => 'pur_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'action' => 'chargeback',
    'purchase-id' => 'pur_123',
  ]);

  $signature = hash_hmac('sha256', $payload, 'test_secret');

  $response = $this->postJson('/webhooks/segpay', json_decode($payload, true), [
    'X-SegPay-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  $subscription->refresh();
  expect($subscription->status)->toBe('cancelled');

  Event::assertDispatched(SubscriptionCancelled::class);
});

test('segpay webhook rejects invalid signature', function (): void {
  $payload = json_encode([
    'action' => 'initial',
  ]);

  $response = $this->postJson('/webhooks/segpay', json_decode($payload, true), [
    'X-SegPay-Signature' => 'invalid_signature',
  ]);

  $response->assertStatus(403);
});

test('segpay webhook handles unknown event type', function (): void {
  $payload = json_encode([
    'action' => 'unknown',
  ]);

  $signature = hash_hmac('sha256', $payload, 'test_secret');

  $response = $this->postJson('/webhooks/segpay', json_decode($payload, true), [
    'X-SegPay-Signature' => $signature,
  ]);

  $response->assertStatus(200);
});
