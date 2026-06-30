<?php

namespace App\Modules\Recommendations\Services;

use App\Modules\Proxy\Services\OriginHealthService;
use App\Services\ControlPlane\TrafficRulesService;
use App\Support\AuditLog;
use App\Support\Database;
use App\Support\Uuid;

class RecommendationService
{
    private const DISMISS_SUPPRESSION_SECONDS = 604800;

    public function generate(?string $domainId = null, ?int $now = null): array
    {
        $now ??= time();
        $generated = [];
        foreach ($this->domains($domainId) as $domain) {
            foreach ($this->candidatesForDomain((string) $domain['id'], $now) as $candidate) {
                $row = $this->upsertCandidate((string) $domain['id'], $candidate, $now);
                if ($row !== null) {
                    $generated[] = $row;
                }
            }
        }
        return ['generated' => $generated, 'count' => count($generated)];
    }

    public function list(?string $domainId = null, bool $includeInactive = false, ?int $now = null): array
    {
        $now ??= time();
        $sql = 'SELECT r.*, d.domain AS domain_name FROM recommendations r JOIN domains d ON d.id=r.domain_id WHERE 1=1';
        $params = [];
        if ($domainId !== null && $domainId !== '') {
            $sql .= ' AND r.domain_id=:domain_id';
            $params[':domain_id'] = $domainId;
        }
        if (!$includeInactive) {
            $sql .= " AND r.status='open' AND (r.snoozed_until IS NULL OR r.snoozed_until<=:now)";
            $params[':now'] = $now;
        }
        $sql .= ' ORDER BY r.confidence DESC, r.updated_at DESC LIMIT 50';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll());
    }

    public function get(string $domainId, string $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM recommendations WHERE domain_id=:domain_id AND id=:id LIMIT 1');
        $stmt->execute([':domain_id' => $domainId, ':id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->cast((array) $row) : null;
    }

    public function dismiss(string $domainId, string $id): ?array
    {
        $now = time();
        Database::pdo()->prepare("UPDATE recommendations SET status='dismissed', dismissed_at=:now, snoozed_until=NULL, updated_at=:now WHERE domain_id=:domain_id AND id=:id")
            ->execute([':domain_id' => $domainId, ':id' => $id, ':now' => $now]);
        $row = $this->get($domainId, $id);
        if ($row !== null) {
            AuditLog::write('recommendation.dismiss', 'recommendation', $id, $domainId, null, $row);
        }
        return $row;
    }

    public function snooze(string $domainId, string $id, int $seconds): ?array
    {
        $now = time();
        $until = $now + max(3600, min($seconds, 2592000));
        Database::pdo()->prepare("UPDATE recommendations SET status='snoozed', snoozed_until=:until, updated_at=:now WHERE domain_id=:domain_id AND id=:id")
            ->execute([':domain_id' => $domainId, ':id' => $id, ':until' => $until, ':now' => $now]);
        $row = $this->get($domainId, $id);
        if ($row !== null) {
            AuditLog::write('recommendation.snooze', 'recommendation', $id, $domainId, null, $row);
        }
        return $row;
    }

    public function apply(string $domainId, string $id): array
    {
        $recommendation = $this->get($domainId, $id);
        if ($recommendation === null) {
            return ['error' => 'recommendation_not_found', 'status' => 404];
        }

        $action = (array) ($recommendation['one_click_action'] ?? []);
        $kind = (string) ($action['kind'] ?? '');
        $rules = new TrafficRulesService();
        $result = match ($kind) {
            'enable_protection_intent' => $rules->enableProtectionIntent($domainId, (string) ($action['intent_key'] ?? ''), (array) ($action['input'] ?? [])),
            'enable_static_asset_cache' => $rules->setDomainCacheSettings($domainId, (array) ($action['settings'] ?? [])),
            'run_origin_test' => $this->runOriginTest($domainId, (array) $action),
            default => throw new \InvalidArgumentException('unsupported_recommendation_action'),
        };

        $now = time();
        Database::pdo()->prepare("UPDATE recommendations SET status='applied', applied_at=:now, updated_at=:now WHERE domain_id=:domain_id AND id=:id")
            ->execute([':domain_id' => $domainId, ':id' => $id, ':now' => $now]);
        $updated = $this->get($domainId, $id);
        AuditLog::write('recommendation.apply', 'recommendation', $id, $domainId, $recommendation, ['recommendation' => $updated, 'result' => $result]);
        return ['data' => ['recommendation' => $updated, 'result' => $result]];
    }

    private function candidatesForDomain(string $domainId, int $now): array
    {
        $since = $now - 86400;
        $pathCounts = $this->pathCounts($domainId, $since);
        $securityCounts = $this->securityCounts($domainId, $since);
        $cache = $this->cacheStats($domainId, $since);
        $candidates = [];

        if (($pathCounts['login'] ?? 0) >= 5 && !$this->intentEnabled($domainId, 'login_shield')) {
            $candidates[] = $this->intentCandidate('login_shield', 'Protect repeated login traffic', 'Repeated requests are hitting login paths. Enable Login Shield to rate-limit credential attacks safely.', 'Activity shows login-path traffic in the last 24 hours and no enabled Login Shield intent.', min(95, 65 + (int) ($pathCounts['login'] ?? 0)), 'moderate', 'security');
        }
        if (($pathCounts['api'] ?? 0) >= 10 && !$this->intentEnabled($domainId, 'protect_api')) {
            $candidates[] = $this->intentCandidate('protect_api', 'Protect high-volume API paths', 'API paths have elevated request volume. Enable API Protection for token-aware method and burst controls.', 'Activity shows API traffic in the last 24 hours and API Protection is not enabled.', min(95, 60 + (int) (($pathCounts['api'] ?? 0) / 2)), 'moderate', 'security');
        }
        if (($pathCounts['origin_502'] ?? 0) >= 3) {
            $candidates[] = [
                'type' => 'origin_diagnostics',
                'title' => 'Run origin diagnostics',
                'message' => 'The edge has seen repeated 502 responses. Run an origin test to capture reachability, TLS, and host-header details.',
                'why' => 'Request diagnostics include multiple 502 responses or upstream errors in the last 24 hours.',
                'confidence' => min(95, 70 + (int) (($pathCounts['origin_502'] ?? 0) * 3)),
                'risk' => 'safe',
                'impact' => 'reliability',
                'preview_payload' => ['origin_errors_24h' => (int) ($pathCounts['origin_502'] ?? 0)],
                'one_click_action' => ['kind' => 'run_origin_test'],
            ];
        }
        if (($cache['total'] ?? 0) >= 10 && ($cache['hit_ratio'] ?? 1.0) < 0.40) {
            $candidates[] = [
                'type' => 'static_asset_cache',
                'title' => 'Enable static asset caching',
                'message' => 'Cache hit ratio is low. Enable the static asset cache starter to improve repeat asset delivery while bypassing logged-in traffic.',
                'why' => 'Cache analytics show a low hit ratio over the last 24 hours.',
                'confidence' => 78,
                'risk' => 'safe',
                'impact' => 'performance',
                'preview_payload' => ['requests_24h' => (int) $cache['total'], 'cache_hit_ratio' => (float) $cache['hit_ratio']],
                'one_click_action' => ['kind' => 'enable_static_asset_cache', 'settings' => ['enabled' => true, 'static_asset_cache_enabled' => true, 'ignore_query_strings_for_static' => true, 'bypass_logged_in_users' => true]],
            ];
        }
        if (($securityCounts['bot_match'] ?? 0) >= 3 && !$this->intentEnabled($domainId, 'bot_shield')) {
            $candidates[] = $this->intentCandidate('bot_shield', 'Enable Bot Protection', 'Suspicious automation is hitting this domain. Enable Bot Protection to challenge fake search bots and block obvious scrapers.', 'Security events include repeated bot signals in the last 24 hours.', min(95, 70 + (int) ($securityCounts['bot_match'] ?? 0)), 'moderate', 'security');
        }
        if (($securityCounts['waf_match'] ?? 0) >= 3 && !$this->intentEnabled($domainId, 'common_exploits')) {
            $candidates[] = $this->intentCandidate('common_exploits', 'Enable common exploit protection', 'Exploit-like requests are reaching this domain. Enable common exploit protection to block high-confidence attacks.', 'Security events include repeated WAF matches and common exploit protection is not enabled.', min(95, 70 + (int) ($securityCounts['waf_match'] ?? 0)), 'moderate', 'security');
        }
        if ($this->sslRisk($domainId, $now)) {
            $candidates[] = [
                'type' => 'ssl_review',
                'title' => 'Review SSL certificate health',
                'message' => 'A certificate is missing, failed, or expiring soon. Review SSL status before traffic is affected.',
                'why' => 'SSL state has a missing, failed, or soon-expiring certificate for this domain.',
                'confidence' => 82,
                'risk' => 'safe',
                'impact' => 'ssl',
                'preview_payload' => ['route' => "/domains/{$domainId}/ssl"],
                'one_click_action' => ['kind' => 'open_ssl'],
            ];
        }
        return $candidates;
    }

    private function intentCandidate(string $intentKey, string $title, string $message, string $why, int $confidence, string $risk, string $impact): array
    {
        return [
            'type' => $intentKey,
            'title' => $title,
            'message' => $message,
            'why' => $why,
            'confidence' => min(100, max(0, $confidence)),
            'risk' => $risk,
            'impact' => $impact,
            'preview_payload' => ['intent_key' => $intentKey, 'mutates' => false],
            'one_click_action' => ['kind' => 'enable_protection_intent', 'intent_key' => $intentKey, 'input' => []],
        ];
    }

    private function upsertCandidate(string $domainId, array $candidate, int $now): ?array
    {
        $existing = Database::pdo()->prepare('SELECT * FROM recommendations WHERE domain_id=:domain_id AND type=:type LIMIT 1');
        $existing->execute([':domain_id' => $domainId, ':type' => (string) $candidate['type']]);
        $row = $existing->fetch();
        if ($row && (string) $row['status'] === 'applied') {
            return null;
        }
        if ($row && (string) $row['status'] === 'dismissed' && (int) ($row['dismissed_at'] ?? 0) > $now - self::DISMISS_SUPPRESSION_SECONDS) {
            return null;
        }
        if ($row) {
            Database::pdo()->prepare("UPDATE recommendations SET title=:title,message=:message,why=:why,confidence=:confidence,risk=:risk,impact=:impact,preview_payload=:preview,one_click_action=:action,status=CASE WHEN status='snoozed' AND snoozed_until>:snooze_cutoff THEN status ELSE 'open' END,updated_at=:updated_at WHERE id=:id AND domain_id=:domain_id AND type=:type")
                ->execute([
                    ':id' => (string) $row['id'],
                    ':domain_id' => $domainId,
                    ':type' => (string) $candidate['type'],
                    ':title' => (string) $candidate['title'],
                    ':message' => (string) $candidate['message'],
                    ':why' => (string) $candidate['why'],
                    ':confidence' => (int) $candidate['confidence'],
                    ':risk' => (string) $candidate['risk'],
                    ':impact' => (string) $candidate['impact'],
                    ':preview' => json_encode($candidate['preview_payload'] ?? [], JSON_UNESCAPED_SLASHES),
                    ':action' => json_encode($candidate['one_click_action'] ?? [], JSON_UNESCAPED_SLASHES),
                    ':snooze_cutoff' => $now,
                    ':updated_at' => $now,
                ]);
            return $this->get($domainId, (string) $row['id']);
        }
        $id = Uuid::v4();
        Database::pdo()->prepare("INSERT INTO recommendations (id,domain_id,type,title,message,why,confidence,risk,impact,preview_payload,one_click_action,status,created_at,updated_at) VALUES (:id,:domain_id,:type,:title,:message,:why,:confidence,:risk,:impact,:preview,:action,'open',:now,:now)")
            ->execute($this->params($domainId, $candidate, $now) + [':id' => $id]);
        return $this->get($domainId, $id);
    }

    private function params(string $domainId, array $candidate, int $now): array
    {
        return [
            ':domain_id' => $domainId,
            ':type' => (string) $candidate['type'],
            ':title' => (string) $candidate['title'],
            ':message' => (string) $candidate['message'],
            ':why' => (string) $candidate['why'],
            ':confidence' => (int) $candidate['confidence'],
            ':risk' => (string) $candidate['risk'],
            ':impact' => (string) $candidate['impact'],
            ':preview' => json_encode($candidate['preview_payload'] ?? [], JSON_UNESCAPED_SLASHES),
            ':action' => json_encode($candidate['one_click_action'] ?? [], JSON_UNESCAPED_SLASHES),
            ':now' => $now,
        ];
    }

    private function domains(?string $domainId): array
    {
        if ($domainId !== null && $domainId !== '') {
            $stmt = Database::pdo()->prepare('SELECT id FROM domains WHERE id=:id');
            $stmt->execute([':id' => $domainId]);
            return $stmt->fetchAll();
        }
        return Database::pdo()->query('SELECT id FROM domains ORDER BY created_at ASC')->fetchAll();
    }

    private function pathCounts(string $domainId, int $since): array
    {
        $stmt = Database::pdo()->prepare("SELECT COALESCE(SUM(requests_count) FILTER (WHERE path ~* '(^|/)(login|signin|wp-login|admin)(/|$|\\.)'),0) AS login, COALESCE(SUM(requests_count) FILTER (WHERE path LIKE '/api/%' OR path='/api'),0) AS api, COALESCE(SUM(requests_count) FILTER (WHERE status=502 OR router_error IS NOT NULL OR upstream_status LIKE '5%'),0) AS origin_502 FROM usage_rollups WHERE domain_id=:domain_id AND ts>=:since");
        $stmt->execute([':domain_id' => $domainId, ':since' => $since]);
        return array_map('intval', (array) $stmt->fetch());
    }

    private function cacheStats(string $domainId, int $since): array
    {
        $stmt = Database::pdo()->prepare("SELECT COALESCE(SUM(requests_count),0) total, COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='HIT'),0) hits FROM usage_rollups WHERE domain_id=:domain_id AND ts>=:since");
        $stmt->execute([':domain_id' => $domainId, ':since' => $since]);
        $row = (array) $stmt->fetch();
        $total = (int) ($row['total'] ?? 0);
        return ['total' => $total, 'hit_ratio' => $total > 0 ? (int) ($row['hits'] ?? 0) / $total : 1.0];
    }

    private function securityCounts(string $domainId, int $since): array
    {
        $stmt = Database::pdo()->prepare("SELECT event, COUNT(*) count FROM audit_log WHERE domain_id=:domain_id AND event IN ('waf_match','bot_match','rate_limited','geo_block') AND created_at>=:since GROUP BY event");
        $stmt->execute([':domain_id' => $domainId, ':since' => $since]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['event']] = (int) $row['count'];
        }
        return $counts;
    }

    private function intentEnabled(string $domainId, string $intentKey): bool
    {
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM protection_intents WHERE domain_id=:domain_id AND intent_key=:intent_key AND status='enabled'");
        $stmt->execute([':domain_id' => $domainId, ':intent_key' => $intentKey]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function sslRisk(string $domainId, int $now): bool
    {
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM ssl_certificates WHERE domain_id=:domain_id AND (status IN ('missing','failed','expired') OR not_after<:expiry)");
        $stmt->execute([':domain_id' => $domainId, ':expiry' => $now + 2592000]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function runOriginTest(string $domainId, array $action): array
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM domain_origins WHERE domain_id=:domain_id AND enabled=true ORDER BY is_primary DESC, created_at ASC LIMIT 1');
        $stmt->execute([':domain_id' => $domainId]);
        $originId = $action['origin_id'] ?? $stmt->fetchColumn();
        if (!is_string($originId) || $originId === '') {
            return ['error' => 'origin_not_found', 'status' => 404];
        }
        return (new OriginHealthService())->check($domainId, $originId) ?? ['error' => 'origin_not_found', 'status' => 404];
    }

    private function cast(array $row): array
    {
        foreach (['confidence', 'created_at', 'updated_at', 'dismissed_at', 'applied_at', 'snoozed_until'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['preview_payload'] = json_decode((string) ($row['preview_payload'] ?? '{}'), true) ?: [];
        $row['one_click_action'] = json_decode((string) ($row['one_click_action'] ?? '{}'), true) ?: [];
        return $row;
    }
}
