<?php

namespace App\Http\Middleware;

use App\Services\CsrfService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitSafeChat
{
    public function __construct(
        private readonly CsrfService $csrf,
    ) {}

    public function handle(Request $request, Closure $next, string $bucket): Response
    {
        $ip = (string) ($request->ip() ?? '');
        $deviceId = '';
        try {
            $deviceId = (string) $request->session()->get('device_id', '');
        } catch (\Throwable) {
            $deviceId = '';
        }

        $keyParts = [
            'safechat',
            $bucket,
            $ip !== '' ? $ip : 'no-ip',
            $deviceId !== '' ? $deviceId : 'no-device',
        ];

        $key = implode(':', $keyParts);

        $max = match ($bucket) {
            'unlock' => (int) config('safechat.rate_limit_unlock', 10),
            'init' => (int) config('safechat.rate_limit_init', 20),
            'export' => (int) config('safechat.rate_limit_export', 6),
            'admin' => (int) config('safechat.rate_limit_admin', 2),
            'mutate' => (int) config('safechat.rate_limit_mutate', 60),
            default => (int) config('safechat.rate_limit_send', 30),
        };
        $window = (int) config('safechat.rate_limit_window', 60);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            Log::channel('safechat')->warning('Session rate limit exceeded', [
                'bucket' => $bucket,
                'ip' => $request->ip(),
                'device_id' => $deviceId !== '' ? $deviceId : null,
            ]);

            $message = match ($bucket) {
                'unlock' => 'تعداد تلاش‌ها بیش از حد مجاز است. کمی صبر کنید.',
                'init' => 'تعداد درخواست‌ها بیش از حد است. کمی صبر کنید.',
                default => 'سرعت درخواست‌ها بیش از حد است. لطفاً کمی صبر کنید.',
            };

            return response()->json([
                'error' => $message,
                'csrf' => $this->csrf->token(),
            ], 429, [], JSON_UNESCAPED_UNICODE);
        }

        RateLimiter::hit($key, $window);

        return $next($request);
    }
}
