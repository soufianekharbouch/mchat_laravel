<?php

namespace App\Models;

use App\Support\AppNotifications\NotificationDestinations;
use App\Support\AppNotifications\NotificationTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MobileNotification extends Model
{
    public const TYPE_GENERAL = 'general';
    public const TYPE_SUPPORT_TICKET = 'support_ticket';
    public const TYPE_DELIVERY = 'delivery';
    public const TYPE_LOYALTY = 'loyalty';
    public const TYPE_NUTRITION = 'nutrition';
    public const TYPE_PROMOTION = 'promotion';

    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    /**
     * draft / sending / sent / failed : old system (kept)
     * scheduled : waiting for its date (can be edited / cancelled)
     * cancelled : cancelled by the team, or no longer needed
     */
    public const STATUSES = [
        'scheduled' => ['label' => 'Scheduled', 'color' => 'primary', 'icon' => 'fa-clock'],
        'sending' => ['label' => 'Sending', 'color' => 'info', 'icon' => 'fa-spinner'],
        'sent' => ['label' => 'Sent', 'color' => 'success', 'icon' => 'fa-check'],
        'failed' => ['label' => 'Failed', 'color' => 'danger', 'icon' => 'fa-exclamation-triangle'],
        'cancelled' => ['label' => 'Cancelled', 'color' => 'secondary', 'icon' => 'fa-ban'],
        'draft' => ['label' => 'Draft', 'color' => 'light', 'icon' => 'fa-pen'],
    ];

    /** Manual notifications: who receives it. */
    public const TARGETS = [
        'selected_customers' => 'Selected customers',
        'all_customers' => 'All the customers of the app',
        'active_plan' => 'Customers with an active plan',
        'no_active_plan' => 'Customers without an active plan',
    ];

    protected $table = 'mobile_notifications';

    protected $fillable = [
        'title_en',
        'title_ar',
        'body_en',
        'body_ar',
        'type',
        'source',
        'auto_type',
        'target_type',
        'status',
        'scheduled_at',
        'sent_at',
        'destination',
        'destination_params',
        'dedupe_key',
        'customer_account_id',
        'purchase_id',
        'context',
        'created_by',
        'edited_at',
        'edited_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'last_error',
    ];

    protected $attributes = [
        'type' => self::TYPE_GENERAL,
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'edited_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'destination_params' => 'array',
            'context' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function recipients(): HasMany
    {
        return $this->hasMany(MobileNotificationRecipient::class, 'notification_id');
    }

    /** The customer when the notification is for one customer only. */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /** Due: scheduled and its date reached. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isAuto(): bool
    {
        return $this->source === self::SOURCE_AUTO;
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
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

    /** Target computed when it is sent (all / with plan / without plan). */
    public function isGroupTarget(): bool
    {
        return in_array($this->target_type, ['all_customers', 'active_plan', 'no_active_plan'], true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isSending(): bool
    {
        return $this->status === 'sending';
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** Can still be changed by the team (texts, date, destination, cancel). */
    public function isEditable(): bool
    {
        return in_array($this->status, ['scheduled', 'failed', 'cancelled', 'draft'], true);
    }

    public function isSupportTicket(): bool
    {
        return $this->type === self::TYPE_SUPPORT_TICKET;
    }

    /** Date shown in the lists: planned date, else sending date. */
    public function displayDate()
    {
        return $this->scheduled_at ?? $this->sent_at ?? $this->created_at;
    }

    public function statusConfig(): array
    {
        return self::STATUSES[$this->status]
            ?? ['label' => ucfirst((string) $this->status), 'color' => 'secondary', 'icon' => 'fa-circle'];
    }

    public function destinationLabel(): string
    {
        return NotificationDestinations::describe($this->destination, $this->destination_params);
    }

    /** "Order prepared" / "Manual" / "Support ticket"... */
    public function originLabel(): string
    {
        if ($this->isAuto()) {
            return NotificationTypes::label($this->auto_type);
        }

        if ($this->isManual()) {
            return 'Manual';
        }

        return $this->type_label;
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
            'anonymous_devices' => 'Anonymous devices',
            default => self::TARGETS[$this->target_type] ?? 'Unknown target',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->statusConfig()['label'];
    }
}
