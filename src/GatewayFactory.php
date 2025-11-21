<?php

declare(strict_types=1);

namespace Zen\Obsidian;

use Exception;
use Zen\Obsidian\Exceptions\InvalidGateway;
use Zen\Obsidian\Gateways\CcbillGateway;
use Zen\Obsidian\Gateways\FakeGateway;
use Zen\Obsidian\Gateways\PaymentGatewayInterface;
use Zen\Obsidian\Gateways\SegpayGateway;

class GatewayFactory
{
  /**
   * Create a gateway instance by name
   */
  public static function make(string $gateway): PaymentGatewayInterface
  {
    return match ($gateway) {
      'ccbill' => new CcbillGateway,
      'segpay' => new SegpayGateway,
      'fake' => new FakeGateway,
      default => throw new InvalidGateway("Gateway [{$gateway}] is not supported"),
    };
  }

  /**
   * Get the default gateway
   */
  public static function default(): PaymentGatewayInterface
  {
    /** @var string $gateway */
    $gateway = config('obsidian.default_gateway', 'ccbill');

    return static::make($gateway);
  }

  /**
   * Get gateway with automatic failover
   */
  public static function withFailover(): PaymentGatewayInterface
  {
    /** @var string $primary */
    $primary = config('obsidian.default_gateway');

    /** @var string $fallback */
    $fallback = config('obsidian.fallback_gateway');

    try {
      return static::make($primary);
    } catch (Exception $e) {
      logger()->warning("Primary gateway [{$primary}] failed, using fallback [{$fallback}]", [
        'error' => $e->getMessage(),
      ]);

      return static::make($fallback);
    }
  }
}
