<?php

declare(strict_types=1);

namespace Zen\Obsidian\Exceptions;

use Exception;

class WebhookValidationException extends Exception
{
  /**
   * Create a new WebhookValidationException instance.
   */
  public static function invalidSignature(): static
  {
    /** @phpstan-ignore new.static */
    return new static('The webhook signature is invalid.');
  }
}
