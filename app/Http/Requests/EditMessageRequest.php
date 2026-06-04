<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditMessageRequest extends FormRequest
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
            'msg_id' => ['required', 'integer', 'min:1'],
            'content' => ['required', 'string', 'max:'.$max],
        ];
    }
}
