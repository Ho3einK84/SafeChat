<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitDeviceRequest extends FormRequest
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
            'device_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{8}$/'],
        ];
    }
}
