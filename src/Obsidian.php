<?php

declare(strict_types=1);

namespace Zen\Obsidian;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money;
use NumberFormatter;

class Obsidian
{
  /**
   * The Obsidian library version.
   */
  public const string VERSION = '2.0.0';

  /**
   * The custom currency formatter.
   *
   * @var callable|null
   */
  protected static $formatCurrencyUsing;

  /**
   * Indicates if Obsidian will mark past due subscriptions as inactive.
   */
  public static bool $deactivatePastDue = true;

  /**
   * The default customer model class name.
   */
  public static string $customerModel = 'App\\Models\\User';

  /**
   * The subscription model class name.
   */
  public static string $subscriptionModel = Subscription::class;

  /**
   * Set the custom currency formatter.
   */
  public static function formatCurrencyUsing(callable $callback): void
  {
    static::$formatCurrencyUsing = $callback;
  }

  /**
   * Format the given amount into a displayable currency.
   *
   * @param  array<string, mixed>  $options
   */
  public static function formatAmount(int $amount, ?string $currency = null, ?string $locale = null, array $options = []): string
  {
    if (static::$formatCurrencyUsing) {
      /** @var string $result */
      $result = call_user_func(static::$formatCurrencyUsing, $amount, $currency, $locale, $options);

      return $result;
    }

    /** @var string $currencyCode */
    $currencyCode = $currency ?? config('obsidian.currency', 'USD');
    $currencyCode = strtoupper($currencyCode);

    if ($currencyCode === '') {
      $currencyCode = 'USD';
    }

    $money = new Money($amount, new Currency($currencyCode));

    /** @var string $localeCode */
    $localeCode = $locale ?? config('obsidian.currency_locale', 'en_US');

    $numberFormatter = new NumberFormatter($localeCode, NumberFormatter::CURRENCY);

    if (isset($options['min_fraction_digits']) && is_int($options['min_fraction_digits'])) {
      /** @phpstan-ignore method.internalClass */
      $numberFormatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $options['min_fraction_digits']);
    }

    $moneyFormatter = new IntlMoneyFormatter($numberFormatter, new ISOCurrencies);

    return $moneyFormatter->format($money);
  }

  /**
   * Configure Obsidian to maintain past due subscriptions as active.
   */
  public static function keepPastDueSubscriptionsActive(): static
  {
    static::$deactivatePastDue = false;

    /** @phpstan-ignore new.static */
    return new static;
  }

  /**
   * Set the customer model class name.
   */
  public static function useCustomerModel(string $customerModel): void
  {
    static::$customerModel = $customerModel;
  }

  /**
   * Set the subscription model class name.
   */
  public static function useSubscriptionModel(string $subscriptionModel): void
  {
    static::$subscriptionModel = $subscriptionModel;
  }
}
