<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDnsRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA', 'NS', 'SRV'])],
            'name' => ['required', 'string', 'max:253'],
            'content' => ['required', 'string', 'max:2048'],
            'ttl' => ['nullable', 'integer', 'between:60,86400'],
            'priority' => ['nullable', 'integer', 'between:0,65535'],
            'proxied' => ['nullable', 'boolean'],
        ];
    }
}
