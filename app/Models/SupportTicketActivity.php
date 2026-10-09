<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'support_ticket_id', 'actor_type', 'actor_id', 'action',
        'old_status', 'new_status', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
