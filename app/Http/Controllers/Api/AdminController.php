<?php

namespace App\Http\Controllers\Api;

use App\Services\AdminService;
use App\Services\CsrfService;
use App\Services\DeviceAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends ApiController
{
    public function __construct(
        CsrfService $csrf,
        DeviceAuthService $deviceAuth,
        private readonly AdminService $admin,
    ) {
        parent::__construct($csrf, $deviceAuth);
    }

    public function resetDatabase(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (config('app.env') === 'production' && ! (bool) config('safechat.allow_db_reset', false)) {
            return $this->respond(['error' => 'این عملیات در محیط production غیرفعال است'], 403, true);
        }

        if (! $this->deviceAuth->isAdmin($user)) {
            return $this->respond(['error' => 'دسترسی غیرمجاز'], 403, true);
        }

        return $this->respond($this->admin->resetDatabase(), 200, true);
    }
}
