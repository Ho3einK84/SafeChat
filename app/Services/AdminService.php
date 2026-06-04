<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class AdminService
{
    /**
     * @return array<string, mixed>
     */
    public function resetDatabase(): array
    {
        Log::channel('safechat')->warning('Database reset initiated');

        $newDeviceId = $this->generateDeviceId();

        DB::transaction(function () use ($newDeviceId): void {
            Schema::disableForeignKeyConstraints();

            DB::table('message_seen')->truncate();
            DB::table('messages')->truncate();
            DB::table('group_members')->truncate();
            DB::table('groups')->truncate();
            DB::table('blocked_users')->truncate();
            DB::table('users')->truncate();

            Schema::enableForeignKeyConstraints();

            DB::table('users')->insert([
                'device_id' => $newDeviceId,
                'created_at' => now(),
                'last_seen' => now(),
            ]);
        });

        if (! session()->isStarted()) {
            session()->start();
        }
        session()->regenerate(true);
        session([
            'device_id' => $newDeviceId,
        ]);

        return ['success' => true, 'new_device_id' => $newDeviceId];
    }

    private function generateDeviceId(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $id = '';

        for ($i = 0; $i < 8; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $id;
    }
}
