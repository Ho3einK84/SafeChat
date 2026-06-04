<?php

namespace App\Services;

use App\Models\User;
use JsonException;

final class ExportService
{
    public function __construct(
        private readonly MessageService $messages,
    ) {}

    /**
     * @return array{format: string, content: string, filename: string}
     */
    public function exportConversation(User $user, string $otherDeviceId, string $format = 'json'): array
    {
        $messages = $this->messages->getPrivateMessages($user, $otherDeviceId, 0);

        if ($format === 'txt') {
            $output = "SafeChat Export - {$user->device_id} ↔ {$otherDeviceId}\n";
            $output .= 'Date: '.date('Y-m-d H:i:s')."\n".str_repeat('=', 50)."\n\n";

            foreach ($messages as $msg) {
                $sender = $msg['sender_id'] === $user->device_id ? 'Me' : $msg['sender_id'];
                $content = $msg['content'] ?? '';
                // E2E-encrypted blobs are base64 and typically very long
                if (is_string($content) && strlen($content) > 100 && base64_decode($content, true) !== false) {
                    $content = '[E2E encrypted — decrypt in SafeChat app]';
                }
                $output .= "[{$msg['created_at']}] {$sender}: {$content}\n";
            }

            return [
                'format' => 'txt',
                'content' => $output,
                'filename' => "safechat_{$otherDeviceId}_".date('Ymd').'.txt',
            ];
        }

        $export = [
            'export_date' => date('c'),
            'version' => config('safechat.version', '0.1.0'),
            'user' => $user->device_id,
            'other' => $otherDeviceId,
            'messages' => [],
        ];

        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            $encrypted = is_string($content) && strlen($content) > 100 && base64_decode($content, true) !== false;

            $export['messages'][] = [
                'id' => $msg['id'],
                'sender_id' => $msg['sender_id'],
                'content' => $encrypted ? '[E2E encrypted — decrypt in SafeChat app]' : $content,
                'encrypted' => $encrypted,
                'created_at' => $msg['created_at'],
                'has_password' => (bool) ($msg['has_password'] ?? false),
            ];
        }

        try {
            $content = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $content = '{"error":"export_failed"}';
        }

        return [
            'format' => 'json',
            'content' => $content,
            'filename' => "safechat_{$otherDeviceId}_".date('Ymd').'.json',
        ];
    }
}
