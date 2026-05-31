<?php

namespace App\Modules\Dns\Services;

class EdgeHealthRecordBuilder
{
    /**
     * @param list<string> $ips
     */
    public function luaRecord(string $dnsType, array $ips): ?string
    {
        $dnsType = strtoupper($dnsType);
        $ips = $this->validIps($dnsType, $ips);
        $mode = strtolower((string) (getenv('CDNLITE_EDGE_HEALTH_MODE') ?: 'ifportup'));
        if (!in_array($mode, ['ifportup', 'ifurlup', 'static'], true)) {
            $mode = 'ifportup';
        }

        $lua = match ($mode) {
            'static' => $this->staticLua($ips),
            'ifurlup' => $this->ifUrlUpLua($ips),
            default => $this->ifPortUpLua($ips),
        };

        return $dnsType . ' "' . str_replace('"', '\"', $lua) . '"';
    }

    /**
     * @param list<string> $ips
     */
    private function ifPortUpLua(array $ips): string
    {
        if ($ips === []) {
            return 'return {}';
        }
        return sprintf(
            'return ifportup(%d, %s, %s)',
            $this->envInt('CDNLITE_EDGE_HEALTH_PORT', 80),
            $this->luaList($ips),
            $this->luaOptions()
        );
    }

    /**
     * @param list<string> $ips
     */
    private function ifUrlUpLua(array $ips): string
    {
        if ($ips === []) {
            return 'return {}';
        }
        $port = $this->envInt('CDNLITE_EDGE_HEALTH_PORT', 80);
        $path = (string) (getenv('CDNLITE_EDGE_HEALTH_URL') ?: '/cdn-health');
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $urls = [];
        foreach ($ips as $ip) {
            $host = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $urls[] = 'http://' . $host . ':' . $port . $path;
        }

        return sprintf('return ifurlup(%s, %s)', $this->luaList($urls), $this->luaOptions());
    }

    /**
     * @param list<string> $ips
     */
    private function staticLua(array $ips): string
    {
        if ($ips === []) {
            return 'return {}';
        }
        if (count($ips) === 1) {
            return "return '" . $ips[0] . "'";
        }
        return 'return ' . $this->luaList($ips);
    }

    private function luaOptions(): string
    {
        return sprintf(
            "{selector='%s', backupSelector='%s', timeout=%d, interval=%d, minimumFailures=%d, failOnIncompleteCheck=false}",
            $this->selector('CDNLITE_EDGE_SELECTOR', 'pickclosest'),
            $this->selector('CDNLITE_EDGE_BACKUP_SELECTOR', 'empty'),
            $this->envInt('CDNLITE_EDGE_HEALTH_TIMEOUT', 1),
            $this->envInt('CDNLITE_EDGE_HEALTH_INTERVAL', 10),
            $this->envInt('CDNLITE_EDGE_HEALTH_MIN_FAILURES', 2)
        );
    }

    private function selector(string $key, string $default): string
    {
        $value = strtolower((string) (getenv($key) ?: $default));
        return in_array($value, ['pickclosest', 'hashed', 'random', 'all', 'empty'], true) ? $value : $default;
    }

    /**
     * @param list<string> $items
     */
    private function luaList(array $items): string
    {
        return '{' . implode(',', array_map(static fn(string $item): string => "'" . str_replace("'", "\\'", $item) . "'", $items)) . '}';
    }

    /**
     * @param list<string> $ips
     * @return list<string>
     */
    private function validIps(string $dnsType, array $ips): array
    {
        $flag = $dnsType === 'AAAA' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        $valid = [];
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, $flag) !== false) {
                $valid[$ip] = $ip;
            }
        }
        ksort($valid);
        return array_values($valid);
    }

    private function envInt(string $key, int $default): int
    {
        $value = getenv($key);
        return $value === false || !is_numeric($value) ? $default : max(0, (int) $value);
    }
}
