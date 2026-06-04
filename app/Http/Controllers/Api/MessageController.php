<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EditMessageRequest;
use App\Http\Requests\GetGroupMessagesRequest;
use App\Http\Requests\GetPrivateMessagesRequest;
use App\Http\Requests\GetPublicMessagesRequest;
use App\Http\Requests\DeleteMessageRequest;
use App\Http\Requests\MarkSeenRequest;
use App\Http\Requests\SearchMessagesRequest;
use App\Http\Requests\ExportChatRequest;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\UnlockMessageRequest;
use App\Models\User;
use App\Services\CsrfService;
use App\Services\DeviceAuthService;
use App\Services\ExportService;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MessageController extends ApiController
{
    public function __construct(
        CsrfService $csrf,
        DeviceAuthService $deviceAuth,
        private readonly MessageService $messages,
        private readonly ExportService $export,
    ) {
        parent::__construct($csrf, $deviceAuth);
    }

    public function sendPublic(SendMessageRequest $request): JsonResponse
    {
        return $this->respond(
            $this->messages->send(
                $this->user($request),
                (string) $request->input('content'),
                null,
                $request->input('password'),
                $request->integer('reply_to') ?: null,
            ),
            200,
            true,
        );
    }

    public function sendPrivate(SendMessageRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $recipient = trim((string) $request->input('recipient_id', ''));

        if (! preg_match('/^[a-zA-Z0-9]{8}$/', $recipient)) {
            return $this->respond(['error' => 'شناسه کاربری نامعتبر']);
        }

        if (! User::query()->where('device_id', $recipient)->exists()) {
            return $this->respond(['error' => 'کاربر یافت نشد']);
        }

        return $this->respond(
            $this->messages->send(
                $user,
                (string) $request->input('content'),
                $recipient,
                $request->input('password'),
                $request->integer('reply_to') ?: null,
            ),
            200,
            true,
        );
    }

    public function sendGroup(SendMessageRequest $request): JsonResponse
    {
        $groupId = $request->integer('group_id');

        if ($groupId <= 0) {
            return $this->respond(['error' => 'شناسه گروه نامعتبر']);
        }

        return $this->respond(
            $this->messages->send(
                $this->user($request),
                (string) $request->input('content'),
                null,
                $request->input('password'),
                $request->integer('reply_to') ?: null,
                $groupId,
            ),
            200,
            true,
        );
    }

    public function getPublic(GetPublicMessagesRequest $request): JsonResponse
    {
        return $this->json([
            'messages' => $this->messages->getPublicMessages(
                $this->user($request),
                (int) ($request->validated('after') ?? 0),
            ),
        ]);
    }

    public function getPrivate(GetPrivateMessagesRequest $request): JsonResponse
    {
        $otherId = (string) $request->validated('other');

        return $this->json([
            'messages' => $this->messages->getPrivateMessages(
                $this->user($request),
                $otherId,
                (int) ($request->validated('after') ?? 0),
            ),
        ]);
    }

    public function getGroup(GetGroupMessagesRequest $request): JsonResponse
    {
        $groupId = (int) $request->validated('group_id');

        return $this->json([
            'messages' => $this->messages->getGroupMessages(
                $groupId,
                $this->user($request),
                (int) ($request->validated('after') ?? 0),
            ),
        ]);
    }

    public function edit(EditMessageRequest $request): JsonResponse
    {
        return $this->respond(
            $this->messages->edit(
                $request->integer('msg_id'),
                $this->user($request),
                (string) $request->input('content'),
            ),
            200,
            true,
        );
    }

    public function delete(DeleteMessageRequest $request): JsonResponse
    {
        $msgId = (int) $request->validated('msg_id');

        $user = $this->user($request);

        return $this->respond(
            $this->messages->delete($msgId, $user, $this->deviceAuth->isAdmin($user)),
            200,
            true,
        );
    }

    public function unlock(UnlockMessageRequest $request): JsonResponse
    {
        return $this->respond(
            $this->messages->unlock($request->integer('msg_id'), $this->user($request), (string) $request->input('password')),
            200,
            true,
        );
    }

    public function markSeen(MarkSeenRequest $request): JsonResponse
    {
        $msgId = (int) $request->validated('msg_id');

        $this->messages->markSeen($msgId, $this->user($request));

        return $this->json(['success' => true], 200, true);
    }

    public function search(SearchMessagesRequest $request): JsonResponse
    {
        $query = trim((string) ($request->validated('q') ?? ''));

        return $this->json([
            'messages' => $query !== '' ? $this->messages->searchMessages($query, $this->user($request)) : [],
        ]);
    }

    public function export(ExportChatRequest $request): Response
    {
        $otherId = trim((string) $request->validated('other_id'));
        $format = trim((string) ($request->validated('format') ?? 'json'));
        $result = $this->export->exportConversation($this->user($request), $otherId, $format);

        return response($result['content'], 200, [
            'Content-Type' => $format === 'txt' ? 'text/plain; charset=utf-8' : 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}
