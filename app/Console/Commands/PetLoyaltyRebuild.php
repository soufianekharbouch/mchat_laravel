<?php

namespace App\Console\Commands;

use App\Services\PetLoyaltyService;
use Illuminate\Console\Command;

/**
 * php artisan pet-loyalty:rebuild            all the pets
 * php artisan pet-loyalty:rebuild --pet=12   one pet
 *
 * Runs every night (points lost after the deadlines).
 */
class PetLoyaltyRebuild extends Command
{
    protected $signature = 'pet-loyalty:rebuild {--pet= : Id of one pet}';

    protected $description = 'Recalculate the loyalty points of the pets';

    public function handle(PetLoyaltyService $service): int
    {
        if ($this->option('pet')) {
            $service->rebuildPet((int) $this->option('pet'));
            $this->info('Pet recalculated.');

            return self::SUCCESS;
        }

        $count = $service->rebuildAll();
        $this->info("{$count} pet(s) recalculated.");

        return self::SUCCESS;
    }
}
