<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\InitDeviceRequest;
use Illuminate\Http\JsonResponse;

class AuthController extends ApiController
{
    public function init(InitDeviceRequest $request): JsonResponse
    {
        $storedId = trim((string) $request->input('device_id', ''));

        if ($storedId !== '' && ($user = $this->deviceAuth->claim($storedId))) {
            // Session regeneration is handled inside claim() — do not call again here
            return $this->json([
                'id' => $user->device_id,
                'claimed' => true,
                'is_admin' => $this->deviceAuth->isAdmin($user),
            ]);
        }

        $user = $this->deviceAuth->getOrCreate();

        return $this->json([
            'id' => $user->device_id,
            'claimed' => false,
            'is_admin' => $this->deviceAuth->isAdmin($user),
        ]);
    }

    public function myId(): JsonResponse
    {
        $user = $this->deviceAuth->current();

        return $this->json([
            'id' => $user->device_id,
            'is_admin' => $this->deviceAuth->isAdmin($user),
        ]);
    }
}
