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
    ->toThrow(WebhookValidationException::class, 'The webhook signature is invalid.');
});
