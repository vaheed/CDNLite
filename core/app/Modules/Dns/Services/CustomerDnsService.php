<?php

namespace App\Modules\Dns\Services;

class CustomerDnsService
{
    public function __construct(private DnsPublishingPlanner $planner = new DnsPublishingPlanner())
    {
    }

    public function publicRecordFor(array $domain, array $record): array
    {
        return $this->planner->plan($domain, $record);
    }

    public function isApex(string $name, string $domainDomain): bool
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim(trim($domainDomain), '.'));
        return $name === '' || $name === '@' || $name === $domain;
    }
}
