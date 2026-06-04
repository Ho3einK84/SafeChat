<?php

namespace App\Services;

use App\Exceptions\UnauthenticatedDeviceException;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DeviceAuthService
{
    private const DEVICE_ID_LENGTH = 8;

    public function __construct(
        private readonly CsrfService $csrf,
    ) {}

    /**
     * Get user from session or create a new device identity.
     */
    public function getOrCreate(): User
    {
        $this->bootSession();

        if ($deviceId = session('device_id')) {
            $user = User::query()->where('device_id', $deviceId)->first();

            if ($user) {
                session(['user_id' => $user->id]);

                return $user;
            }
        }

        return $this->createUser();
    }

    /**
     * Restore session from an existing device ID (claim flow).
     * Regenerates session ID to prevent session fixation.
     */
    public function claim(string $id): ?User
    {
        if (! preg_match('/^[a-zA-Z0-9]{8}$/', $id)) {
            return null;
        }

        $user = User::query()->where('device_id', $id)->first();

        if (! $user) {
            return null;
        }

        $this->bootSession();

        // Regenerate session ID to prevent session fixation after privilege change
        session()->regenerate(true);

        session([
            'device_id' => $user->device_id,
            'user_id' => $user->id,
            '_created_at' => time(),
            '_regenerated_at' => time(),
        ]);
        $user->touchLastSeen();

        return $user;
    }

    /**
     * Current authenticated user or exception.
     */
    public function current(): User
    {
        $this->bootSession();

        if ($userId = session('user_id')) {
            $user = User::query()->find($userId);
            if ($user) {
                return $user;
            }
        }

        if ($deviceId = session('device_id')) {
            $user = User::query()->where('device_id', $deviceId)->first();
            if ($user) {
                session(['user_id' => $user->id]);

                return $user;
            }
        }

        throw new UnauthenticatedDeviceException('User not authenticated');
    }

    public function isAdmin(User $user): bool
    {
        $admins = config('safechat.admin_device_ids', []);

        if (in_array($user->device_id, $admins, true)) {
            return true;
        }

        if ($admins === [] && config('app.env') !== 'production') {
            return $user->id === 1;
        }

        return false;
    }

    public function csrfToken(): string
    {
        return $this->csrf->token();
    }

    private function bootSession(): void
    {
        if (! session()->isStarted()) {
            session()->start();
        }

        $lifetime = (int) config('safechat.session_lifetime', 86400);

        // Expire session if it has been alive too long
        if ($created = session('_created_at')) {
            if (time() - (int) $created > $lifetime) {
                session()->flush();
                session()->regenerate(true);
                session(['_created_at' => time(), '_regenerated_at' => time()]);

                return;
            }
        } else {
            session(['_created_at' => time()]);
        }

        // Periodically rotate session ID (every 30 minutes) to limit session hijacking window
        if (session()->has('_regenerated_at')) {
            if (time() - (int) session('_regenerated_at') > 1800) {
                session()->regenerate(true);
                session(['_regenerated_at' => time()]);
            }
        } else {
            session(['_regenerated_at' => time()]);
        }
    }

    private function createUser(): User
    {
        $this->bootSession();

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $deviceId = $this->generateDeviceId();

            try {
                $user = DB::transaction(function () use ($deviceId) {
                    return User::query()->create([
                        'device_id' => $deviceId,
                        'created_at' => now(),
                        'last_seen' => now(),
                    ]);
                });

                session([
                    'device_id' => $user->device_id,
                    'user_id' => $user->id,
                ]);

                return $user;
            } catch (QueryException) {
                continue;
            }
        }

        throw new RuntimeException('Could not allocate device ID');
    }

    private function generateDeviceId(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $id = '';

        for ($i = 0; $i < self::DEVICE_ID_LENGTH; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $id;
    }
}
