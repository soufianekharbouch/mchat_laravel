<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'logo',
        'logo_premium',
        'meal_storage_instructions',
        'welcome_message',
        'subscription_qr_message',
        'last_order_subscription_message',
        'pdf_lang',
        'pdf_first_delivery',
        'pdf_last_delivery',
    ];

    public static function instance(): self
    {
        $setting = self::query()->first();

        if (!$setting) {
            $setting = self::query()->create([
                'logo' => null,
                'logo_premium' => null,
                'meal_storage_instructions' => null,
                'welcome_message' => null,
                'subscription_qr_message' => null,
                'last_order_subscription_message' => null,
                'pdf_lang' => 'en',
                'pdf_first_delivery' => null,
                'pdf_last_delivery' => null,
            ]);
        }

        return $setting;
    }
}