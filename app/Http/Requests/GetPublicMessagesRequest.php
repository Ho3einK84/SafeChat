<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetPublicMessagesRequest extends FormRequest
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
            'after' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

