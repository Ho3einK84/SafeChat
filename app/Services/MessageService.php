<?php

namespace App\Services;

use App\Models\BlockedUser;
use App\Models\Message;
use App\Models\MessageSeen;
use App\Models\User;
use App\Support\InputSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MessageService
{
    public function __construct(
        private readonly EncryptionService $encryption,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function send(
        User $sender,
        string $content,
        ?string $recipientDeviceId = null,
        ?string $password = null,
        ?int $replyTo = null,
        ?int $groupId = null,
    ): array {
        $maxLen = (int) config('safechat.max_msg_length', 2000);
        $content = InputSanitizer::sanitize($content, $maxLen);

        if ($content === '') {
            return ['error' => 'پیام خالی است'];
        }

        if (mb_strlen($content) > $maxLen) {
            return ['error' => 'پیام خیلی طولانی است'];
        }

        if ($recipientDeviceId !== null) {
            $recipientDeviceId = InputSanitizer::sanitize($recipientDeviceId, 8);

            if (! preg_match('/^[a-zA-Z0-9]{8}$/', $recipientDeviceId)) {
                return ['error' => 'شناسه کاربری نامعتبر است'];
            }

            if ($recipientDeviceId === $sender->device_id) {
                return ['error' => 'نمی‌توانید به خودتان پیام بفرستید'];
            }

            if (! User::query()->where('device_id', $recipientDeviceId)->exists()) {
                return ['error' => 'کاربر یافت نشد'];
            }

            if ($this->isBlockedBetween($sender->device_id, $recipientDeviceId)) {
                if ($this->isUserBlocked($recipientDeviceId, $sender->device_id)) {
                    return ['error' => 'این کاربر شما را بلاک کرده است'];
                }

                return ['error' => 'شما این کاربر را بلاک کرده‌اید'];
            }
        }

        $hasPassword = $password !== null && $password !== '';
        try {
            $passwordHash = $hasPassword ? $this->encryption->hashPassword((string) $password) : null;
        } catch (RuntimeException) {
            return ['error' => 'خطا در پردازش رمز'];
        }
        $plaintextContent = null;

        if ($groupId !== null || $recipientDeviceId !== null) {
            if ($groupId !== null) {
                if ($groupId <= 0) {
                    return ['error' => 'شناسه گروه نامعتبر'];
                }

                if (! DB::table('groups')->where('id', $groupId)->exists()) {
                    return ['error' => 'گروه یافت نشد'];
                }

                $isMember = DB::table('group_members')
                    ->where('group_id', $groupId)
                    ->where('user_id', $sender->id)
                    ->exists();

                if (! $isMember) {
                    return ['error' => 'شما عضو این گروه نیستید'];
                }
            }

            $encryptedContent = $content;
        } else {
            try {
                $encryptedContent = $this->encryption->encrypt($content);
                if (! $hasPassword) {
                    $plaintextContent = $content;
                }
            } catch (\Throwable $e) {
                Log::channel('safechat')->error('Failed to encrypt public message', ['error' => $e->getMessage()]);

                return ['error' => 'خطا در رمزنگاری پیام'];
            }
        }

        $message = Message::query()->create([
            'sender_id' => $sender->device_id,
            'recipient_id' => $recipientDeviceId,
            'encrypted_content' => $encryptedContent,
            'plaintext_content' => $plaintextContent,
            'has_password' => $hasPassword,
            'password_hash' => $passwordHash,
            'reply_to' => $replyTo ?: null,
            'group_id' => $groupId,
        ]);

        return ['success' => true, 'id' => $message->id];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPublicMessages(User $viewer, int $afterId = 0): array
    {
        $limit = (int) config('safechat.msg_limit', 50);

        // Fetch newest messages DESC (to respect the limit), then reverse for chronological display
        $rows = Message::query()
            ->public()
            ->where('id', '>', $afterId)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get(['id', 'sender_id', 'recipient_id', 'encrypted_content', 'has_password', 'created_at', 'reply_to', 'deleted_at', 'edited_at']);

        return $this->formatRows($rows, $viewer->device_id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPrivateMessages(User $viewer, string $otherDeviceId, int $afterId = 0): array
    {
        if ($this->isBlockedBetween($viewer->device_id, $otherDeviceId)) {
            return [];
        }

        $limit = (int) config('safechat.msg_limit', 50);

        $rows = Message::query()
            ->private()
            ->where('messages.id', '>', $afterId)
            ->where(function ($q) use ($viewer, $otherDeviceId) {
                $q->where(function ($q2) use ($viewer, $otherDeviceId) {
                    $q2->where('sender_id', $viewer->device_id)
                        ->where('recipient_id', $otherDeviceId);
                })->orWhere(function ($q2) use ($viewer, $otherDeviceId) {
                    $q2->where('sender_id', $otherDeviceId)
                        ->where('recipient_id', $viewer->device_id);
                });
            })
            ->leftJoin('message_seen as ms', function ($join) use ($viewer) {
                $join->on('ms.message_id', '=', 'messages.id')
                    ->where('ms.user_id', '=', $viewer->id);
            })
            ->orderBy('messages.id', 'asc')
            ->limit($limit)
            ->get([
                'messages.id',
                'messages.sender_id',
                'messages.recipient_id',
                'messages.encrypted_content',
                'messages.has_password',
                'messages.created_at',
                'messages.reply_to',
                'messages.deleted_at',
                'messages.edited_at',
                'ms.seen_at',
            ]);

        return $this->formatRows($rows, $viewer->device_id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGroupMessages(int $groupId, User $viewer, int $afterId = 0): array
    {
        if (! DB::table('groups')->where('id', $groupId)->exists()) {
            throw new NotFoundHttpException('گروه یافت نشد');
        }

        if (! DB::table('group_members')->where('group_id', $groupId)->where('user_id', $viewer->id)->exists()) {
            throw new AccessDeniedHttpException('دسترسی غیرمجاز');
        }

        $limit = (int) config('safechat.msg_limit', 50);

        $rows = Message::query()
            ->forGroup($groupId)
            ->where('id', '>', $afterId)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get([
                'id', 'sender_id', 'recipient_id', 'group_id', 'encrypted_content',
                'has_password', 'created_at', 'reply_to', 'deleted_at', 'edited_at',
            ]);

        return $this->formatRows($rows, $viewer->device_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function unlock(int $messageId, User $viewer, string $password): array
    {
        $message = Message::query()
            ->where('id', $messageId)
            ->where('has_password', true)
            ->whereNull('deleted_at')
            ->first(['encrypted_content', 'password_hash', 'recipient_id', 'group_id', 'sender_id']);

        if (! $message) {
            Log::channel('safechat')->warning('Unlock failed: message not found', ['msg_id' => $messageId]);

            return ['error' => 'پیام یافت نشد'];
        }

        if ($message->recipient_id !== null) {
            if ($viewer->device_id !== $message->sender_id && $viewer->device_id !== $message->recipient_id) {
                return ['error' => 'دسترسی غیرمجاز'];
            }
        } elseif ($message->group_id !== null) {
            $isMember = DB::table('group_members')
                ->where('group_id', $message->group_id)
                ->where('user_id', $viewer->id)
                ->exists();

            if (! $isMember) {
                return ['error' => 'دسترسی غیرمجاز'];
            }
        }

        if (! $this->encryption->verifyPassword($password, (string) $message->password_hash)) {
            Log::channel('safechat')->warning('Unlock failed: wrong password', ['msg_id' => $messageId]);

            return ['error' => 'رمز اشتباه است'];
        }

        if ($message->recipient_id !== null || $message->group_id !== null) {
            return ['content' => $message->encrypted_content];
        }

        $decrypted = $this->encryption->decrypt($message->encrypted_content);
        if ($decrypted === '[decryption error]') {
            return ['error' => 'خطا در رمزگشایی پیام'];
        }

        return ['content' => $decrypted];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $msgId, User $user, bool $isAdmin): array
    {
        $message = Message::query()
            ->where('id', $msgId)
            ->whereNull('deleted_at')
            ->first(['id', 'sender_id']);

        if (! $message) {
            return ['error' => 'پیام یافت نشد'];
        }

        if ($message->sender_id !== $user->device_id && ! $isAdmin) {
            return ['error' => 'شما مجاز به حذف این پیام نیستید'];
        }

        Message::query()->where('id', $msgId)->update(['deleted_at' => now()]);

        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function edit(int $msgId, User $user, string $newContent): array
    {
        $maxLen = (int) config('safechat.max_msg_length', 2000);
        $newContent = InputSanitizer::sanitize($newContent, $maxLen);

        if ($newContent === '') {
            return ['error' => 'متن پیام خالی است'];
        }

        $message = Message::query()
            ->where('id', $msgId)
            ->where('sender_id', $user->device_id)
            ->first();

        if (! $message) {
            return ['error' => 'پیام یافت نشد یا شما مجاز به ویرایش نیستید'];
        }

        if ($message->deleted_at !== null) {
            return ['error' => 'پیام حذف شده قابل ویرایش نیست'];
        }

        if ($message->has_password) {
            return ['error' => 'ویرایش پیام قفل‌دار مجاز نیست'];
        }

        if (! $message->isEditable()) {
            return ['error' => 'زمان ویرایش پیام گذشته است'];
        }

        $plaintextContent = null;

        if ($message->recipient_id !== null || $message->group_id !== null) {
            $storedContent = $newContent;
        } else {
            try {
                $storedContent = $this->encryption->encrypt($newContent);
                $plaintextContent = $newContent;
            } catch (\Throwable $e) {
                Log::channel('safechat')->error('Failed to encrypt edited public message', [
                    'error' => $e->getMessage(),
                    'msg_id' => $msgId,
                ]);

                return ['error' => 'خطا در رمزنگاری پیام'];
            }
        }

        $message->update([
            'encrypted_content' => $storedContent,
            'plaintext_content' => $plaintextContent,
            'edited_at' => now(),
        ]);

        return ['success' => true];
    }

    public function markSeen(int $msgId, User $viewer): void
    {
        $message = Message::query()
            ->where('id', $msgId)
            ->whereNull('deleted_at')
            ->first(['id', 'sender_id', 'recipient_id', 'group_id']);

        if (! $message) {
            return;
        }

        if ($message->recipient_id !== null) {
            if ($message->recipient_id !== $viewer->device_id) {
                return;
            }
        } elseif ($message->group_id !== null) {
            $isMember = DB::table('group_members')
                ->where('group_id', $message->group_id)
                ->where('user_id', $viewer->id)
                ->exists();

            if (! $isMember) {
                return;
            }
        } else {
            return;
        }

        MessageSeen::query()->insertOrIgnore([
            'message_id' => $msgId,
            'user_id' => $viewer->id,
            'seen_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getConversations(User $user): array
    {
        $deviceId = $user->device_id;

        $sub = DB::table('messages')
            ->selectRaw('CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END AS other_id, created_at', [$deviceId])
            ->where(function ($q) use ($deviceId) {
                $q->where('sender_id', $deviceId)->orWhere('recipient_id', $deviceId);
            })
            ->whereNotNull('recipient_id')
            ->where('recipient_id', '!=', '')
            ->whereNull('deleted_at')
            ->whereNull('group_id');

        $rows = DB::table(DB::raw("({$sub->toSql()}) as sub"))
            ->mergeBindings($sub)
            ->selectRaw('other_id, MAX(created_at) AS last_message')
            ->groupBy('other_id')
            ->orderByDesc('last_message')
            ->get();

        return $rows
            ->filter(fn ($r) => ! empty($r->other_id) && ! $this->isBlockedBetween($deviceId, (string) $r->other_id))
            ->values()
            ->map(fn ($r) => ['other_id' => $r->other_id, 'last_message' => $r->last_message])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchMessages(string $query, User $viewer): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        try {
            $messages = Message::query()
                ->public()
                ->notDeleted()
                ->where('has_password', false)
                ->whereFullText('plaintext_content', $query)
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'sender_id', 'encrypted_content', 'has_password', 'created_at', 'reply_to', 'deleted_at', 'edited_at']);
        } catch (\Illuminate\Database\QueryException) {
            $messages = Message::query()
                ->public()
                ->notDeleted()
                ->where('has_password', false)
                ->where('plaintext_content', 'like', '%'.$query.'%')
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'sender_id', 'encrypted_content', 'has_password', 'created_at', 'reply_to', 'deleted_at', 'edited_at']);
        }

        return $this->formatRows($messages, $viewer->device_id);
    }

    public function isUserBlocked(string $blockerDeviceId, string $blockedDeviceId): bool
    {
        if (strlen($blockerDeviceId) !== 8 || strlen($blockedDeviceId) !== 8) {
            return false;
        }

        return BlockedUser::query()
            ->where('blocker_id', $blockerDeviceId)
            ->where('blocked_id', $blockedDeviceId)
            ->exists();
    }

    public function isBlockedBetween(string $deviceA, string $deviceB): bool
    {
        return $this->isUserBlocked($deviceA, $deviceB) || $this->isUserBlocked($deviceB, $deviceA);
    }

    /**
     * @return array<string, mixed>
     */
    public function getBlockStatus(string $myDeviceId, string $otherDeviceId): array
    {
        $iBlocked = $this->isUserBlocked($myDeviceId, $otherDeviceId);
        $blockedMe = $this->isUserBlocked($otherDeviceId, $myDeviceId);

        return [
            'i_blocked' => $iBlocked,
            'blocked_me' => $blockedMe,
            'is_blocked' => $iBlocked || $blockedMe,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getBlockedUsers(string $blockerDeviceId): array
    {
        return BlockedUser::query()
            ->from('blocked_users as b')
            ->leftJoin('users as u', 'u.device_id', '=', 'b.blocked_id')
            ->where('b.blocker_id', $blockerDeviceId)
            ->orderByDesc('b.created_at')
            ->get(['b.blocked_id', 'b.created_at', 'u.display_name'])
            ->map(fn ($r) => [
                'blocked_id' => $r->blocked_id,
                'created_at' => $r->created_at,
                'display_name' => $r->display_name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function blockUser(User $blocker, string $blockedDeviceId): array
    {
        if ($blocker->device_id === $blockedDeviceId) {
            return ['error' => 'نمی‌توانید خودتان را بلاک کنید'];
        }

        if (! User::query()->where('device_id', $blockedDeviceId)->exists()) {
            return ['error' => 'کاربر یافت نشد'];
        }

        if ($this->isUserBlocked($blocker->device_id, $blockedDeviceId)) {
            return ['success' => true, 'already_blocked' => true];
        }

        BlockedUser::query()->create([
            'blocker_id' => $blocker->device_id,
            'blocked_id' => $blockedDeviceId,
        ]);

        Log::channel('safechat')->info('User blocked', [
            'blocker' => $blocker->device_id,
            'blocked' => $blockedDeviceId,
        ]);

        return ['success' => true, 'blocked_id' => $blockedDeviceId];
    }

    /**
     * @return array<string, mixed>
     */
    public function unblockUser(User $blocker, string $blockedDeviceId): array
    {
        if (! $this->isUserBlocked($blocker->device_id, $blockedDeviceId)) {
            return ['error' => 'این کاربر در لیست بلاک شما نیست'];
        }

        BlockedUser::query()
            ->where('blocker_id', $blocker->device_id)
            ->where('blocked_id', $blockedDeviceId)
            ->delete();

        return ['success' => true, 'blocked_id' => $blockedDeviceId];
    }

    /**
     * @param  Collection<int, Message|\stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function formatRows(Collection $rows, string $viewerDeviceId): array
    {
        return $rows
            ->values()
            ->map(fn ($row) => $this->buildMessageRow((array) (is_array($row) ? $row : $row->toArray()), $viewerDeviceId))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function buildMessageRow(array $row, string $viewerId): array
    {
        $isMine = ($row['sender_id'] ?? '') === $viewerId;
        $row['has_password'] = (int) ($row['has_password'] ?? 0);

        if (! empty($row['group_id'])) {
            $row['group_id'] = (int) $row['group_id'];
        }

        $isDeleted = ! empty($row['deleted_at']);

        if ($isDeleted) {
            $row['content'] = '⛔ این پیام حذف شده است';
            $row['deleted'] = true;
        } elseif ($row['has_password'] && ! $isMine) {
            $row['content'] = null;
            $row['deleted'] = false;
        } else {
            if (! empty($row['group_id']) || ($row['recipient_id'] ?? null) !== null) {
                $row['content'] = $row['encrypted_content'];
            } else {
                $decrypted = $this->encryption->decrypt((string) $row['encrypted_content']);
                $row['content'] = $decrypted === '[decryption error]' ? null : $decrypted;
            }
            $row['deleted'] = false;
        }

        unset($row['encrypted_content'], $row['password_hash']);

        if ($isMine && ($row['recipient_id'] ?? null) !== null) {
            $row['seen'] = ! empty($row['seen_at']);
        }

        unset($row['seen_at']);

        return $row;
    }
}
