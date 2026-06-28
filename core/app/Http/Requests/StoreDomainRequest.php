<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i'],
            'name' => ['nullable', 'string', 'max:160'],
            'user_id' => ['nullable', 'string', 'max:120'],
            'origin_shield_header_name' => ['nullable', 'string', 'max:255'],
            'origin_shield_secret' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
