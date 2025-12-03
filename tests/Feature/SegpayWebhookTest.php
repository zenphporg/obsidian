<?php

use Zen\Obsidian\Exceptions\GatewayException;
use Zen\Obsidian\Gateways\SegpayGateway;

/**
 * SegPay Gateway Tests
 *
 * Note: SegPay integration is not yet implemented.
 * These tests verify that the gateway correctly throws GatewayException
 * for all operations until the integration is complete.
 */
test('segpay gateway name returns segpay', function (): void {
  $gateway = new SegpayGateway;

  expect($gateway->name())->toBe('segpay');
});

test('segpay gateway createSubscription throws not implemented exception', function (): void {
  $gateway = new SegpayGateway;

  expect(fn (): array => $gateway->createSubscription([
    'user' => (object) ['email' => 'test@example.com'],
    'token' => 'test_token',
    'plan' => 'test_plan',
  ]))->toThrow(GatewayException::class, 'SegPay gateway is not yet implemented');
});

test('segpay gateway cancelSubscription throws not implemented exception', function (): void {
  $gateway = new SegpayGateway;

  expect(fn (): bool => $gateway->cancelSubscription('sub_123'))
    ->toThrow(GatewayException::class, 'SegPay gateway is not yet implemented');
});

test('segpay gateway charge throws not implemented exception', function (): void {
  $gateway = new SegpayGateway;

  expect(fn (): array => $gateway->charge([
    'token' => 'test_token',
    'amount' => 2999,
    'currency' => 'USD',
  ]))->toThrow(GatewayException::class, 'SegPay gateway is not yet implemented');
});

test('segpay gateway validateWebhookSignature throws not implemented exception', function (): void {
  $gateway = new SegpayGateway;

  expect(fn (): bool => $gateway->validateWebhookSignature('payload', 'signature'))
    ->toThrow(GatewayException::class, 'SegPay gateway is not yet implemented');
});

test('segpay gateway parseWebhookPayload throws not implemented exception', function (): void {
  $gateway = new SegpayGateway;

  expect(fn (): array => $gateway->parseWebhookPayload(['action' => 'initial']))
    ->toThrow(GatewayException::class, 'SegPay gateway is not yet implemented');
});

test('segpay webhook controller returns 403 for any request', function (): void {
  // SegPay gateway is not implemented, so validateWebhookSignature throws
  // which causes the controller to return 403
  $response = $this->postJson('/webhooks/segpay', [
    'action' => 'initial',
    'purchaseId' => 'purchase_123',
  ], [
    'X-Segpay-Signature' => 'any_signature',
  ]);

  $response->assertStatus(403);
});
