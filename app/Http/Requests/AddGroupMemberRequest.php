<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddGroupMemberRequest extends FormRequest
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
            'user_id' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8}$/'],
            'encrypted_key' => ['required', 'string'],
        ];
    }
}
