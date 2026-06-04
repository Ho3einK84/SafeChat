<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UnauthenticatedDeviceException;
use App\Http\Controllers\Controller;
use App\Services\CsrfService;
use App\Services\DeviceAuthService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    public function __construct(
        protected readonly CsrfService $csrf,
        protected readonly DeviceAuthService $deviceAuth,
    ) {}

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user instanceof User) {
            throw new UnauthenticatedDeviceException('User not authenticated');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data, int $status = 200, bool $mutating = false): JsonResponse
    {
        $token = $this->csrf->token();
        $data['csrf'] = $token;

        $response = response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($mutating) {
            $response->header('X-CSRF-Token', $token);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function respond(array $payload, int $status = 200, bool $mutating = false): JsonResponse
    {
        if (isset($payload['error'])) {
            $status = $status >= 400 ? $status : 400;
        }

        return $this->json($payload, $status, $mutating);
    }
}
