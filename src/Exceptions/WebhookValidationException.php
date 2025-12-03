<?php

declare(strict_types=1);

namespace Zen\Obsidian\Exceptions;

use Exception;

class WebhookValidationException extends Exception
{
  public static function invalidSignature(): self
  {
    return new self('Invalid webhook signature', 403);
  }

  public static function missingSecret(): self
  {
    return new self('Webhook secret not configured', 500);
  }

  public static function missingSignature(): self
  {
    return new self('Webhook signature header missing', 400);
  }
}
