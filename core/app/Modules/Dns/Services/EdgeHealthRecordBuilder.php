<?php

namespace App\Modules\Dns\Services;

class EdgeHealthRecordBuilder
{
    /**
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
     * @param list<array{ip:string, country:?string, continent:?string}> $targets
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

            $branches[] = sprintf(
                "%s %s(%s) then return %s",
                $keyword,
                $route['type'],
                $this->luaString($route['code']),
                $this->luaAnswer($route['ips'])
            );

            $first = false;
        }

        $branches[] = 'else return ' . $this->luaString($fallbackIp) . ' end';

        return ';' . implode(' ', $branches);
    }

    /**
     * @param list<array{ip:string, country:?string, continent:?string}> $targets
     * @return list<array{type:string, code:string, ips:list<string>}>
     */
    private function groupTargetsByRoute(array $targets): array
    {
        $countries = [];
        $continents = [];

        foreach ($targets as $target) {
            $country = $target['country'];
            if ($country !== null) {
                $countries[$country][$target['ip']] = $target['ip'];
            }

            $continent = $target['continent'];
            if ($continent !== null) {
                $continents[$continent][$target['ip']] = $target['ip'];
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
     * @return list<array{ip:string, country:?string, continent:?string}>
     */
    private function validTargets(string $dnsType, array $targets): array
    {
        $flag = $dnsType === 'AAAA' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        $valid = [];
        $seen = [];

        foreach ($targets as $target) {
            if (is_array($target)) {
                $ip = trim((string) ($target['ip'] ?? ''));
                $country = $this->countryFromTarget($target);
                $continent = $this->continentFromTarget($target);
            } else {
                $ip = trim((string) $target);
                $country = null;
                $continent = null;
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
                'country' => $country,
                'continent' => $continent,
            ];
        }

        return $valid;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function countryFromTarget(array $target): ?string
    {
        $country = strtoupper(trim((string) ($target['country'] ?? '')));

        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            return $country;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function continentFromTarget(array $target): ?string
    {
        $continent = strtoupper(trim((string) ($target['continent'] ?? '')));

        if (in_array($continent, ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'], true)) {
            return $continent;
        }

        return null;
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

    /**
     * @param list<string> $ips
     */
    private function luaAnswer(array $ips): string
    {
        if (count($ips) === 1) {
            return $this->luaString($ips[0]);
        }

        return '{' . implode(',', array_map(fn(string $ip): string => $this->luaString($ip), $ips)) . '}';
    }

    private function escapePowerDnsContent(string $lua): string
    {
        return strtr($lua, [
            "\\" => "\\\\",
            '"' => '\\"',
        ]);
    }
}
