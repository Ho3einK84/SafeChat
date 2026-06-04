<?php

namespace App\Http\Controllers;

use App\Services\CsrfService;
use App\Services\DeviceAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(
        private readonly DeviceAuthService $deviceAuth,
        private readonly CsrfService $csrf,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect('/chat');
    }

    public function chat(Request $request): View
    {
        $user = $request->user();

        return view('app', [
            'deviceId' => $user->device_id,
            'csrfToken' => $this->csrf->token(),
            'isAdmin' => $this->deviceAuth->isAdmin($user),
            'version' => config('safechat.version', '0.1.0'),
        ]);
    }

    public function install(): View
    {
        $this->enforceInstallAccess(request());

        return view('install', [
            'version' => config('safechat.version', '0.1.0'),
            'requirements' => $this->checkRequirements(),
        ]);
    }

    public function runInstall(Request $request): View
    {
        $this->enforceInstallAccess($request);

        $messages = [];
        $success = false;
        $errorMsg = null;

        try {
            Artisan::call('migrate', ['--force' => true]);
            $messages[] = '✅ جداول دیتابیس با migration ایجاد شدند.';
            DB::connection()->getPdo();
            $messages[] = '✅ اتصال به دیتابیس برقرار است.';
            $this->writeInstallMarker();
            $success = true;
        } catch (\Throwable $e) {
            Log::channel('safechat')->error('Install failed', ['exception' => $e::class]);
            $errorMsg = 'خطا در نصب. لاگ سرور را بررسی کنید.';
        }

        return view('install', [
            'version' => config('safechat.version', '0.1.0'),
            'requirements' => $this->checkRequirements(),
            'runResult' => compact('messages', 'success', 'errorMsg'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function checkRequirements(): array
    {
        $errors = [];

        if (! extension_loaded('pdo_mysql')) {
            $errors[] = 'PDO MySQL driver not found';
        }
        if (! function_exists('openssl_encrypt')) {
            $errors[] = 'OpenSSL extension not found';
        }
        if (! function_exists('mb_strlen')) {
            $errors[] = 'mbstring extension not found';
        }
        if (! function_exists('sodium_base642bin')) {
            $errors[] = 'libsodium extension not found';
        }
        if (config('safechat.encryption_key') === null || config('safechat.encryption_key') === '') {
            $errors[] = 'ENCRYPTION_KEY is not set in .env';
        }

        return $errors;
    }

    private function enforceInstallAccess(Request $request): void
    {
        $marker = (string) config('safechat.install.marker', storage_path('app/safechat.installed'));
        if ($marker !== '' && is_file($marker)) {
            abort(404);
        }

        $enabled = (bool) config('safechat.install.enabled', false);
        $allowedByEnv = config('app.env') !== 'production' && (bool) config('app.debug', false);

        if (! $enabled && ! $allowedByEnv) {
            abort(404);
        }

        $token = (string) config('safechat.install.token', '');
        if ($token !== '') {
            $provided = (string) $request->input('token', $request->query('token', ''));
            if (! hash_equals($token, $provided)) {
                abort(403);
            }
        }
    }

    private function writeInstallMarker(): void
    {
        $marker = (string) config('safechat.install.marker', storage_path('app/safechat.installed'));
        if ($marker === '' || is_file($marker)) {
            return;
        }

        $dir = dirname($marker);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($marker, (string) now());
    }
}
