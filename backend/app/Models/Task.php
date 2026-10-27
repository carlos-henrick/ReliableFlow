<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasUuids;
    protected $fillable = ['type', 'payload', 'status', 'attempts', 'result', 'last_error', 'completed_at',];
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
