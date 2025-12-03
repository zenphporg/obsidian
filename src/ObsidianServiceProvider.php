<?php

declare(strict_types=1);

namespace Zen\Obsidian;

use Illuminate\Support\ServiceProvider;
use Zen\Obsidian\Gateways\CcbillGateway;
use Zen\Obsidian\Gateways\SegpayGateway;

class ObsidianServiceProvider extends ServiceProvider
{
  /**
   * Bootstrap any package services.
   */
  public function boot(): void
  {
    $this->registerRoutes();
    $this->registerPublishing();
  }

  /**
   * Register any application services.
   */
  public function register(): void
  {
    $this->configure();
    $this->registerGateways();
  }

  /**
   * Setup the configuration for Obsidian.
   */
  protected function configure(): void
  {
    $this->mergeConfigFrom(
      __DIR__.'/../config/obsidian.php', 'obsidian'
    );
  }

  /**
   * Register payment gateways
   */
  protected function registerGateways(): void
  {
    $this->app->bind('obsidian.gateway.ccbill', fn (): CcbillGateway => new CcbillGateway);

    $this->app->bind('obsidian.gateway.segpay', fn (): SegpayGateway => new SegpayGateway);

    // Bind default gateway
    $this->app->bind(GatewayFactory::default(...));
  }

  /**
   * Register the package routes.
   */
  protected function registerRoutes(): void
  {
    // Load webhook routes
    $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
  }

  /**
   * Register the package's publishable resources.
   */
  protected function registerPublishing(): void
  {
    if ($this->app->runningInConsole()) {
      $this->publishes([
        __DIR__.'/../config/obsidian.php' => $this->app->configPath('obsidian.php'),
      ], 'obsidian-config');

      $this->publishesMigrations([
        __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
      ], 'obsidian-migrations');
    }
  }
}
