<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

interface PaymentGatewayInterface
{
  /**
   * Get the gateway name
   */
  public function name(): string;

  /**
   * Create a subscription with a payment token
   *
   * @param  array<string, mixed>  $params  ['user', 'token', 'plan', 'trial_days', 'amount', 'currency']
   * @return array<string, mixed> ['subscription_id', 'status', 'next_billing_date', 'payment_method']
   */
  public function createSubscription(array $params): array;

  /**
   * Cancel a subscription (stop future billing)
   */
  public function cancelSubscription(string $subscriptionId): bool;

  /**
   * Charge a payment token one-time
   *
   * @param  array<string, mixed>  $params  ['token', 'amount', 'currency', 'description']
   * @return array<string, mixed> ['id', 'status', 'amount', 'currency']
   */
  public function charge(array $params): array;

  /**
   * Validate webhook signature
   */
  public function validateWebhookSignature(string $payload, string $signature): bool;

  /**
   * Parse webhook payload into normalized format
   *
   * @param  array<string, mixed>  $payload
   * @return array<string, mixed> ['type', 'subscription_id', 'transaction_id', 'amount', 'currency', 'data']
   */
  public function parseWebhookPayload(array $payload): array;
}
