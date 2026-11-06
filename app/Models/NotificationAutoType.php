<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Settings of one automatic notification (texts, on/off, timing).
 * The list of the types is in App\Support\AppNotifications\NotificationTypes.
 */
class NotificationAutoType extends Model
{
    protected $table = 'notification_auto_types';

    protected $fillable = [
        'type_key',
        'enabled',
        'title_en',
        'title_ar',
        'body_en',
        'body_ar',
        'destination',
        'delay_minutes',
        'days_before',
        'send_time',
        'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'delay_minutes' => 'integer',
    ];
}
