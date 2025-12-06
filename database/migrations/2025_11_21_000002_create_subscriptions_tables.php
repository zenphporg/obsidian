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
    Schema::create('subscriptions', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('user_id')->constrained()->cascadeOnDelete();

      // Subscription identification
      $table->string('name')->default('default');           								// 'default', 'premium', etc
      $table->string('gateway');                            								// 'ccbill' or 'segpay'
      $table->string('gateway_subscription_id')->unique(); 									// Processor's subscription ID
      $table->string('gateway_plan_id');                   									// Processor's plan/price ID

      // Status tracking
      $table->string('status')->default('active')->index();         				// active, canceled, expired
      $table->timestamp('trial_ends_at')->nullable();
      $table->timestamp('ends_at')->nullable();            									// When cancelled, when access ends

      // Payment method
      $table->string('gateway_payment_token')->nullable(); 									// Stored payment method token
      $table->string('payment_method_type')->nullable();   									// 'card', 'paypal', etc
      $table->string('payment_method_last4')->nullable();  									// Last 4 of card

      // Metadata
      $table->decimal('amount', 8, 2)->nullable();    											// Subscription amount
      $table->string('currency', 3)->default('USD');
      $table->string('interval')->nullable();              									// 'month', '6_months', 'year'
      $table->json('metadata')->nullable();                									// Extra data

      $table->timestamp('created_at')->useCurrent();
      $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
      $table->softDeletes()->index();

      // Indexes
      $table->index(['user_id', 'name']);
    });

    Schema::create('subscription_items', function (Blueprint $table): void {
      $table->id();
      $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
      $table->string('name')->default('default');
      $table->boolean('on_trial')->default(false);
      $table->timestamp('trial_started_at')->nullable();
      $table->timestamp('trial_ends_at')->nullable();
      $table->timestamp('created_at')->useCurrent();
      $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
      $table->softDeletes()->index();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('subscriptions');
    Schema::dropIfExists('subscription_items');
  }
};
