<?php

namespace App\Support\AppNotifications;

use App\Models\MobileNotification;
use App\Models\NotificationAutoType;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The AUTOMATIC notifications of the app: the only list to change.
 *
 *  - Add a type: add an entry below (+ one call where the event happens,
 *    or a reminder in ReminderPlanner). No SQL: its settings row (texts,
 *    on/off, timing) is created with these defaults the first time.
 *  - Remove a type: delete its entry. Nothing more is sent, the old
 *    notifications stay in the history.
 *  - The texts written here are only the DEFAULTS: the team changes them
 *    in Notifications > Automatic types (saved in notification_auto_types).
 *
 * kind     event    : sent when it happens (+ delay_minutes)
 *          reminder : sent X days before a date (days_before, at send_time)
 * params   values given to the app with the destination (only these
 *          destinations can be chosen for the type)
 * vars     {placeholders} usable in the texts
 */
class NotificationTypes
{
    public const TYPES = [

        'order_prepared' => [
            'label' => 'Order prepared',
            'kind' => 'event',
            'category' => MobileNotification::TYPE_DELIVERY,
            'description' => 'All the pets of a delivery are prepared (Daily report).',
            'destination' => 'delivery',
            'params' => ['planned_order_id', 'animal_id', 'date'],
            'vars' => ['pet', 'date'],
            'delay_minutes' => 0,
            'title_en' => 'Your order is ready 🐾',
            'title_ar' => 'طلبك جاهز 🐾',
            'body_en' => "{pet}'s meals for {date} are prepared and will be handed to the driver soon.",
            'body_ar' => 'وجبات {pet} ليوم {date} جاهزة وسيتم تسليمها للسائق قريباً.',
        ],

        'order_on_the_way' => [
            'label' => 'Order on the way',
            'kind' => 'event',
            'category' => MobileNotification::TYPE_DELIVERY,
            'description' => 'The JAAK driver has the parcel.',
            'destination' => 'delivery',
            'params' => ['planned_order_id', 'animal_id', 'date'],
            'vars' => ['pet', 'date'],
            'delay_minutes' => 0,
            'title_en' => 'Your order is on the way 🚚',
            'title_ar' => 'طلبك في الطريق 🚚',
            'body_en' => "The driver has {pet}'s meals and is heading to you.",
            'body_ar' => 'السائق استلم وجبات {pet} وهو في الطريق إليك.',
        ],

        'order_delivered' => [
            'label' => 'Order delivered',
            'kind' => 'event',
            'category' => MobileNotification::TYPE_DELIVERY,
            'description' => 'JAAK marked the delivery as delivered.',
            'destination' => 'delivery',
            'params' => ['planned_order_id', 'animal_id', 'date'],
            'vars' => ['pet', 'date'],
            'delay_minutes' => 0,
            'title_en' => 'Order delivered ✅',
            'title_ar' => 'تم توصيل طلبك ✅',
            'body_en' => "{pet}'s meals have been delivered. Enjoy!",
            'body_ar' => 'تم توصيل وجبات {pet}. بالهناء والشفاء!',
        ],

        'premium_ready' => [
            'label' => 'Premium pack ready',
            'kind' => 'event',
            'category' => MobileNotification::TYPE_NUTRITION,
            'description' => 'The team assigned the custom recipes of a PREMIUM pack.',
            'destination' => 'plan_deliveries',
            'params' => ['purchase_id', 'animal_id', 'product_id'],
            'vars' => ['pet', 'product'],
            'delay_minutes' => 0,
            'title_en' => 'Your custom recipes are ready 🐾',
            'title_ar' => 'وصفاتك المخصصة جاهزة 🐾',
            'body_en' => "We prepared {pet}'s own recipes. Open the app to choose your delivery days.",
            'body_ar' => 'حضّرنا وصفات خاصة بـ {pet}. افتح التطبيق لاختيار أيام التوصيل.',
        ],

        'payment_confirmed' => [
            'label' => 'Payment confirmed',
            'kind' => 'event',
            'category' => MobileNotification::TYPE_GENERAL,
            'description' => 'Online payment (Hesabe) confirmed.',
            'destination' => 'pet',
            'params' => ['purchase_id', 'animal_id', 'product_id'],
            'vars' => ['pet', 'amount', 'reference'],
            'delay_minutes' => 0,
            'title_en' => 'Payment confirmed ✅',
            'title_ar' => 'تم تأكيد الدفع ✅',
            'body_en' => 'We received your payment of {amount} KWD for {pet}. Thank you!',
            'body_ar' => 'استلمنا دفعتك بقيمة {amount} د.ك لـ {pet}. شكراً لك!',
        ],

        'plan_ending' => [
            'label' => 'Plan ending (renew)',
            'kind' => 'reminder',
            'category' => MobileNotification::TYPE_NUTRITION,
            'description' => 'Paid pack (not trial) close to its end date. Not sent if the pet already has a newer pack.',
            'destination' => 'pet_plans',
            'params' => ['purchase_id', 'animal_id', 'product_id'],
            'vars' => ['pet', 'product', 'end_date', 'days'],
            'days_before' => '5,1',
            'send_time' => '10:00',
            'title_en' => "{pet}'s plan ends soon 🐾",
            'title_ar' => 'باقة {pet} تنتهي قريباً 🐾',
            'body_en' => "{pet}'s plan ends on {end_date}. Renew now so the meals keep coming without a break.",
            'body_ar' => 'تنتهي باقة {pet} في {end_date}. جدّد الآن لتستمر الوجبات بدون انقطاع.',
        ],

        'trial_ending' => [
            'label' => 'Trial pack ending (subscribe)',
            'kind' => 'reminder',
            'category' => MobileNotification::TYPE_PROMOTION,
            'description' => 'Trial pack close to its end date. Not sent if the pet already has a paid plan after it.',
            'destination' => 'pet_plans',
            'params' => ['purchase_id', 'animal_id', 'product_id'],
            'vars' => ['pet', 'product', 'end_date', 'days'],
            'days_before' => '2',
            'send_time' => '10:00',
            'title_en' => "{pet}'s trial ends soon 🎁",
            'title_ar' => 'تجربة {pet} تنتهي قريباً 🎁',
            'body_en' => 'The trial pack ends on {end_date}. Subscribe now to keep your trial discount.',
            'body_ar' => 'تنتهي باقة التجربة في {end_date}. اشترك الآن واستفد من خصم التجربة.',
        ],
    ];

