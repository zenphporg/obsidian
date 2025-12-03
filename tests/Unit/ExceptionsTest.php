<?php

use Zen\Obsidian\Exceptions\InvalidGateway;
use Zen\Obsidian\Exceptions\WebhookValidationException;

test('invalid gateway exception can be thrown', function (): void {
  expect(fn () => throw InvalidGateway::notSupported('unknown'))
    ->toThrow(InvalidGateway::class, 'Gateway [unknown] is not supported.');
});

test('webhook validation exception can be thrown', function (): void {
  expect(fn () => throw new WebhookValidationException('Invalid signature'))
    ->toThrow(WebhookValidationException::class, 'Invalid signature');
});

test('webhook validation exception can be created with invalid signature', function (): void {
  expect(fn () => throw WebhookValidationException::invalidSignature())
    ->toThrow(WebhookValidationException::class, 'Invalid webhook signature');
});

test('webhook validation exception can be created with missing secret', function (): void {
  expect(fn () => throw WebhookValidationException::missingSecret())
    ->toThrow(WebhookValidationException::class, 'Webhook secret not configured');
});

test('webhook validation exception can be created with missing signature', function (): void {
  expect(fn () => throw WebhookValidationException::missingSignature())
    ->toThrow(WebhookValidationException::class, 'Webhook signature header missing');
});
