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
            'origin_host' => ['nullable', 'string', 'max:253'],
            'geo_routes' => ['nullable', 'array'],
            'geo_routes.*.route_scope' => ['required_with:geo_routes', Rule::in(['default', 'country', 'continent'])],
            'geo_routes.*.country_code' => ['nullable', 'string', 'size:2'],
            'geo_routes.*.continent_code' => ['nullable', Rule::in(['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'])],
            'geo_routes.*.answer_type' => ['required_with:geo_routes', Rule::in(['A', 'AAAA'])],
            'geo_routes.*.answer_value' => ['required_with:geo_routes', 'string', 'max:255'],
            'geo_routes.*.enabled' => ['nullable', 'boolean'],
        ];
    }
}
