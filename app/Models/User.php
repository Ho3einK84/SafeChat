<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'display_name',
        'avatar',
        'public_key',
        'last_seen',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'last_seen' => 'datetime',
        ];
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id', 'device_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ChatGroup::class, 'group_members', 'user_id', 'group_id');
    }

    public function blockedUsers(): HasMany
    {
        return $this->hasMany(BlockedUser::class, 'blocker_id', 'device_id');
    }

    public function touchLastSeen(): void
    {
        $this->forceFill(['last_seen' => now()])->save();
    }
}
