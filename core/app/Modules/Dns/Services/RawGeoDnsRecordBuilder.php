<?php

namespace App\Modules\Dns\Services;

class RawGeoDnsRecordBuilder
{
    /**
     * @param list<array<string, mixed>> $routes
     */
    public function luaRecord(string $dnsType, array $routes): ?string
    {
        $dnsType = strtoupper(trim($dnsType));
        if (!in_array($dnsType, ['A', 'AAAA'], true)) {
            return null;
        }

        $default = null;
        $countries = [];
        $continents = [];

        foreach ($routes as $route) {
            if (($route['enabled'] ?? true) !== true) {
                continue;
            }
            $answer = trim((string) ($route['answer_value'] ?? ''));
            if ($answer === '') {
                continue;
            }

            $scope = (string) ($route['route_scope'] ?? ($route['country_code'] === null ? 'default' : 'country'));
            if ($scope === 'default') {
                $default = $answer;
            } elseif ($scope === 'country') {
                $countries[(string) $route['country_code']] = $answer;
            } elseif ($scope === 'continent') {
                $continents[(string) $route['continent_code']] = $answer;
            }
        }

        if ($default === null) {
            return null;
        }

        $branches = [];
        $first = true;
        foreach ($countries as $code => $answer) {
            $branches[] = sprintf(
                "%s country(%s) then return %s",
                $first ? 'if' : 'elseif',
                $this->luaString($code),
                $this->luaString($answer)
            );
            $first = false;
        }
        foreach ($continents as $code => $answer) {
            $branches[] = sprintf(
                "%s continent(%s) then return %s",
                $first ? 'if' : 'elseif',
                $this->luaString($code),
                $this->luaString($answer)
            );
            $first = false;
        }

        $lua = $branches === []
            ? $this->luaString($default)
            : ';' . implode(' ', $branches) . ' else return ' . $this->luaString($default) . ' end';

        return $dnsType . ' "' . $this->escapePowerDnsContent($lua) . '"';
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
