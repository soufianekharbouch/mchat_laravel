<?php

namespace App\Observers;

use App\Models\LoyaltyRedemption;
use App\Models\Purchase;
use App\Models\PurchaseDelivery;
use App\Services\PetLoyaltyService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Recalculates the points of the pet when something that changes them
 * is saved: purchase (paid, cancelled, refunded, pet changed...), delivery
 * (first delivery date = start of the pack), points used.
 * Runs after the database transaction, never breaks the caller.
 */
class PetLoyaltyObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        $this->rebuild($model);
    }

    public function deleted(Model $model): void
    {
        $this->rebuild($model);
    }

    private function rebuild(Model $model): void
    {
        $service = app(PetLoyaltyService::class);

        foreach ($this->animalIds($model) as $animalId) {
            $service->rebuildPetSafely($animalId);
        }
    }

    /** @return int[] */
    private function animalIds(Model $model): array
    {
        $ids = [];

        if ($model instanceof Purchase || $model instanceof LoyaltyRedemption) {
            $ids[] = (int) $model->animal_id;

            // Pet changed on the purchase: the old pet too
            if ($model->wasChanged('animal_id') || $model->isDirty('animal_id')) {
                $ids[] = (int) $model->getOriginal('animal_id');
            }
        }

        if ($model instanceof PurchaseDelivery) {
            $ids[] = (int) Purchase::query()->whereKey($model->purchase_id)->value('animal_id');
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
