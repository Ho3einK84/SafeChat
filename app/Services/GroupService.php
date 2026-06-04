<?php

namespace App\Services;

use App\Models\ChatGroup;
use App\Models\User;
use App\Support\InputSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;

final class GroupService
{
    /**
     * @return array<string, mixed>
     */
    public function create(User $creator, string $name, string $encryptedKeyForCreator, bool $isAdmin): array
    {
        if (! $isAdmin) {
            return ['error' => 'فقط ادمین می‌تواند گروه بسازد'];
        }

        $name = InputSanitizer::sanitize($name, 100);

        if ($name === '') {
            return ['error' => 'نام گروه الزامی است'];
        }

        $encryptedKeyForCreator = trim($encryptedKeyForCreator);

        if ($encryptedKeyForCreator === '') {
            return ['error' => 'کلید رمزنگاری گروه الزامی است'];
        }

        try {
            $group = DB::transaction(function () use ($creator, $name, $encryptedKeyForCreator) {
                $group = ChatGroup::query()->create([
                    'name' => $name,
                    'created_by' => $creator->id,
                    'encrypted_key' => json_encode([(string) $creator->id => $encryptedKeyForCreator], JSON_THROW_ON_ERROR),
                ]);

                DB::table('group_members')->insert([
                    'group_id' => $group->id,
                    'user_id' => $creator->id,
                ]);

                return $group;
            });
        } catch (JsonException) {
            return ['error' => 'خطا در پردازش کلید گروه'];
        }

        Log::channel('safechat')->info('Group created', ['group_id' => $group->id, 'name' => $name]);

        return ['success' => true, 'id' => $group->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function addMember(int $groupId, string $deviceId, string $encryptedKeyForNewMember, bool $isAdmin): array
    {
        if (! $isAdmin) {
            return ['error' => 'فقط ادمین می‌تواند عضو اضافه کند'];
        }

        if (! preg_match('/^[a-zA-Z0-9]{8}$/', $deviceId)) {
            return ['error' => 'شناسه کاربری نامعتبر است'];
        }

        $encryptedKeyForNewMember = trim($encryptedKeyForNewMember);
        if ($encryptedKeyForNewMember === '') {
            return ['error' => 'کلید رمزنگاری الزامی است'];
        }

        $user = User::query()->where('device_id', $deviceId)->first();

        if (! $user) {
            return ['error' => 'کاربر یافت نشد'];
        }

        $group = ChatGroup::query()->find($groupId);

        if (! $group) {
            return ['error' => 'گروه یافت نشد'];
        }

        $stored = DB::transaction(function () use ($groupId, $user, $encryptedKeyForNewMember) {
            DB::table('group_members')->insertOrIgnore([
                'group_id' => $groupId,
                'user_id' => $user->id,
            ]);

            return $this->storeGroupKey($groupId, $user->id, $encryptedKeyForNewMember);
        });

        if (! $stored) {
            Log::channel('safechat')->error('Failed to store group key', [
                'group_id' => $groupId,
                'user_id' => $user->id,
            ]);

            return ['error' => 'خطا در ذخیره کلید گروه'];
        }

        return ['success' => true];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUserGroups(User $user): array
    {
        return ChatGroup::query()
            ->join('group_members as gm', 'gm.group_id', '=', 'groups.id')
            ->where('gm.user_id', $user->id)
            ->orderByDesc('groups.id')
            ->get(['groups.id', 'groups.name', 'groups.created_at'])
            ->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'created_at' => $g->created_at,
            ])
            ->all();
    }

    public function getGroupKey(int $groupId, int $userId): ?string
    {
        if ($groupId <= 0 || $userId <= 0) {
            return null;
        }

        if (! DB::table('group_members')->where('group_id', $groupId)->where('user_id', $userId)->exists()) {
            return null;
        }

        $group = ChatGroup::query()->find($groupId);

        if (! $group || ! $group->encrypted_key) {
            return null;
        }

        try {
            $keys = json_decode($group->encrypted_key, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($keys)) {
            return null;
        }

        return $keys[$userId] ?? $keys[(string) $userId] ?? null;
    }

    /**
     * Store an encrypted group key for a user using a pessimistic lock.
     * Returns true on success, false if the group record could not be locked.
     */
    private function storeGroupKey(int $groupId, int $userId, string $encryptedKey): bool
    {
        $locked = ChatGroup::query()->where('id', $groupId)->lockForUpdate()->first();

        if (! $locked) {
            return false;
        }

        $existingJson = (string) $locked->encrypted_key;
        try {
            $keys = json_decode($existingJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $keys = [];
        }

        if (! is_array($keys)) {
            $keys = [];
        }

        $keys[(string) $userId] = $encryptedKey;

        try {
            $encoded = json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        ChatGroup::query()->where('id', $groupId)->update(['encrypted_key' => $encoded]);

        return true;
    }
}
