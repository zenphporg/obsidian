<?php

use Tests\Fixtures\User;
use Zen\Obsidian\Obsidian;
use Zen\Obsidian\Subscription;

test('can format amount with default currency', function (): void {
  $formatted = Obsidian::formatAmount(1000);
  expect($formatted)->toBeString();
});

test('can format amount with custom currency', function (): void {
  $formatted = Obsidian::formatAmount(1000, 'EUR');
  expect($formatted)->toBeString();
});

test('can format amount with empty currency defaults to USD', function (): void {
  $formatted = Obsidian::formatAmount(1000, '');
  expect($formatted)->toBeString();
});

test('can format amount with custom locale', function (): void {
  $formatted = Obsidian::formatAmount(1000, 'USD', 'en_US');
  expect($formatted)->toBeString();
});

test('can format amount with min fraction digits option', function (): void {
  $formatted = Obsidian::formatAmount(1000, 'USD', 'en_US', ['min_fraction_digits' => 2]);
  expect($formatted)->toBeString();
});

test('can use custom currency formatter', function (): void {
  Obsidian::formatCurrencyUsing(fn ($amount, string $currency): string => $currency.' '.number_format($amount / 100, 2));

  $formatted = Obsidian::formatAmount(1000, 'USD');
  expect($formatted)->toBe('USD 10.00');

  // Reset
  Obsidian::formatCurrencyUsing(fn (): string => '');
});

test('can use custom customer model', function (): void {
  Obsidian::useCustomerModel('App\\Models\\CustomUser');
  expect(Obsidian::$customerModel)->toBe('App\\Models\\CustomUser');

  // Reset
  Obsidian::useCustomerModel(User::class);
});

test('can use custom subscription model', function (): void {
  Obsidian::useSubscriptionModel('App\\Models\\CustomSubscription');
  expect(Obsidian::$subscriptionModel)->toBe('App\\Models\\CustomSubscription');

  // Reset
  Obsidian::useSubscriptionModel(Subscription::class);
});

test('can keep past due subscriptions active', function (): void {
  // Default is true
  expect(Obsidian::$deactivatePastDue)->toBeTrue();

  Obsidian::keepPastDueSubscriptionsActive();
  expect(Obsidian::$deactivatePastDue)->toBeFalse();
});
