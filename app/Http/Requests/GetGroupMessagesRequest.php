<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetGroupMessagesRequest extends FormRequest
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
        return [
            'group_id' => ['required', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

