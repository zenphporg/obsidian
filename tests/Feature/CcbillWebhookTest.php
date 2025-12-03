<?php

use Illuminate\Support\Facades\Event;
use Tests\Fixtures\User;
use Zen\Obsidian\Events\PaymentFailed;
use Zen\Obsidian\Events\PaymentSucceeded;
use Zen\Obsidian\Events\SubscriptionCancelled;
use Zen\Obsidian\Events\SubscriptionCreated;

beforeEach(function (): void {
  config(['obsidian.ccbill.webhook_secret' => 'test_secret']);
});

test('ccbill webhook handles new sale success', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'ccbill',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'pending',
  ]);

  $payload = json_encode([
    'eventType' => 'NewSaleSuccess',
    'subscriptionId' => 'sub_123',
    'billedAmount' => '29.99',
    'billedCurrency' => 'USD',
    'transactionId' => 'txn_123',
  ]);

  $signature = hash_hmac('sha256', (string) $payload, 'test_secret');

  $response = $this->postJson('/webhooks/ccbill', json_decode((string) $payload, true), [
    'X-CCBill-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  $subscription->refresh();
  expect($subscription->status)->toBe('active');

  Event::assertDispatched(SubscriptionCreated::class);
  Event::assertDispatched(PaymentSucceeded::class);
});

test('ccbill webhook handles renewal success', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'ccbill',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'eventType' => 'RenewalSuccess',
    'subscriptionId' => 'sub_123',
    'billedAmount' => '29.99',
    'billedCurrency' => 'USD',
    'transactionId' => 'txn_456',
  ]);

  $signature = hash_hmac('sha256', (string) $payload, 'test_secret');

  $response = $this->postJson('/webhooks/ccbill', json_decode((string) $payload, true), [
    'X-CCBill-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  Event::assertDispatched(PaymentSucceeded::class);
});

test('ccbill webhook handles renewal failure', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'ccbill',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'eventType' => 'RenewalFailure',
    'subscriptionId' => 'sub_123',
  ]);

  $signature = hash_hmac('sha256', (string) $payload, 'test_secret');

  $response = $this->postJson('/webhooks/ccbill', json_decode((string) $payload, true), [
    'X-CCBill-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  $subscription->refresh();
  expect($subscription->status)->toBe('past_due');

  Event::assertDispatched(PaymentFailed::class);
});

test('ccbill webhook handles cancellation', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'ccbill',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'eventType' => 'Cancellation',
    'subscriptionId' => 'sub_123',
  ]);

  $signature = hash_hmac('sha256', (string) $payload, 'test_secret');

  $response = $this->postJson('/webhooks/ccbill', json_decode((string) $payload, true), [
    'X-CCBill-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  $subscription->refresh();
  expect($subscription->status)->toBe('cancelled');

  Event::assertDispatched(SubscriptionCancelled::class);
});

test('ccbill webhook handles chargeback', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'ccbill',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'eventType' => 'Chargeback',
    'subscriptionId' => 'sub_123',
  ]);

  $signature = hash_hmac('sha256', (string) $payload, 'test_secret');

  $response = $this->postJson('/webhooks/ccbill', json_decode((string) $payload, true), [
    'X-CCBill-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  $subscription->refresh();
  expect($subscription->status)->toBe('cancelled');

  Event::assertDispatched(SubscriptionCancelled::class);
});

test('ccbill webhook handles refund', function (): void {
  $user = User::factory()->create();
  $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'ccbill',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'eventType' => 'Refund',
    'subscriptionId' => 'sub_123',
    'billedAmount' => '29.99',
  ]);

  $signature = hash_hmac('sha256', (string) $payload, 'test_secret');

  $response = $this->postJson('/webhooks/ccbill', json_decode((string) $payload, true), [
    'X-CCBill-Signature' => $signature,
  ]);

  $response->assertStatus(200);
});

test('ccbill webhook rejects invalid signature', function (): void {
  $payload = json_encode([
    'eventType' => 'NewSaleSuccess',
  ]);

  $response = $this->postJson('/webhooks/ccbill', json_decode((string) $payload, true), [
    'X-CCBill-Signature' => 'invalid_signature',
  ]);

  $response->assertStatus(403);
});

test('ccbill webhook handles unknown event type', function (): void {
  $payload = json_encode([
    'eventType' => 'UnknownEvent',
  ]);

  $signature = hash_hmac('sha256', (string) $payload, 'test_secret');

  $response = $this->postJson('/webhooks/ccbill', json_decode((string) $payload, true), [
    'X-CCBill-Signature' => $signature,
  ]);

  $response->assertStatus(200);
});

test('ccbill webhook handles expiration event', function (): void {
  Event::fake();

  $user = User::factory()->create();
  $subscription = $user->subscriptions()->create([
    'name' => 'default',
    'gateway' => 'ccbill',
    'gateway_subscription_id' => 'sub_123',
    'gateway_plan_id' => 'plan_123',
    'status' => 'active',
  ]);

  $payload = json_encode([
    'eventType' => 'Expiration',
    'subscriptionId' => 'sub_123',
  ]);

  $signature = hash_hmac('sha256', (string) $payload, 'test_secret');

  $response = $this->postJson('/webhooks/ccbill', json_decode((string) $payload, true), [
    'X-CCBill-Signature' => $signature,
  ]);

  $response->assertStatus(200);

  $subscription->refresh();
  expect($subscription->status)->toBe('cancelled');

  Event::assertDispatched(SubscriptionCancelled::class);
});
