<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipNotification extends Model
{
    protected $fillable = [
        'gimnasio_id',
        'member_id',
        'membership_id',
        'channel',
        'type',
        'status',
        'provider_message_id',
        'error_message',
        'metadata',
        'sent_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'sent_at' => 'datetime',
    ];
}
