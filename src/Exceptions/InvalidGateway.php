<?php

declare(strict_types=1);

namespace Zen\Obsidian\Exceptions;

use Exception;

class InvalidGateway extends Exception
{
  /**
   * Create a new InvalidGateway instance.
   */
  public static function notSupported(string $gateway): static
  {
    /** @phpstan-ignore new.static */
    return new static("Gateway [{$gateway}] is not supported.");
  }
}
