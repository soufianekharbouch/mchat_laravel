<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Commands
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/*
|--------------------------------------------------------------------------
| Support Ticket Notifications
|--------------------------------------------------------------------------
|
| Vérifie chaque minute les messages envoyés par les administrateurs.
| Si un message n'a pas été lu après 10 minutes,
| une notification est envoyée au client concerné.
|
*/

Schedule::command('support:send-unread-notifications')
    ->everyMinute()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| JAAK deliveries
|--------------------------------------------------------------------------
|
| JAAK has no webhook: every 10 minutes, and only when prepared orders are
| at JAAK (or waiting to be sent), the statuses are read (Shipped /
| Delivered applied automatically) and the failed sends are retried.
| Needs the cPanel cron: * * * * * php /path/artisan schedule:run
|
*/

Schedule::command('jaak:sync')
    ->everyTenMinutes()
    ->withoutOverlapping()
    // Only when deliveries are waiting at JAAK or must be sent again
    ->when(fn () => \App\Services\JaakClient::enabled()
        && \App\Models\JaakDelivery::query()
            ->where(fn ($q) => $q->whereNotNull('jaak_task_id')->whereIn('status', \App\Models\JaakDelivery::OPEN_STATUSES))
            ->orWhere(fn ($q) => $q->whereNull('jaak_task_id')->whereIn('status', ['queued', 'waiting']))
            ->exists());

/*
|--------------------------------------------------------------------------
| Shopify orders not received by webhook
|--------------------------------------------------------------------------
|
| Same as the "Refresh" button of Auto subscription, every 10 minutes:
| Shopify orders of the last days (never before SHOPIFY_SYNC_START)
| missing here are imported, paid "Waiting payment" ones processed again.
| Only when the Shopify Admin token is set.
|
*/

Schedule::command('shopify:sync-orders')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->when(fn () => filled(config('services.shopify.store_domain'))
        && filled(config('services.shopify.admin_access_token')));

/*
|--------------------------------------------------------------------------
| Online payments (Hesabe) not confirmed yet
|--------------------------------------------------------------------------
|
| Customer closed the app on the payment page, webhook not received...:
| every 5 minutes the open payments are asked to Hesabe (paid -> packs
| paid), and the ones not paid after 2 hours are closed.
|
*/

Schedule::command('payments:check-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn () => \App\Services\OnlinePaymentService::enabled()
        && \App\Models\PaymentTransaction::query()->where('status', 'initiated')->exists());
