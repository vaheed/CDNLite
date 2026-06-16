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
     * @param list<array{ip:string, country:?string}> $targets
     */
    private function geoLua(array $targets): string
    {
        $fallbackIp = $targets[0]['ip'];
        $routes = $this->groupTargetsByCountry($targets);

        if ($routes === []) {
            return $this->luaString($fallbackIp);
        }

        $branches = [];
        $first = true;

        foreach ($routes as $route) {
            $keyword = $first ? 'if' : 'elseif';

            $branches[] = sprintf(
                "%s country(%s) then return %s",
                $keyword,
                $this->luaString($route['code']),
                $this->luaAnswer($route['ips'])
            );

            $first = false;
        }

        $branches[] = 'else return ' . $this->luaString($fallbackIp) . ' end';

        return ';' . implode(' ', $branches);
    }

    /**
     * @param list<array{ip:string, country:?string}> $targets
     * @return list<array{code:string, ips:list<string>}>
     */
    private function groupTargetsByCountry(array $targets): array
    {
        $countries = [];

        foreach ($targets as $target) {
            $country = $target['country'];
            if ($country === null) {
                continue;
            }

            $countries[$country][$target['ip']] = $target['ip'];
        }

        $routes = [];

        foreach ($countries as $code => $ips) {
            $routes[] = [
                'code' => $code,
                'ips' => array_values($ips),
            ];
        }

        return $routes;
    }

    /**
     * @param list<string|array<string, mixed>> $targets
     * @return list<array{ip:string, country:?string}>
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
            } else {
                $ip = trim((string) $target);
                $country = null;
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
