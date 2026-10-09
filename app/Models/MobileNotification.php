<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MobileNotification extends Model
{
    public const TYPE_GENERAL = 'general';
    public const TYPE_SUPPORT_TICKET = 'support_ticket';
    public const TYPE_DELIVERY = 'delivery';
    public const TYPE_LOYALTY = 'loyalty';
    public const TYPE_NUTRITION = 'nutrition';
    public const TYPE_PROMOTION = 'promotion';

    protected $table = 'mobile_notifications';

    protected $fillable = [
        'title_en',
        'title_ar',
        'body_en',
        'body_ar',
        'type',
        'target_type',
        'status',
        'sent_at',
    ];

    protected $attributes = [
        'type' => self::TYPE_GENERAL,
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MobileNotificationRecipient::class, 'notification_id');
    }

    public function isAllDevices(): bool
    {
        return $this->target_type === 'all_devices';
    }

    public function isAllCustomers(): bool
    {
        return $this->target_type === 'all_customers';
    }

    public function isSelectedCustomers(): bool
    {
        return $this->target_type === 'selected_customers';
    }

    public function isAnonymousDevices(): bool
    {
        return $this->target_type === 'anonymous_devices';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSending(): bool
    {
        return $this->status === 'sending';
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function isSupportTicket(): bool
    {
        return $this->type === self::TYPE_SUPPORT_TICKET;
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_GENERAL => 'General',
            self::TYPE_SUPPORT_TICKET => 'Support ticket',
            self::TYPE_DELIVERY => 'Delivery',
            self::TYPE_LOYALTY => 'Loyalty',
            self::TYPE_NUTRITION => 'Nutrition',
            self::TYPE_PROMOTION => 'Promotion',
            default => ucfirst(str_replace('_', ' ', (string) $this->type)),
        };
    }

    public function getTargetLabelAttribute(): string
    {
        return match ($this->target_type) {
            'all_devices' => 'All devices',
            'all_customers' => 'All customers',
            'selected_customers' => 'Selected customers',
            'anonymous_devices' => 'Anonymous devices',
            default => 'Unknown target',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'sending' => 'Sending',
            'sent' => 'Sent',
            'failed' => 'Failed',
            default => ucfirst((string) $this->status),
        };
    }
}
