<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportChatRequest extends FormRequest
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
            'other_id' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8}$/'],
            'format' => ['nullable', 'string', 'in:json,txt'],
        ];
    }
}
