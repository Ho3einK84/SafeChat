<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePubkeyRequest extends FormRequest
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
            'public_key' => ['required', 'string', 'max:8192'],
        ];
    }
}
