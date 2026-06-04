<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\BlockUserRequest;
use App\Http\Requests\CheckUserRequest;
use App\Http\Requests\GetBlockStatusRequest;
use App\Http\Requests\GetProfileRequest;
use App\Http\Requests\GetPubkeyRequest;
use App\Http\Requests\StorePubkeyRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Services\CsrfService;
use App\Services\DeviceAuthService;
use App\Services\EncryptionService;
use App\Services\MessageService;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserController extends ApiController
{
    public function __construct(
        CsrfService $csrf,
        DeviceAuthService $deviceAuth,
        private readonly EncryptionService $encryption,
        private readonly MessageService $messages,
        private readonly UserProfileService $profiles,
    ) {
        parent::__construct($csrf, $deviceAuth);
    }

    public function storePubkey(StorePubkeyRequest $request): JsonResponse
    {
        $publicKey = trim((string) $request->input('public_key', ''));

        try {
            if (! $this->encryption->validateRsaPublicKey($publicKey)) {
                Log::channel('safechat')->warning('Invalid public key rejected', [
                    'user_id' => $this->user($request)->id,
                ]);

                return $this->respond(['error' => 'Invalid RSA public key format']);
            }

            $this->user($request)->update(['public_key' => $publicKey]);

            return $this->json(['success' => true], 200, true);
        } catch (\Throwable $e) {
            Log::channel('safechat')->error('Failed to store public key', [
                'user_id' => $this->user($request)->id,
                'exception' => $e::class,
            ]);

            return $this->respond(['error' => 'خطا در ذخیره کلید'], 500, true);
        }
    }

    public function getPubkey(GetPubkeyRequest $request): JsonResponse
    {
        $targetId = (string) $request->validated('id');

        $key = User::query()->where('device_id', $targetId)->value('public_key');

        return $this->json(['public_key' => $key ?: null]);
    }

    public function getProfile(GetProfileRequest $request): JsonResponse
    {
        $me = $this->user($request);
        $targetId = trim((string) ($request->validated('id') ?? $me->device_id));

        return $this->json($this->profiles->getProfile($targetId));
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $displayName = $request->has('display_name')
            ? trim((string) $request->input('display_name'))
            : null;

        return $this->respond(
            $this->profiles->updateProfile(
                $this->user($request),
                $displayName === '' ? null : $displayName,
            ),
            200,
            true,
        );
    }

    public function checkUser(CheckUserRequest $request): JsonResponse
    {
        $targetId = trim((string) $request->validated('id'));

        $me = $this->user($request);
        $exists = User::query()->where('device_id', $targetId)->exists();
        $block = $exists
            ? $this->messages->getBlockStatus($me->device_id, $targetId)
            : ['i_blocked' => false, 'blocked_me' => false, 'is_blocked' => false];

        return $this->json(array_merge([
            'exists' => $exists,
            'is_self' => $targetId === $me->device_id,
        ], $block));
    }

    public function getBlockStatus(GetBlockStatusRequest $request): JsonResponse
    {
        $otherId = (string) $request->validated('other');

        return $this->json($this->messages->getBlockStatus($this->user($request)->device_id, $otherId));
    }

    public function getBlockedUsers(Request $request): JsonResponse
    {
        return $this->json([
            'blocked' => $this->messages->getBlockedUsers($this->user($request)->device_id),
        ]);
    }

    public function blockUser(BlockUserRequest $request): JsonResponse
    {
        return $this->respond(
            $this->messages->blockUser($this->user($request), trim((string) $request->input('blocked_id'))),
            200,
            true,
        );
    }

    public function unblockUser(BlockUserRequest $request): JsonResponse
    {
        return $this->respond(
            $this->messages->unblockUser($this->user($request), trim((string) $request->input('blocked_id'))),
            200,
            true,
        );
    }

    public function getConversations(Request $request): JsonResponse
    {
        return $this->json([
            'conversations' => $this->messages->getConversations($this->user($request)),
        ]);
    }

    public function getOnlineUsers(): JsonResponse
    {
        return $this->json(['users' => $this->profiles->getOnlineUsers()]);
    }
}
