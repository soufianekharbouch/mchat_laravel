
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
