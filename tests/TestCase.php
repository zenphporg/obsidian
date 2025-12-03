<?php

namespace Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Tests\Fixtures\User;
use Zen\Obsidian\Gateways\FakeGateway;
use Zen\Obsidian\ObsidianServiceProvider;

abstract class TestCase extends Orchestra
{
  protected function setUp(): void
  {
    parent::setUp();

    // Reset FakeGateway state between tests
    FakeGateway::reset();

    Factory::guessFactoryNamesUsing(
      fn (string $modelName): string => 'Zen\\Obsidian\\Database\\Factories\\'.class_basename($modelName).'Factory'
    );
  }

  protected function getPackageProviders($app): array
  {
    return [
      ObsidianServiceProvider::class,
    ];
  }

  protected function getEnvironmentSetUp($app): void
  {
    config()->set('database.default', 'testing');
    config()->set('obsidian.user_model', User::class);
    config()->set('obsidian.default_gateway', 'fake');

    // CCBill test configuration
    config()->set('obsidian.ccbill.merchant_id', 'test_merchant');
    config()->set('obsidian.ccbill.subaccount_id', 'test_subaccount');
    config()->set('obsidian.ccbill.merchant_app_id', 'test_app_id');
    config()->set('obsidian.ccbill.secret_key', 'test_secret_key');
    config()->set('obsidian.ccbill.datalink_username', 'test_datalink_user');
    config()->set('obsidian.ccbill.datalink_password', 'test_datalink_pass');
    config()->set('obsidian.ccbill.webhook_secret', 'test_webhook_secret');

    // SegPay test configuration (placeholder)
    config()->set('obsidian.segpay.merchant_id', 'test_merchant');
    config()->set('obsidian.segpay.package_id', 'test_package');
    config()->set('obsidian.segpay.user_id', 'test_user');
    config()->set('obsidian.segpay.api_key', 'test_api_key');

    Schema::create('users', function (Blueprint $table): void {
      $table->id();
      $table->string('name');
      $table->string('email')->unique();
      $table->timestamp('email_verified_at')->nullable();
      $table->string('password');
      $table->rememberToken();
      $table->timestamps();
    });

    $migration = include __DIR__.'/../database/migrations/2025_11_21_000001_create_customer_columns.php';
    $migration->up();

    $migration = include __DIR__.'/../database/migrations/2025_11_21_000002_create_subscriptions_tables.php';
    $migration->up();
  }
}
