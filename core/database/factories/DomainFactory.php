<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Services\ControlPlane\UnixTime;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        $domain = $this->faker->unique()->domainName();
        $now = UnixTime::now();

        return [
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'name' => $domain,
            'domain' => $domain,
            'status' => 'pending_nameserver',
            'nameserver_status' => 'unknown',
            'verification_token' => bin2hex(random_bytes(16)),
            'powerdns_zone_created' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
