<?php

namespace App\Providers;

use App\Console\Commands\PetLoyaltyRebuild;
use App\Console\Commands\PetLoyaltyRemind;
use App\Models\LoyaltyRedemption;
use App\Models\Purchase;
use App\Models\PurchaseDelivery;
use App\Observers\PetLoyaltyObserver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/*
|--------------------------------------------------------------------------
| PET LOYALTY PROGRAM
|--------------------------------------------------------------------------
|
| Registered in bootstrap/providers.php. Everything of the program is here:
|  - automatic recalculation when a purchase / delivery changes
|  (the pages are in routes/web.php, the app API in routes/api.php)
|  - nightly recalculation (points lost after the deadlines)
|  - morning reminders (push notifications)
|
*/

class PetLoyaltyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Purchase::observe(PetLoyaltyObserver::class);
        PurchaseDelivery::observe(PetLoyaltyObserver::class);
        LoyaltyRedemption::observe(PetLoyaltyObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PetLoyaltyRebuild::class, PetLoyaltyRemind::class]);
        }

        // Every night at 02:15 (needs the Laravel cron: php artisan schedule:run)
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('pet-loyalty:rebuild')->dailyAt('02:15')->withoutOverlapping();

            // Every morning at 10:05: points usable / points lost soon (push)
            $schedule->command('pet-loyalty:remind')->dailyAt('10:05')->withoutOverlapping();
        });
    }
}
