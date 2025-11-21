<?php

declare(strict_types=1);

namespace Zen\Obsidian;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Zen\Obsidian\Gateways\PaymentGatewayInterface;

/**
 * @mixin Model
 *
 * @phpstan-ignore trait.unused
 */
trait Billable
{
  /**
   * Get all subscriptions
   *
   * @return HasMany<Subscription>
   */
  public function subscriptions()
  {
    return $this->hasMany(Subscription::class)->orderBy('created_at', 'desc');
  }

  /**
   * Get a subscription by name
   */
  public function subscription(string $name = 'default'): ?Subscription
  {
    return $this->subscriptions()->where('name', $name)->first();
  }

  /**
   * Check if user has an active subscription
   */
  public function subscribed(string $name = 'default'): bool
  {
    $subscription = $this->subscription($name);

    return $subscription && $subscription->active();
  }

  /**
   * Check if user is on a trial
   */
  public function onTrial(string $name = 'default'): bool
  {
    $subscription = $this->subscription($name);

    return $subscription && $subscription->onTrial();
  }

  /**
   * Check if user is on a generic trial (no subscription)
   */
  public function onGenericTrial(): bool
  {
    return $this->trial_ends_at && $this->trial_ends_at->isFuture();
  }

  /**
   * Start building a new subscription
   */
  public function newSubscription(string $name, string $plan): SubscriptionBuilder
  {
    return new SubscriptionBuilder($this, $name, $plan);
  }

  /**
   * Charge a one-time amount
   */
  public function charge(int $amount, string $token, array $options = []): array
  {
    $gateway = app(PaymentGatewayInterface::class);

    return $gateway->charge([
      'token' => $token,
      'amount' => $amount,
      'currency' => $options['currency'] ?? 'USD',
      'description' => $options['description'] ?? 'One-time charge',
    ]);
  }

  /**
   * Update the default payment method
   */
  public function updateDefaultPaymentMethod(string $token): void
  {
    $subscription = $this->subscription();

    if ($subscription) {
      $subscription->update([
        'gateway_payment_token' => $token,
      ]);
    }
  }
}
