<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('safechat.max_msg_length', 2000);

        return [
            'content' => ['required', 'string', 'max:'.$max],
            'password' => ['nullable', 'string', 'max:50'],
            'reply_to' => ['nullable', 'integer', 'min:1'],
            'recipient_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{8}$/'],
            'group_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
