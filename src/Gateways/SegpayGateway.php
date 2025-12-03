<?php

declare(strict_types=1);

namespace Zen\Obsidian\Gateways;

use Zen\Obsidian\Exceptions\GatewayException;

/**
 * SegPay Gateway - Work in Progress
 *
 * This gateway is not yet implemented. All methods will throw a GatewayException.
 * SegPay integration is planned for a future release.
 *
 * @see https://segpay.com/developers for API documentation
 */
class SegpayGateway implements PaymentGatewayInterface
{
  public function name(): string
  {
    return 'segpay';
  }

  /**
   * @param  array<string, mixed>  $params
   * @return array<string, mixed>
   */
  public function createSubscription(array $params): array
  {
    throw new GatewayException('SegPay gateway is not yet implemented');
  }

  public function cancelSubscription(string $subscriptionId): bool
  {
    throw new GatewayException('SegPay gateway is not yet implemented');
  }

  /**
   * @param  array<string, mixed>  $params
   * @return array<string, mixed>
   */
  public function charge(array $params): array
  {
    throw new GatewayException('SegPay gateway is not yet implemented');
  }

  public function validateWebhookSignature(string $payload, string $signature): bool
  {
    throw new GatewayException('SegPay gateway is not yet implemented');
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return array<string, mixed>
   */
  public function parseWebhookPayload(array $payload): array
  {
    throw new GatewayException('SegPay gateway is not yet implemented');
  }
}
