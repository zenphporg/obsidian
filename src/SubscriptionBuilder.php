<?php

declare(strict_types=1);

namespace Zen\Obsidian;

use Illuminate\Database\Eloquent\Model;
use Zen\Obsidian\Gateways\PaymentGatewayInterface;

class SubscriptionBuilder
{
  protected int $trialDays = 0;

  protected ?string $gateway = null;

  /** @var array<string, mixed> */
  protected array $metadata = [];

  public function __construct(protected Model $owner, protected string $name, protected string $plan)
  {
    /** @var string $defaultGateway */
    $defaultGateway = config('obsidian.default_gateway');
    $this->gateway = $defaultGateway;
  }

  /**
   * Specify trial days
   */
  public function trialDays(int $days): self
  {
    $this->trialDays = $days;

    return $this;
  }

  /**
   * Specify which gateway to use
   */
  public function gateway(string $gateway): self
  {
    $this->gateway = $gateway;

    return $this;
  }

  /**
   * Add metadata
   *
   * @param  array<string, mixed>  $metadata
   */
  public function withMetadata(array $metadata): self
  {
    $this->metadata = $metadata;

    return $this;
  }

  /**
   * Create the subscription
   *
   * @param  array<string, mixed>  $options
   */
  public function create(string $paymentToken, array $options = []): Subscription
  {
    $gateway = $this->resolveGateway();

    // Call gateway to create subscription
    $result = $gateway->createSubscription([
      'user' => $this->owner,
      'token' => $paymentToken,
      'plan' => $this->plan,
      'trial_days' => $this->trialDays,
    ]);

    // Create local subscription record
    $subscriptionData = [
      'name' => $this->name,
      'gateway' => $this->gateway,
      'gateway_subscription_id' => $result['subscription_id'],
      'gateway_plan_id' => $this->plan,
      'gateway_payment_token' => $paymentToken,
      'status' => $result['status'],
      'trial_ends_at' => $this->trialDays > 0 ? now()->addDays($this->trialDays) : null,
      'metadata' => $this->metadata,
    ];

    // The owner must use the Billable trait which provides subscriptions()
    assert(method_exists($this->owner, 'subscriptions'));

    /** @var Subscription $subscription */
    $subscription = $this->owner->subscriptions()->create($subscriptionData); // @phpstan-ignore method.nonObject

    return $subscription;
  }

  /**
   * Resolve the gateway instance
   */
  protected function resolveGateway(): PaymentGatewayInterface
  {
    /** @var string $gateway */
    $gateway = $this->gateway ?? config('obsidian.default_gateway', 'ccbill');

    return GatewayFactory::make($gateway);
  }
}
