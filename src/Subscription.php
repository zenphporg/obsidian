<?php

declare(strict_types=1);

namespace Zen\Obsidian;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Zen\Obsidian\Database\Factories\SubscriptionFactory;
use Zen\Obsidian\Gateways\PaymentGatewayInterface;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $gateway
 * @property string $gateway_subscription_id
 * @property string $gateway_plan_id
 * @property string|null $gateway_payment_token
 * @property string|null $payment_method_type
 * @property string|null $payment_method_last4
 * @property int|null $amount
 * @property string|null $currency
 * @property string|null $interval
 * @property string $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $ends_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static SubscriptionFactory factory($count = null, $state = [])
 */
class Subscription extends Model
{
  /** @use HasFactory<SubscriptionFactory> */
  use HasFactory;

  protected $guarded = [];

  /** @var array<string, string> */
  protected $casts = [
    'trial_ends_at' => 'datetime',
    'ends_at' => 'datetime',
    'metadata' => 'array',
  ];

  /**
   * Get the user that owns the subscription
   *
   * @return BelongsTo<Model, $this>
   */
  public function user(): BelongsTo
  {
    /** @var class-string<Model> $userModel */
    $userModel = config('obsidian.user_model', 'App\\Models\\User');

    return $this->belongsTo($userModel);
  }

  /**
   * Check if subscription is active
   */
  public function active(): bool
  {
    if ($this->expired()) {
      return false;
    }

    return $this->status === 'active' || $this->status === 'cancelled' || $this->onTrial();
  }

  /**
   * Check if subscription is on trial
   */
  public function onTrial(): bool
  {
    return $this->trial_ends_at && $this->trial_ends_at->isFuture();
  }

  /**
   * Check if subscription is cancelled
   */
  public function cancelled(): bool
  {
    return $this->status === 'cancelled' || $this->status === 'expired';
  }

  /**
   * Check if subscription is expired
   */
  public function expired(): bool
  {
    return $this->status === 'expired' || ($this->ends_at && $this->ends_at->isPast());
  }

  /**
   * Cancel the subscription (stop future billing)
   */
  public function cancel(): self
  {
    $gateway = $this->getGateway();

    try {
      $gateway->cancelSubscription($this->gateway_subscription_id);

      $this->update([
        'status' => 'cancelled',
        'ends_at' => now()->endOfMonth(), // Access until end of billing period
      ]);
    } catch (Exception $e) {
      // If API call fails, still mark as cancelled locally
      $this->update([
        'status' => 'cancelled',
        'ends_at' => now()->endOfMonth(),
      ]);

      throw $e;
    }

    return $this;
  }

  /**
   * Cancel immediately (no grace period)
   */
  public function cancelNow(): self
  {
    $this->cancel();

    $this->update([
      'ends_at' => now(),
      'status' => 'expired',
    ]);

    return $this;
  }

  /**
   * Get the gateway instance for this subscription
   */
  protected function getGateway(): PaymentGatewayInterface
  {
    return GatewayFactory::make($this->gateway);
  }
}
