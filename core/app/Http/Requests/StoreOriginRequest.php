<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOriginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheme' => ['nullable', Rule::in(['http', 'https'])],
            'host' => ['required', 'string', 'max:253'],
            'port' => ['nullable', 'integer', Rule::in([80, 443])],
            'role' => ['nullable', Rule::in(['primary', 'backup', 'shield'])],
            'weight' => ['nullable', 'integer', 'between:1,10000'],
            'enabled' => ['nullable', 'boolean'],
            'health_check_enabled' => ['nullable', 'boolean'],
            'health_check_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
