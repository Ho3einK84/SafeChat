<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnlockMessageRequest extends FormRequest
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
            'msg_id' => ['required', 'integer', 'min:1'],
            'password' => ['required', 'string', 'max:50'],
        ];
    }
}
