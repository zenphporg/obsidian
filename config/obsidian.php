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
   *
   * CCBill requires two sets of OAuth credentials:
   * - Frontend: For FlexForms payment widget (merchant_app_id + secret_key)
   * - Backend: For REST API calls (same credentials, different usage)
   *
   * DataLink credentials are required for subscription cancellation.
   */
  'ccbill' => [
    // Account identifiers
    'merchant_id' => env('CCBILL_MERCHANT_ID'),
    'subaccount_id' => env('CCBILL_SUBACCOUNT_ID'),

    // OAuth 2.0 credentials (from CCBill Admin > Account Info > API Credentials)
    'merchant_app_id' => env('CCBILL_MERCHANT_APP_ID'),
    'secret_key' => env('CCBILL_SECRET_KEY'),

    // DataLink credentials (for subscription management)
    'datalink_username' => env('CCBILL_DATALINK_USERNAME'),
    'datalink_password' => env('CCBILL_DATALINK_PASSWORD'),

    // Webhook signature verification
    'webhook_secret' => env('CCBILL_WEBHOOK_SECRET'),

    // FlexForms configuration
    'flexforms_url' => env('CCBILL_FLEXFORMS_URL', 'https://api.ccbill.com/wap-frontflex/flexforms'),
  ],

  /**
   * SEGPAY CONFIGURATION
   *
   * Note: SegPay integration is not yet implemented.
   * These configuration options are placeholders for future development.
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
