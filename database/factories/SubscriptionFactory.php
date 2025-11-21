<?php

declare(strict_types=1);

namespace Zen\Obsidian\Database\Factories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Zen\Obsidian\Obsidian;
use Zen\Obsidian\Subscription;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
  /**
   * The name of the factory's corresponding model.
   */
  protected $model = Subscription::class;

  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    /** @var class-string $model */
    $model = Obsidian::$customerModel;

    /** @var Model $modelInstance */
    $modelInstance = new $model;

    return [
      $modelInstance->getForeignKey() => ($model)::factory(),
      'name' => 'default',
      'gateway' => 'fake',
      'gateway_subscription_id' => 'sub_'.Str::random(40),
      'gateway_plan_id' => 'plan_'.Str::random(20),
      'status' => 'active',
      'trial_ends_at' => null,
      'ends_at' => null,
      'gateway_payment_token' => null,
      'payment_method_type' => null,
      'payment_method_last4' => null,
      'amount' => 2999,
      'currency' => 'USD',
      'interval' => 'monthly',
      'metadata' => null,
    ];
  }

  /**
   * Mark the subscription as active.
   */
  public function active(): static
  {
    return $this->state([
      'status' => 'active',
    ]);
  }

  /**
   * Mark the subscription as being within a trial period.
   */
  public function trialing(?DateTimeInterface $trialEndsAt = null): static
  {
    return $this->state([
      'status' => 'active',
      'trial_ends_at' => $trialEndsAt ?? now()->addDays(14),
    ]);
  }

  /**
   * Mark the subscription as canceled.
   */
  public function canceled(): static
  {
    return $this->state([
      'status' => 'cancelled',
      'ends_at' => now(),
    ]);
  }

  /**
   * Mark the subscription as being past the due date.
   */
  public function pastDue(): static
  {
    return $this->state([
      'status' => 'past_due',
    ]);
  }

  /**
   * Set the gateway for the subscription.
   */
  public function gateway(string $gateway): static
  {
    return $this->state([
      'gateway' => $gateway,
    ]);
  }
}
