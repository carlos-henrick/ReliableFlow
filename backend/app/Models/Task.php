<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    public const MAX_ATTEMPTS = 3;

    public const TYPE_SEND_NOTIFICATION = 'send_notification';

    public const SUPPORTED_TYPES = [self::TYPE_SEND_NOTIFICATION];

    use HasUuids;

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected $fillable = [
        'type',
        'payload',
        'status',
        'attempts',
        'result',
        'last_error',
        'completed_at',
        'idempotency_key',
        'lease_token',
        'lease_expires_at',
    ];

    protected $hidden = ['idempotency_key', 'lease_token', 'lease_expires_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
            'lease_expires_at' => 'datetime',
        ];
    }
}
