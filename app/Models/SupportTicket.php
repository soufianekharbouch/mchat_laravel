<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';

    protected $fillable = [
        'customer_account_id', 'title', 'status', 'last_message_at',
        'admin_last_read_at', 'resolved_at', 'closed_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'admin_last_read_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SupportTicketActivity::class)->orderBy('created_at');
    }
}
