<?php

use Zen\Obsidian\Exceptions\InvalidGateway;
use Zen\Obsidian\GatewayFactory;
use Zen\Obsidian\Gateways\CcbillGateway;
use Zen\Obsidian\Gateways\FakeGateway;
use Zen\Obsidian\Gateways\SegpayGateway;

test('can create ccbill gateway', function (): void {
  $gateway = GatewayFactory::make('ccbill');

  expect($gateway)->toBeInstanceOf(CcbillGateway::class);
});

test('can create segpay gateway', function (): void {
  $gateway = GatewayFactory::make('segpay');

  expect($gateway)->toBeInstanceOf(SegpayGateway::class);
});

test('can create fake gateway', function (): void {
  $gateway = GatewayFactory::make('fake');

  expect($gateway)->toBeInstanceOf(FakeGateway::class);
});

test('throws exception for invalid gateway', function (): void {
  GatewayFactory::make('invalid');
})->throws(InvalidGateway::class);

test('can get default gateway from config', function (): void {
  config()->set('obsidian.default_gateway', 'ccbill');

  $gateway = GatewayFactory::default();

  expect($gateway)->toBeInstanceOf(CcbillGateway::class);
});

test('falls back to fallback gateway when primary fails', function (): void {
  config()->set('obsidian.default_gateway', 'invalid_gateway');
  config()->set('obsidian.fallback_gateway', 'fake');

  $gateway = GatewayFactory::withFailover();

  expect($gateway)->toBeInstanceOf(FakeGateway::class);
});
