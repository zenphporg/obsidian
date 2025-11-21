<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::create('subscription_items', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
      $table->string('name')->default('default');
      $table->boolean('on_trial')->default(false);
      $table->timestamp('trial_started_at')->nullable();
      $table->timestamp('trial_ends_at')->nullable();
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('subscription_items');
  }
};
