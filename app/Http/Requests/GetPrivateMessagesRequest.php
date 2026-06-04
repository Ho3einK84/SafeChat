<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetPrivateMessagesRequest extends FormRequest
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
            'other' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8}$/'],
            'after' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