    /** Description of the {placeholders} (settings page). */
    public const VARS = [
        'pet' => 'Pet name(s)',
        'date' => 'Delivery date (dd/mm)',
        'product' => 'Pack name',
        'amount' => 'Amount paid (KWD)',
        'reference' => 'Payment reference',
        'end_date' => 'Last day of the pack (dd/mm/yyyy)',
        'days' => 'Days left',
    ];

    /** @var array<string, NotificationAutoType> */
    private static array $settingsCache = [];

    public static function all(): array
    {
        return self::TYPES;
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::TYPES[$key]);
    }

    public static function get(?string $key): ?array
    {
        return self::exists($key) ? self::TYPES[$key] : null;
    }

    public static function label(?string $key): string
    {
        return self::TYPES[$key]['label'] ?? ucfirst(str_replace('_', ' ', (string) $key));
    }

    /** @return string[] keys of the reminder types */
    public static function reminders(): array
    {
        return array_keys(array_filter(self::TYPES, fn ($type) => $type['kind'] === 'reminder'));
    }

    /**
     * Settings of a type (row created with the defaults when missing).
     */
    public static function settings(string $key): ?NotificationAutoType
    {
        $definition = self::get($key);

        if (!$definition) {
            return null;
        }

        if (isset(self::$settingsCache[$key])) {
            return self::$settingsCache[$key];
        }

        $settings = NotificationAutoType::firstOrCreate(
            ['type_key' => $key],
            [
                'enabled' => true,
                'title_en' => $definition['title_en'],
                'title_ar' => $definition['title_ar'],
                'body_en' => $definition['body_en'],
                'body_ar' => $definition['body_ar'],
                'destination' => $definition['destination'],
                'delay_minutes' => (int) ($definition['delay_minutes'] ?? 0),
                'days_before' => $definition['days_before'] ?? null,
                'send_time' => $definition['send_time'] ?? null,
            ]
        );

        return self::$settingsCache[$key] = $settings;
    }

    public static function forgetCache(): void
    {
        self::$settingsCache = [];
    }

    public static function enabled(string $key): bool
    {
        try {
            return (bool) self::settings($key)?->enabled;
        } catch (Throwable) {
            return false; // table not created yet
        }
    }

    /** Destination of the type (setting, else default). */
    public static function destination(string $key): string
    {
        $settings = self::settings($key);
        $destination = $settings?->destination ?: (self::TYPES[$key]['destination'] ?? 'notifications');

        return NotificationDestinations::exists($destination) ? $destination : 'notifications';
    }

    /**
     * Days before the date for a reminder ("5,1" -> [5, 1]).
     *
     * @return int[]
     */
    public static function daysBefore(string $key): array
    {
        $raw = (string) (self::settings($key)?->days_before ?? self::TYPES[$key]['days_before'] ?? '');

        return collect(preg_split('/[^0-9]+/', $raw))
            ->filter(fn ($value) => $value !== '' && (int) $value >= 0 && (int) $value <= 60)
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    public static function sendTime(string $key): string
    {
        $time = (string) (self::settings($key)?->send_time ?: (self::TYPES[$key]['send_time'] ?? '10:00'));

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '10:00';
    }

    /**
     * Replaces the {placeholders}. A value can be one text for both
     * languages, or ['en' => ..., 'ar' => ...].
     */
    public static function render(string $text, array $vars, string $lang): string
    {
        $replace = [];

        foreach ($vars as $name => $value) {
            if (is_array($value)) {
                $value = $value[$lang] ?? $value['en'] ?? reset($value);
            }

            $replace['{' . $name . '}'] = (string) $value;
        }

        return trim(preg_replace('/\s{2,}/u', ' ', strtr($text, $replace)));
    }

    /** True when the settings table exists (SQL run). */
    public static function installed(): bool
    {
        try {
            return Schema::hasTable('notification_auto_types');
        } catch (Throwable) {
            return false;
        }
    }
}
