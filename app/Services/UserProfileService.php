<?php

namespace App\Services;

use App\Models\User;
use App\Support\InputSanitizer;

final class UserProfileService
{
    /**
     * @return array<string, mixed>
     */
    public function getProfile(string $deviceId): array
    {
        $user = User::query()->where('device_id', $deviceId)->first([
            'device_id', 'display_name', 'created_at', 'last_seen',
        ]);

        if (! $user) {
            return [
                'exists' => false,
                'device_id' => $deviceId,
                'display_name' => null,
                'created_at' => null,
                'last_seen' => null,
                'is_online' => false,
            ];
        }

        return [
            'exists' => true,
            'device_id' => $user->device_id,
            'display_name' => $user->display_name ?: null,
            'created_at' => $user->created_at,
            'last_seen' => $user->last_seen,
            'is_online' => $user->last_seen && $user->last_seen->diffInSeconds(now()) < 120,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateProfile(User $user, ?string $displayName): array
    {
        if ($displayName !== null) {
            $displayName = InputSanitizer::sanitize($displayName, 30);

            if ($displayName === '') {
                return ['error' => 'نام نمی‌تواند خالی باشد'];
            }

            $user->update(['display_name' => $displayName]);
        }

        return ['success' => true];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOnlineUsers(): array
    {
        return User::query()
            ->where('last_seen', '>=', now()->subMinutes(2))
            ->orderByDesc('last_seen')
            ->get(['device_id', 'display_name'])
            ->map(fn (User $u) => [
                'device_id' => $u->device_id,
                'display_name' => $u->display_name,
            ])
            ->all();
    }
}
