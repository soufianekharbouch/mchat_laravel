<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use HasFactory;

    /**
     * Créneaux de livraison disponibles.
     */
    public const DELIVERY_SLOTS = [
        '09:00-12:00',
        '12:00-15:00',
    ];

    protected $fillable = [
        'customer_account_id',
        'label',
        'first_name',
        'last_name',
        'phone',
        'address',
        'note',
        'delivery_slot', // créneau de livraison (09:00-12:00 / 12:00-15:00)
        'city',          // texte (compatibilité) = nom anglais de la zone
        'province',      // texte (compatibilité) = nom anglais de la province
        'province_id',
        'zone_id',
        'postal_code',
        'country',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'province_id' => 'integer',
        'zone_id' => 'integer',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /**
     * "province" est déjà une colonne texte, d'où ce nom de relation.
     */
    public function provinceRelation(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    protected static function booted(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Before save : fill text columns from province / zone
        |--------------------------------------------------------------------------
        */
        static::saving(function (CustomerAddress $address) {

            if ($address->isDirty('zone_id') && $address->zone_id) {
                $zone = Zone::with('province')->find($address->zone_id);

                if ($zone) {
                    $address->city = $zone->name;
                    $address->province_id = $zone->province_id;

                    if ($zone->province) {
                        $address->province = $zone->province->name;
                    }
                }

                return;
            }

            if ($address->isDirty('province_id') && $address->province_id) {
                $province = Province::find($address->province_id);

                if ($province) {
                    $address->province = $province->name;
                }

                if (
                    $address->zone_id
                    && !Zone::whereKey($address->zone_id)
                        ->where('province_id', $address->province_id)
                        ->exists()
                ) {
                    $address->zone_id = null;
                }
            }
        });

        /*
        |--------------------------------------------------------------------------
        | After save : the DEFAULT address is the subscription delivery address
        |--------------------------------------------------------------------------
        |
        | Keeps subscriptions.subscriber_address / subscriber_province /
        | subscriber_zone / subscriber_note in sync, so reports and PDFs
        | always use the address chosen by the customer in the app.
        |
        */
        static::saved(function (CustomerAddress $address) {
            if ($address->is_default) {
                $address->syncSubscriptionDeliveryAddress();
            }
        });
    }

    /**
     * Copies this address into the linked subscription columns.
     * Uses a query update: no model events, no loop.
     */
    public function syncSubscriptionDeliveryAddress(): void
    {
        $subscriptionId = CustomerAccount::query()
            ->whereKey($this->customer_account_id)
            ->value('subscription_id');

        if (!$subscriptionId) {
            return;
        }

        $zone = $this->zone_id
            ? Zone::with('province')->find($this->zone_id)
            : null;

        $values = [
            'subscriber_address' => $this->address,
            'subscriber_province' => $zone?->province?->name ?? $this->getAttribute('province'),
            'subscriber_zone' => $zone?->name ?? $this->city,
            'subscriber_note' => $this->note,
            'updated_at' => now(),
        ];

        /*
         * The slot is copied only when the address has one,
         * so an address saved without slot (older app version)
         * never erases the current subscription slot.
         */
        if (filled($this->delivery_slot)) {
            $values['subscriber_delivery_slot'] = $this->delivery_slot;
        }

        Subscription::query()
            ->whereKey($subscriptionId)
            ->update($values);
    }

    /**
     * Values of this address in the old subscription format
     * (subscriber_address, subscriber_province, ...).
     *
     * Used by reports and PDFs, which still read the subscriber_*
     * attributes: they are overwritten in memory only, never saved.
     *
     * Load before: ->with('zone.province')
     */
    public function subscriberAttributes(): array
    {
        $zone = $this->zone;

        $values = [
            'subscriber_address' => $this->address,
            'subscriber_province' => $zone?->province?->name ?? $this->getAttribute('province'),
            'subscriber_zone' => $zone?->name ?? $this->city,
            'subscriber_note' => $this->note,
        ];

        // Same rule as the sync: no slot on the address = keep the subscription slot
        if (filled($this->delivery_slot)) {
            $values['subscriber_delivery_slot'] = $this->delivery_slot;
        }

        return $values;
    }

    /**
     * Replaces in memory the subscriber_* attributes of the subscription
     * with those of its default address (nothing is saved).
     *
     * Load before: ->with('deliveryAddress.zone.province')
     */
    public static function applyToSubscription($subscription): void
    {
        $address = $subscription?->deliveryAddress;

        if (!$address) {
            return;
        }

        foreach ($address->subscriberAttributes() as $key => $value) {
            $subscription->setAttribute($key, $value);
        }

        // Clean, so an accidental ->save() never writes these values back
        $subscription->syncOriginalAttributes(array_keys($address->subscriberAttributes()));
    }

    /**
     * Format JSON pour l'application mobile.
     *
     * Les clés existantes (city, province...) sont conservées à l'identique
     * pour ne pas casser les anciennes versions de l'app.
     *
     * Charger avant : ->with(['provinceRelation', 'zone'])
     */
    public function toMobileArray(string $lang = 'en'): array
    {
        $isArabic = $lang === 'ar';

        $province = $this->provinceRelation;
        $zone = $this->zone;

        $provinceLabel = $province
            ? ($isArabic && filled($province->name_ar) ? $province->name_ar : $province->name)
            : $this->getAttribute('province');

        $zoneLabel = $zone
            ? ($isArabic && filled($zone->name_ar) ? $zone->name_ar : $zone->name)
            : $this->city;

        return [
            'id' => $this->id,
            'customer_account_id' => $this->customer_account_id,
            'label' => $this->label,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'note' => $this->note,
            'delivery_slot' => $this->delivery_slot,
            'city' => $this->city,
            'province' => $this->getAttribute('province'),
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'province_id' => $this->province_id,
            'zone_id' => $this->zone_id,
            'province_label' => $provinceLabel,
            'zone_label' => $zoneLabel,

            'province_data' => $province ? [
                'id' => $province->id,
                'code' => $province->code,
                'name' => $province->name,
                'name_ar' => $province->name_ar,
            ] : null,

            'zone_data' => $zone ? [
                'id' => $zone->id,
                'province_id' => $zone->province_id,
                'name' => $zone->name,
                'name_ar' => $zone->name_ar,
            ] : null,
        ];
    }
}
