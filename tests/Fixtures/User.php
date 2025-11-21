<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Zen\Obsidian\Billable;
use Zen\Obsidian\Database\Factories\UserFactory;

class User extends Authenticatable
{
  use Billable;
  use HasFactory;

  protected $guarded = [];

  /**
   * Create a new factory instance for the model.
   */
  protected static function newFactory(): UserFactory
  {
    return UserFactory::new();
  }
}
