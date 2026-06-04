<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AddGroupMemberRequest;
use App\Http\Requests\CreateGroupRequest;
use App\Http\Requests\GetGroupKeyRequest;
use App\Services\CsrfService;
use App\Services\DeviceAuthService;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GroupController extends ApiController
{
    public function __construct(
        CsrfService $csrf,
        DeviceAuthService $deviceAuth,
        private readonly GroupService $groups,
    ) {
        parent::__construct($csrf, $deviceAuth);
    }

    public function create(CreateGroupRequest $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->respond(
            $this->groups->create(
                $user,
                (string) $request->input('name'),
                (string) $request->input('encrypted_key'),
                $this->deviceAuth->isAdmin($user),
            ),
            200,
            true,
        );
    }

    public function addMember(AddGroupMemberRequest $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->respond(
            $this->groups->addMember(
                $request->integer('group_id'),
                trim((string) $request->input('user_id')),
                trim((string) $request->input('encrypted_key')),
                $this->deviceAuth->isAdmin($user),
            ),
            200,
            true,
        );
    }

    public function index(Request $request): JsonResponse
    {
        return $this->json(['groups' => $this->groups->getUserGroups($this->user($request))]);
    }

    public function getKey(GetGroupKeyRequest $request): JsonResponse
    {
        $groupId = (int) $request->validated('group_id');
        $user = $this->user($request);
        $key = $this->groups->getGroupKey($groupId, $user->id);

        if ($key === null) {
            throw new NotFoundHttpException('کلید گروه یافت نشد');
        }

        return $this->json(['encrypted_key' => $key]);
    }
}
