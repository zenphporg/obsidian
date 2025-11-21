<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Zen\Obsidian\Http\Controllers\CcbillWebhookController;
use Zen\Obsidian\Http\Controllers\SegpayWebhookController;

Route::post('/webhooks/ccbill', [CcbillWebhookController::class, 'handleWebhook'])
  ->name('obsidian.webhooks.ccbill');

Route::post('/webhooks/segpay', [SegpayWebhookController::class, 'handleWebhook'])
  ->name('obsidian.webhooks.segpay');
