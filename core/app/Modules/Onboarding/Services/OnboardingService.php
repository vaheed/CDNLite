<?php

namespace App\Modules\Onboarding\Services;

use App\Services\ControlPlane\TrafficRulesService;
use App\Support\AuditLog;
use App\Support\Database;
use App\Support\Uuid;

class OnboardingService
{
    public function show(string $domainId): ?array
    {
        if (!$this->domainExists($domainId)) {
            return null;
        }

        $state = $this->state($domainId);
        $recommendation = $this->recommend((array) ($state['answers'] ?? []));

        return [
            'domain_id' => $domainId,
            'status' => (string) ($state['status'] ?? 'not_started'),
            'answers' => (array) ($state['answers'] ?? []),
            'recommended_profile_key' => (string) ($state['recommended_profile_key'] ?? $recommendation['profile_key']),
            'recommendation' => $recommendation,
            'progress' => $this->progress($domainId, (string) ($state['recommended_profile_key'] ?? $recommendation['profile_key'])),
            'skipped_at' => $state['skipped_at'] ?? null,
            'completed_at' => $state['completed_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }

    public function saveAnswers(string $domainId, array $answers): ?array
    {
        if (!$this->domainExists($domainId)) {
            return null;
        }

        $normalized = $this->normalizeAnswers($answers);
        $recommendation = $this->recommend($normalized);
        $current = $this->state($domainId);
        $now = time();
        Database::pdo()->prepare(
            "INSERT INTO domain_onboarding (id,domain_id,status,answers_json,recommended_profile_key,created_at,updated_at)
             VALUES (:id,:domain_id,'in_progress',:answers_json,:recommended_profile_key,:created_at,:updated_at)
             ON CONFLICT (domain_id)
             DO UPDATE SET status='in_progress',answers_json=EXCLUDED.answers_json,recommended_profile_key=EXCLUDED.recommended_profile_key,skipped_at=NULL,updated_at=EXCLUDED.updated_at"
        )->execute([
            ':id' => Uuid::v4(),
            ':domain_id' => $domainId,
            ':answers_json' => json_encode($normalized, JSON_UNESCAPED_SLASHES),
            ':recommended_profile_key' => $recommendation['profile_key'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $after = $this->show($domainId);
        AuditLog::write('onboarding.answers_saved', 'domain_onboarding', $domainId, $domainId, $current, $after);
        return $after;
    }

    public function preview(string $domainId): ?array
    {
        $state = $this->show($domainId);
        if ($state === null) {
            return null;
        }
        $rules = new TrafficRulesService();
        $preview = $rules->previewProtectionProfile($domainId, (string) $state['recommended_profile_key']);
        return [
            'onboarding' => $state,
            'profile_preview' => $preview,
            'mutates' => false,
        ];
    }

    public function apply(string $domainId, array $input = []): ?array
    {
        $state = $this->show($domainId);
        if ($state === null) {
            return null;
        }
        $rules = new TrafficRulesService();
        $before = $this->state($domainId);
        $result = $rules->applyProtectionProfile($domainId, (string) $state['recommended_profile_key'], $input);
        $now = time();
        Database::pdo()->prepare("UPDATE domain_onboarding SET status='completed',completed_at=:completed_at,updated_at=:updated_at WHERE domain_id=:domain_id")
            ->execute([':completed_at' => $now, ':updated_at' => $now, ':domain_id' => $domainId]);
        $after = ['onboarding' => $this->show($domainId), 'profile_result' => $result];
        AuditLog::write('onboarding.apply_profile', 'domain_onboarding', $domainId, $domainId, $before, $after);
        return $after;
    }

    public function skip(string $domainId): ?array
    {
        if (!$this->domainExists($domainId)) {
            return null;
        }
        $before = $this->state($domainId);
        $now = time();
        Database::pdo()->prepare(
            "INSERT INTO domain_onboarding (id,domain_id,status,answers_json,recommended_profile_key,skipped_at,created_at,updated_at)
             VALUES (:id,:domain_id,'skipped','{}','basic_website',:skipped_at,:created_at,:updated_at)
             ON CONFLICT (domain_id)
             DO UPDATE SET status='skipped',skipped_at=EXCLUDED.skipped_at,updated_at=EXCLUDED.updated_at"
        )->execute([':id' => Uuid::v4(), ':domain_id' => $domainId, ':skipped_at' => $now, ':created_at' => $now, ':updated_at' => $now]);
        $after = $this->show($domainId);
        AuditLog::write('onboarding.skip', 'domain_onboarding', $domainId, $domainId, $before, $after);
        return $after;
    }

    public function resume(string $domainId): ?array
    {
        if (!$this->domainExists($domainId)) {
            return null;
        }
        $before = $this->state($domainId);
        $now = time();
        Database::pdo()->prepare("UPDATE domain_onboarding SET status='in_progress',skipped_at=NULL,updated_at=:updated_at WHERE domain_id=:domain_id")
            ->execute([':updated_at' => $now, ':domain_id' => $domainId]);
        if ($before === null) {
            $this->saveAnswers($domainId, []);
        }
        $after = $this->show($domainId);
        AuditLog::write('onboarding.resume', 'domain_onboarding', $domainId, $domainId, $before, $after);
        return $after;
    }

    private function recommend(array $answers): array
    {
        $framework = strtolower((string) ($answers['framework'] ?? ''));
        $siteType = strtolower((string) ($answers['site_type'] ?? 'website'));
        if (!empty($answers['under_attack'])) {
            return $this->recommendation('emergency', 'Emergency Protection', 'Active-attack mode adds high-friction abuse controls first.');
        }
        if ($framework === 'wordpress') {
            return $this->recommendation('wordpress', 'WordPress', 'WordPress sites benefit from login, XML-RPC, scanner, bot, and static asset defaults.');
        }
        if (!empty($answers['sells_products']) || $siteType === 'ecommerce') {
            return $this->recommendation('ecommerce', 'E-commerce', 'Checkout and login paths get extra protection against abuse and automation.');
        }
        if (!empty($answers['has_api']) || $siteType === 'api') {
            return $this->recommendation('api', 'API', 'API workloads get method-aware WAF rules and token/header-aware rate limits.');
        }
        if (!empty($answers['has_login']) || $siteType === 'saas') {
            return $this->recommendation('saas_app', 'SaaS App', 'Applications with sign-in flows get login, API, and automation protection.');
        }
        return $this->recommendation('basic_website', 'Basic Website', 'A safe starter profile protects common exploits and improves static asset caching.');
    }

    private function recommendation(string $key, string $name, string $reason): array
    {
        return ['profile_key' => $key, 'name' => $name, 'reason' => $reason];
    }

    private function normalizeAnswers(array $answers): array
    {
        return [
            'site_type' => (string) ($answers['site_type'] ?? 'website'),
            'has_login' => !empty($answers['has_login']),
            'has_api' => !empty($answers['has_api']),
            'sells_products' => !empty($answers['sells_products']),
            'countries' => array_values(array_filter((array) ($answers['countries'] ?? []), 'is_string')),
            'under_attack' => !empty($answers['under_attack']),
            'framework' => (string) ($answers['framework'] ?? 'other'),
            'enable_now' => array_key_exists('enable_now', $answers) ? !empty($answers['enable_now']) : false,
        ];
    }

    private function progress(string $domainId, string $profileKey): array
    {
        $domain = $this->domain($domainId);
        return [
            ['key' => 'domain_added', 'label' => 'Domain added', 'status' => $domain ? 'complete' : 'pending'],
            ['key' => 'nameservers', 'label' => 'Nameservers verified', 'status' => (($domain['nameserver_status'] ?? '') === 'verified') ? 'complete' : 'pending'],
            ['key' => 'origin', 'label' => 'Origin configured', 'status' => $this->count("SELECT COUNT(*) FROM domain_origins WHERE domain_id=:domain_id AND enabled=true", $domainId) > 0 ? 'complete' : 'pending'],
            ['key' => 'ssl', 'label' => 'SSL queued or active', 'status' => $this->count("SELECT COUNT(*) FROM ssl_certificates WHERE domain_id=:domain_id", $domainId) > 0 ? 'complete' : 'pending'],
            ['key' => 'protection', 'label' => 'Protection profile selected', 'status' => $this->count("SELECT COUNT(*) FROM protection_profiles WHERE domain_id=:domain_id AND profile_key=:profile_key AND status='enabled'", $domainId, [':profile_key' => $profileKey]) > 0 ? 'complete' : 'pending'],
            ['key' => 'edge_ready', 'label' => 'Edge ready', 'status' => $this->count("SELECT COUNT(*) FROM edge_nodes WHERE is_enabled=true AND status='online' AND health_status='healthy' AND EXISTS (SELECT 1 FROM domains WHERE id=:domain_id)", $domainId) > 0 ? 'complete' : 'pending'],
        ];
    }

    private function state(string $domainId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM domain_onboarding WHERE domain_id=:domain_id LIMIT 1');
        $stmt->execute([':domain_id' => $domainId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row = (array) $row;
        $row['answers'] = json_decode((string) ($row['answers_json'] ?? '{}'), true) ?: [];
        unset($row['answers_json']);
        return $row;
    }

    private function domain(string $domainId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM domains WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $domainId]);
        $row = $stmt->fetch();
        return $row ? (array) $row : null;
    }

    private function domainExists(string $domainId): bool
    {
        return $this->domain($domainId) !== null;
    }

    private function count(string $sql, string $domainId, array $extra = []): int
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':domain_id' => $domainId] + $extra);
        return (int) $stmt->fetchColumn();
    }
}
