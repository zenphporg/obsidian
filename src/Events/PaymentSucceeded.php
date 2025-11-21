<?php

declare(strict_types=1);

namespace Zen\Obsidian\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Zen\Obsidian\Subscription;

class PaymentSucceeded
{
  use Dispatchable, SerializesModels;

  public function __construct(public Subscription $subscription, public ?int $amount = null) {}
}
