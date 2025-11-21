<?php

declare(strict_types=1);

return [

  /**
   * DEFAULT PAYMENT GATEWAY
   *
   * This option controls the default payment gateway used for subscriptions.
   * Supported: "ccbill", "segpay", "fake"
   */
  'default_gateway' => env('OBSIDIAN_GATEWAY', 'ccbill'),

  /**
   * FALLBACK PAYMENT GATEWAY
   *
   * This gateway will be used if the default gateway fails.
   */
  'fallback_gateway' => env('OBSIDIAN_FALLBACK_GATEWAY', 'segpay'),

  /**
   * USER MODEL
   *
   * This is the model in your application that implements the Billable trait.
   */
  'user_model' => env('OBSIDIAN_USER_MODEL', 'App\\Models\\User'),

  /**
   * CCBILL CONFIGURATION
   */
  'ccbill' => [
    'merchant_id' => env('CCBILL_MERCHANT_ID'),
    'subaccount_id' => env('CCBILL_SUBACCOUNT_ID'),
    'api_key' => env('CCBILL_API_KEY'),
    'api_secret' => env('CCBILL_API_SECRET'),
    'salt' => env('CCBILL_SALT'),
    'webhook_secret' => env('CCBILL_WEBHOOK_SECRET'),

    // FlexForms configuration
    'flexforms_url' => env('CCBILL_FLEXFORMS_URL', 'https://bill.ccbill.com/jpost/signup.cgi'),
  ],

  /**
   * SEGPAY CONFIGURATION
   */
  'segpay' => [
    'merchant_id' => env('SEGPAY_MERCHANT_ID'),
    'package_id' => env('SEGPAY_PACKAGE_ID'),
    'user_id' => env('SEGPAY_USER_ID'),
    'api_key' => env('SEGPAY_API_KEY'),
    'webhook_secret' => env('SEGPAY_WEBHOOK_SECRET'),
  ],

  /**
   * CURRENCY
   */
  'currency' => env('OBSIDIAN_CURRENCY', 'usd'),

  /**
   * CURRENCY LOCALE
   */
  'currency_locale' => env('OBSIDIAN_CURRENCY_LOCALE', 'en'),

  /**
   * WEBHOOK TOLERANCE
   *
   * This value defines the number of seconds that webhooks can be delayed.
   */
  'webhook_tolerance' => env('OBSIDIAN_WEBHOOK_TOLERANCE', 300),
];
