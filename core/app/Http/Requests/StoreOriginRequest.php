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
            'health_check_path' => ['nullable', 'string', 'max:255', 'starts_with:/'],
            'host_header' => ['nullable', 'string', 'max:255'],
            'sni' => ['nullable', 'string', 'max:255'],
            'tls_verify' => ['nullable', Rule::in(['verify', 'ignore'])],
            'preserve_host' => ['nullable', 'boolean'],
            'load_balancing_algorithm' => ['nullable', Rule::in(['weighted_hash', 'consistent_hash'])],
            'health_check_interval_seconds' => ['nullable', 'integer', 'between:5,3600'],
            'health_check_timeout_seconds' => ['nullable', 'integer', 'between:1,60'],
            'connection_timeout_seconds' => ['nullable', 'integer', 'between:1,60'],
            'response_timeout_seconds' => ['nullable', 'integer', 'between:1,600'],
            'retry_attempts' => ['nullable', 'integer', 'between:0,3'],
            'retry_budget_per_minute' => ['nullable', 'integer', 'between:0,100000'],
            'circuit_breaker_enabled' => ['nullable', 'boolean'],
            'circuit_failure_threshold' => ['nullable', 'integer', 'between:1,1000'],
            'circuit_recovery_seconds' => ['nullable', 'integer', 'between:1,3600'],
            'max_concurrent_requests' => ['nullable', 'integer', 'between:0,1000000'],
            'drain' => ['nullable', 'boolean'],
            'shield_enabled' => ['nullable', 'boolean'],
        ];
    }
}
