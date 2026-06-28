<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Domain extends Model
{
    use HasFactory;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'name',
        'domain',
        'status',
        'nameserver_status',
        'verification_token',
        'powerdns_zone_created',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'powerdns_zone_created' => 'boolean',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function origins(): HasMany
    {
        return $this->hasMany(DomainOrigin::class);
    }

    public function dnsRecords(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }
}
