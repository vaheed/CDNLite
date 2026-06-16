<?php

namespace App\Modules\Dns\Services;

class EdgeHealthRecordBuilder
{
    /**
     * Build simple PowerDNS LUA geo record.
     *
     * No ifportup.
     * No pickclosest.
     *
     * Fallback is always the first healthy edge IP.
     *
     * @param list<string|array<string, mixed>> $targets
     */
    public function luaRecord(string $dnsType, array $targets): ?string
    {
        $dnsType = strtoupper(trim($dnsType));

        if (!in_array($dnsType, ['A', 'AAAA'], true)) {
            return null;
        }

        $targets = $this->validTargets($dnsType, $targets);

        if ($targets === []) {
            return null;
        }

        $lua = $this->geoLua($targets);

        return $dnsType . ' "' . $this->escapePowerDnsContent($lua) . '"';
    }

    /**
     * @param list<array{ip:string, route_type:?string, route_code:?string}> $targets
     */
    private function geoLua(array $targets): string
    {
        $fallbackIp = $targets[0]['ip'];
        $routes = $this->groupTargetsByRoute($targets);

        if ($routes === []) {
            return $this->luaString($fallbackIp);
        }

        $branches = [];
        $first = true;

        foreach ($routes as $route) {
            $keyword = $first ? 'if' : 'elseif';
            $function = $route['type'] === 'continent' ? 'continent' : 'country';

            /*
             * If multiple edges have the same country/continent,
             * return the first one in that group.
             */
            $ip = $route['ips'][0];

            $branches[] = sprintf(
                "%s %s(%s) then return %s",
                $keyword,
                $function,
                $this->luaString($route['code']),
                $this->luaString($ip)
            );

            $first = false;
        }

        $branches[] = 'else return ' . $this->luaString($fallbackIp) . ' end';

        return ';' . implode(' ', $branches);
    }

    /**
     * Countries are checked before continents.
     *
     * @param list<array{ip:string, route_type:?string, route_code:?string}> $targets
     * @return list<array{type:string, code:string, ips:list<string>}>
     */
    private function groupTargetsByRoute(array $targets): array
    {
        $countries = [];
        $continents = [];

        foreach ($targets as $target) {
            $routeType = $target['route_type'];
            $routeCode = $target['route_code'];

            if ($routeType === null || $routeCode === null) {
                continue;
            }

            if ($routeType === 'country') {
                $countries[$routeCode][$target['ip']] = $target['ip'];
                continue;
            }

            if ($routeType === 'continent') {
                $continents[$routeCode][$target['ip']] = $target['ip'];
            }
        }

        $routes = [];

        foreach ($countries as $code => $ips) {
            $routes[] = [
                'type' => 'country',
                'code' => $code,
                'ips' => array_values($ips),
            ];
        }

        foreach ($continents as $code => $ips) {
            $routes[] = [
                'type' => 'continent',
                'code' => $code,
                'ips' => array_values($ips),
            ];
        }

        return $routes;
    }

    /**
     * @param list<string|array<string, mixed>> $targets
     * @return list<array{ip:string, route_type:?string, route_code:?string}>
     */
    private function validTargets(string $dnsType, array $targets): array
    {
        $flag = $dnsType === 'AAAA' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        $valid = [];
        $seen = [];

        foreach ($targets as $target) {
            if (is_array($target)) {
                $ip = trim((string) ($target['ip'] ?? ''));
                $route = $this->routeFromTarget($target);
            } else {
                $ip = trim((string) $target);
                $route = [null, null];
            }

            if ($ip === '') {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, $flag) === false) {
                continue;
            }

            if (isset($seen[$ip])) {
                continue;
            }

            $seen[$ip] = true;

            $valid[] = [
                'ip' => $ip,
                'route_type' => $route[0],
                'route_code' => $route[1],
            ];
        }

        return $valid;
    }

    /**
     * Priority:
     *
     * 1. country column: IR, US, DE, ...
     * 2. continent column: EU, AS, NA, ...
     * 3. region string: iran, us, eu, europe, ...
     *
     * @param array<string, mixed> $target
     * @return array{0:?string, 1:?string}
     */
    private function routeFromTarget(array $target): array
    {
        $country = strtoupper(trim((string) ($target['country'] ?? '')));

        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            return ['country', $country];
        }

        $continent = strtoupper(trim((string) ($target['continent'] ?? '')));

        if ($this->isContinentCode($continent)) {
            return ['continent', $continent];
        }

        $region = strtoupper(trim((string) ($target['region'] ?? '')));
        $region = str_replace(['-', '_', ' '], '', $region);

        $aliases = [
            'IRAN' => 'IR',
            'PERSIA' => 'IR',

            'USA' => 'US',
            'UNITEDSTATES' => 'US',
            'UNITEDSTATESOFAMERICA' => 'US',
            'AMERICA' => 'US',

            'EUROPE' => 'EU',
            'EUROPEANUNION' => 'EU',

            'ASIA' => 'AS',
            'NORTHAMERICA' => 'NA',
            'SOUTHAMERICA' => 'SA',
            'AFRICA' => 'AF',
            'OCEANIA' => 'OC',
            'AUSTRALIA' => 'OC',
            'ANTARCTICA' => 'AN',
        ];

        $code = $aliases[$region] ?? $region;

        if ($this->isContinentCode($code)) {
            return ['continent', $code];
        }

        if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
            return ['country', $code];
        }

        return [null, null];
    }

    private function isContinentCode(string $code): bool
    {
        return in_array($code, ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'], true);
    }

    private function luaString(string $value): string
    {
        return "'" . strtr($value, [
            "\\" => "\\\\",
            "'" => "\\'",
            "\n" => "\\n",
            "\r" => "\\r",
            "\t" => "\\t",
        ]) . "'";
    }

    private function escapePowerDnsContent(string $lua): string
    {
        return strtr($lua, [
            "\\" => "\\\\",
            '"' => '\\"',
        ]);
    }
}