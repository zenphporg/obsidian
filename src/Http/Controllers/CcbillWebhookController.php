<?php

declare(strict_types=1);

namespace Zen\Obsidian\Http\Controllers;

use Exception;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Zen\Obsidian\Events\PaymentFailed;
use Zen\Obsidian\Events\PaymentSucceeded;
use Zen\Obsidian\Events\SubscriptionCancelled;
use Zen\Obsidian\Events\SubscriptionCreated;
use Zen\Obsidian\Gateways\CcbillGateway;
use Zen\Obsidian\Subscription;

class CcbillWebhookController extends Controller
{
  public function handleWebhook(Request $request): ResponseFactory|Response
  {
    /** @var array<string, mixed> $payload */
    $payload = $request->all();

    // Validate signature
    /** @var string $signature */
    $signature = $request->header('X-CCBill-Signature') ?? '';
    $gateway = resolve(CcbillGateway::class);

    try {
      $gateway->validateWebhookSignature($request->getContent(), $signature);
    } catch (Exception) {
      return response('Invalid signature', 403);
    }

    // Parse webhook
    $event = $gateway->parseWebhookPayload($payload);

    // Handle different event types (using normalized types from gateway)
    return match ($event['type']) {
      'subscription.created' => $this->handleNewSale($event),
      'payment.succeeded' => $this->handleRenewal($event),
      'payment.failed' => $this->handleRenewalFailure($event),
      'subscription.cancelled' => $this->handleCancellation($event),
      'subscription.chargeback' => $this->handleChargeback($event),
      'payment.refunded' => $this->handleRefund($event),
      'subscription.expired' => $this->handleCancellation($event),
      default => response('Webhook received', 200),
    };
  }

  /**
   * @param  array<string, mixed>  $event
   */
  protected function handleNewSale(array $event): Response
  {
    $subscription = Subscription::query()->where('gateway_subscription_id', $event['subscription_id'])->first();

    if ($subscription) {
      $subscription->update([
        'status' => 'active',
      ]);

      event(new SubscriptionCreated($subscription));

      /** @var int $amount */
      $amount = $event['amount'] ?? 0;
      event(new PaymentSucceeded($subscription, $amount));
    }

    return response('Webhook handled', 200);
  }

  /**
   * @param  array<string, mixed>  $event
   */
  protected function handleRenewal(array $event): Response
  {
    $subscription = Subscription::query()->where('gateway_subscription_id', $event['subscription_id'])->first();

    if ($subscription) {
      $subscription->update([
        'status' => 'active',
        'ends_at' => null, // Clear any cancellation
      ]);

      /** @var int $amount */
      $amount = $event['amount'] ?? 0;
      event(new PaymentSucceeded($subscription, $amount));
    }

    return response('Webhook handled', 200);
  }

  /**
   * @param  array<string, mixed>  $event
   */
  protected function handleRenewalFailure(array $event): Response
  {
    $subscription = Subscription::query()->where('gateway_subscription_id', $event['subscription_id'])->first();

    if ($subscription) {
      $subscription->update([
        'status' => 'past_due',
      ]);

      /** @var int $amount */
      $amount = $event['amount'] ?? 0;
      event(new PaymentFailed($subscription, $amount));
    }

    return response('Webhook handled', 200);
  }

  /**
   * @param  array<string, mixed>  $event
   */
  protected function handleCancellation(array $event): Response
  {
    $subscription = Subscription::query()->where('gateway_subscription_id', $event['subscription_id'])->first();

    if ($subscription) {
      $subscription->update([
        'status' => 'cancelled',
        'ends_at' => now(),
      ]);

      event(new SubscriptionCancelled($subscription));
    }

    return response('Webhook handled', 200);
  }

  /**
   * @param  array<string, mixed>  $event
   */
  protected function handleChargeback(array $event): Response
  {
    $subscription = Subscription::query()->where('gateway_subscription_id', $event['subscription_id'])->first();

    if ($subscription) {
      $subscription->update([
        'status' => 'cancelled',
        'ends_at' => now(),
      ]);

      event(new SubscriptionCancelled($subscription));
    }

    return response('Webhook handled', 200);
  }

  /**
   * @param  array<string, mixed>  $event
   */
  protected function handleRefund(array $event): Response
  {
    $subscription = Subscription::query()->where('gateway_subscription_id', $event['subscription_id'])->first();

    if ($subscription) {
      // Log refund but don't cancel subscription
      logger()->info('CCBill refund received', [
        'subscription_id' => $subscription->id,
        'amount' => $event['amount'],
      ]);
    }

    return response('Webhook handled', 200);
  }
}
