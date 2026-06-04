<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'encrypted_content',
        'plaintext_content',
        'has_password',
        'password_hash',
        'reply_to',
        'group_id',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'has_password' => 'boolean',
            'created_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'group_id' => 'integer',
            'reply_to' => 'integer',
        ];
    }

    public function replyToMessage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to');
    }

    public function seenRecords(): HasMany
    {
        return $this->hasMany(MessageSeen::class, 'message_id');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->whereNull('recipient_id')->whereNull('group_id');
    }

    public function scopePrivate(Builder $query): Builder
    {
        return $query->whereNotNull('recipient_id')->whereNull('group_id');
    }

    public function scopeForGroup(Builder $query, int $groupId): Builder
    {
        return $query->where('group_id', $groupId);
    }

    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    public function isEditable(): bool
    {
        if ($this->deleted_at !== null || $this->has_password) {
            return false;
        }

        return $this->created_at !== null && $this->created_at->diffInSeconds(now()) < 3600;
    }
}
