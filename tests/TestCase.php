<?php

namespace Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Tests\Fixtures\User;
use Zen\Obsidian\ObsidianServiceProvider;

abstract class TestCase extends Orchestra
{
  protected function setUp(): void
  {
    parent::setUp();

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
    config()->set('obsidian.ccbill.merchant_id', 'test_merchant');
    config()->set('obsidian.ccbill.subaccount_id', 'test_subaccount');
    config()->set('obsidian.ccbill.api_key', 'test_api_key');
    config()->set('obsidian.ccbill.api_secret', 'test_api_secret');
    config()->set('obsidian.ccbill.salt', 'test_salt');
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

    $migration = include __DIR__.'/../database/migrations/2025_11_21_000002_create_subscriptions_table.php';
    $migration->up();

    $migration = include __DIR__.'/../database/migrations/2025_11_21_000003_create_subscription_items_table.php';
    $migration->up();
  }
}
