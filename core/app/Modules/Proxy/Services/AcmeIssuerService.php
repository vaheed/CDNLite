<?php

namespace App\Modules\Proxy\Services;

use App\Modules\Dns\Services\PowerDnsService;
use App\Support\Database;
use App\Support\Secrets;
use App\Support\Uuid;

class AcmeIssuerService
{
    private PowerDnsService $powerDns;
    private ?array $directory = null;
    private ?string $nonce = null;

    public function __construct(private TrafficRulesService $certificates = new TrafficRulesService())
    {
        $this->powerDns = new PowerDnsService();
    }

    public function issue(string $domainId, array $hostnames): array
    {
        if (!Secrets::isConfigured()) {
            throw new \RuntimeException('ssl_secret_key_missing');
        }
        if (!$this->powerDns->isEnabled()) {
            throw new \RuntimeException('powerdns_required_for_dns_01');
        }

        $domain = $this->domain($domainId);
        if ($domain === null) {
            throw new \OutOfBoundsException('domain_not_found');
        }
        if ((int) $domain['proxy_enabled'] !== 1 || (string) $domain['status'] !== 'active') {
            throw new \DomainException('domain_proxy_must_be_active');
        }

        $targets = $this->targetHostnames($domain, $hostnames);
        $account = $this->account();
        $directory = $this->directory();
        $order = $this->signedPost($directory['newOrder'], [
            'identifiers' => array_map(static fn (string $h): array => ['type' => 'dns', 'value' => $h], $targets),
        ], $account);
        $orderUrl = (string) ($order['headers']['Location'] ?? '');
        $orderBody = $order['body'];

        foreach (($orderBody['authorizations'] ?? []) as $authUrl) {
            $this->completeDnsAuthorization((string) $authUrl, $account, (string) $domain['domain']);
        }

        $keyPair = $this->generatePrivateKey();
        $csrDer = $this->csrDer($keyPair['resource'], $targets);
        $finalize = $this->signedPost((string) $orderBody['finalize'], ['csr' => $this->b64($csrDer)], $account);
        $finalOrder = $this->pollOrder($orderUrl !== '' ? $orderUrl : (string) ($finalize['headers']['Location'] ?? ''), $account);
        $certUrl = (string) ($finalOrder['certificate'] ?? '');
        if ($certUrl === '') {
            throw new \RuntimeException('acme_certificate_url_missing');
        }

        $certResp = $this->signedPost($certUrl, null, $account);
        $certificatePem = (string) $certResp['raw'];
        $privateKeyPem = $keyPair['pem'];
        $rows = [];
        foreach ($targets as $hostname) {
            $rows[] = $this->certificates->storeIssuedSslCertificate($domainId, $hostname, 'acme', $certificatePem, $privateKeyPem);
        }
        return $rows;
    }

    private function completeDnsAuthorization(string $authUrl, array $account, string $zoneDomain): void
    {
        $auth = $this->signedPost($authUrl, null, $account)['body'];
        if (($auth['status'] ?? '') === 'valid') {
            return;
        }
        $challenge = null;
        foreach (($auth['challenges'] ?? []) as $candidate) {
            if (($candidate['type'] ?? '') === 'dns-01') {
                $challenge = $candidate;
                break;
            }
        }
        if (!is_array($challenge)) {
            throw new \RuntimeException('acme_dns_01_challenge_missing');
        }

        $identifier = (string) ($auth['identifier']['value'] ?? '');
        $token = (string) ($challenge['token'] ?? '');
        $keyAuthorization = $token . '.' . $account['thumbprint'];
        $txtValue = $this->b64(hash('sha256', $keyAuthorization, true));
        $name = $this->challengeRecordName($identifier, $zoneDomain);
        $dns = $this->powerDns->putEphemeralRecord($zoneDomain, $name, 'TXT', 60, $txtValue);
        if (($dns['ok'] ?? false) !== true) {
            throw new \RuntimeException((string) ($dns['error'] ?? 'powerdns_challenge_sync_failed'));
        }

        $delay = max(0, (int) (getenv('CDNLITE_ACME_DNS_PROPAGATION_SECONDS') ?: 0));
        if ($delay > 0) {
            sleep($delay);
        }

        $this->signedPost((string) $challenge['url'], new \stdClass(), $account);
        $this->pollAuthorization($authUrl, $account);
        $this->powerDns->deleteEphemeralRecord($zoneDomain, $name, 'TXT');
    }

    private function pollAuthorization(string $url, array $account): void
    {
        $attempts = max(1, (int) (getenv('CDNLITE_ACME_POLL_ATTEMPTS') ?: 10));
        for ($i = 0; $i < $attempts; $i++) {
            $body = $this->signedPost($url, null, $account)['body'];
            if (($body['status'] ?? '') === 'valid') {
                return;
            }
            if (($body['status'] ?? '') === 'invalid') {
                throw new \RuntimeException('acme_authorization_invalid');
            }
            sleep(1);
        }
        throw new \RuntimeException('acme_authorization_timeout');
    }

    private function pollOrder(string $url, array $account): array
    {
        $attempts = max(1, (int) (getenv('CDNLITE_ACME_POLL_ATTEMPTS') ?: 10));
        for ($i = 0; $i < $attempts; $i++) {
            $body = $this->signedPost($url, null, $account)['body'];
            if (($body['status'] ?? '') === 'valid') {
                return $body;
            }
            if (($body['status'] ?? '') === 'invalid') {
                throw new \RuntimeException('acme_order_invalid');
            }
            sleep(1);
        }
        throw new \RuntimeException('acme_order_timeout');
    }

    private function account(): array
    {
        $directoryUrl = $this->directoryUrl();
        $stmt = Database::pdo()->prepare('SELECT * FROM ssl_acme_accounts WHERE directory_url=:directory_url LIMIT 1');
        $stmt->execute([':directory_url' => $directoryUrl]);
        $row = $stmt->fetch();
        if ($row) {
            $pem = Secrets::decrypt((string) $row['account_key_pem']);
            return $this->accountPayload((string) $row['kid'], $pem);
        }

        $key = $this->generatePrivateKey();
        $contact = trim((string) (getenv('CDNLITE_ACME_CONTACT_EMAIL') ?: ''));
        if ($contact === '') {
            throw new \RuntimeException('acme_contact_email_required');
        }
        $account = $this->accountPayload(null, $key['pem']);
        $resp = $this->signedPost($this->directory()['newAccount'], [
            'termsOfServiceAgreed' => true,
            'contact' => ['mailto:' . $contact],
        ], $account);
        $kid = (string) ($resp['headers']['Location'] ?? '');
        if ($kid === '') {
            throw new \RuntimeException('acme_account_kid_missing');
        }

        $now = time();
        Database::pdo()->prepare('INSERT INTO ssl_acme_accounts (id,directory_url,kid,account_key_pem,contact_email,created_at,updated_at) VALUES (:id,:directory_url,:kid,:account_key_pem,:contact_email,:created_at,:updated_at)')
            ->execute([':id' => Uuid::v4(), ':directory_url' => $directoryUrl, ':kid' => $kid, ':account_key_pem' => Secrets::encrypt($key['pem']), ':contact_email' => $contact, ':created_at' => $now, ':updated_at' => $now]);
        return $this->accountPayload($kid, $key['pem']);
    }

    private function signedPost(string $url, mixed $payload, array $account): array
    {
        $protected = ['alg' => 'RS256', 'nonce' => $this->nonce(), 'url' => $url];
        if (!empty($account['kid'])) {
            $protected['kid'] = $account['kid'];
        } else {
            $protected['jwk'] = $account['jwk'];
        }
        $payloadB64 = $payload === null ? '' : $this->b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $protectedB64 = $this->b64(json_encode($protected, JSON_UNESCAPED_SLASHES));
        $signatureInput = $protectedB64 . '.' . $payloadB64;
        if (!openssl_sign($signatureInput, $signature, $account['key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('acme_jws_sign_failed');
        }
        return $this->request('POST', $url, json_encode(['protected' => $protectedB64, 'payload' => $payloadB64, 'signature' => $this->b64($signature)], JSON_UNESCAPED_SLASHES), 'application/jose+json');
    }

    private function request(string $method, string $url, ?string $body, string $contentType = 'application/json'): array
    {
        $headers = $body === null ? [] : ['Content-Type: ' . $contentType];
        $http = ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 20];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $ctx = stream_context_create(['http' => $http]);
        $raw = @file_get_contents($url, false, $ctx);
        $headersOut = $this->headers($http_response_header ?? []);
        if (isset($headersOut['Replay-Nonce'])) {
            $this->nonce = $headersOut['Replay-Nonce'];
        }
        $status = (int) ($headersOut['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('acme_http_' . $status . ':' . (is_string($raw) ? $raw : ''));
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return ['headers' => $headersOut, 'body' => is_array($decoded) ? $decoded : [], 'raw' => is_string($raw) ? $raw : ''];
    }

    private function nonce(): string
    {
        if ($this->nonce !== null) {
            $nonce = $this->nonce;
            $this->nonce = null;
            return $nonce;
        }
        $resp = $this->request('HEAD', $this->directory()['newNonce'], null);
        return (string) ($resp['headers']['Replay-Nonce'] ?? '');
    }

    private function directory(): array
    {
        if ($this->directory !== null) {
            return $this->directory;
        }
        $resp = $this->request('GET', $this->directoryUrl(), null);
        foreach (['newNonce', 'newAccount', 'newOrder'] as $key) {
            if (empty($resp['body'][$key])) {
                throw new \RuntimeException('acme_directory_missing_' . $key);
            }
        }
        return $this->directory = $resp['body'];
    }

    private function directoryUrl(): string
    {
        return (string) (getenv('CDNLITE_ACME_DIRECTORY_URL') ?: 'https://acme-staging-v02.api.letsencrypt.org/directory');
    }

    private function accountPayload(?string $kid, string $pem): array
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('acme_account_key_invalid');
        }
        $jwk = $this->rsaJwk($key);
        return ['kid' => $kid, 'key' => $key, 'jwk' => $jwk, 'thumbprint' => $this->b64(hash('sha256', json_encode($jwk, JSON_UNESCAPED_SLASHES), true))];
    }

    private function rsaJwk(\OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
            throw new \RuntimeException('acme_rsa_key_details_missing');
        }
        return ['e' => $this->b64($details['rsa']['e']), 'kty' => 'RSA', 'n' => $this->b64($details['rsa']['n'])];
    }

    private function generatePrivateKey(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('private_key_generate_failed');
        }
        return ['resource' => $key, 'pem' => $pem];
    }

    private function csrDer(\OpenSSLAsymmetricKey $key, array $hostnames): string
    {
        $config = ['digest_alg' => 'sha256', 'req_extensions' => 'v3_req', 'config' => $this->opensslConfig($hostnames)];
        $csr = openssl_csr_new(['commonName' => $hostnames[0]], $key, $config);
        if ($csr === false || !openssl_csr_export($csr, $pem)) {
            throw new \RuntimeException('csr_generate_failed');
        }
        return base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?: '', true) ?: '';
    }

    private function opensslConfig(array $hostnames): string
    {
        $san = implode(',', array_map(static fn (string $h): string => 'DNS:' . $h, $hostnames));
        $path = tempnam(sys_get_temp_dir(), 'cdnlite-acme-openssl-');
        if ($path === false) {
            throw new \RuntimeException('openssl_config_temp_failed');
        }
        file_put_contents($path, "[req]\ndistinguished_name=req_distinguished_name\nreq_extensions=v3_req\n[req_distinguished_name]\n[v3_req]\nsubjectAltName={$san}\n");
        return $path;
    }

    private function targetHostnames(array $domain, array $hostnames): array
    {
        $targets = $hostnames === [] ? [(string) $domain['domain']] : $hostnames;
        $out = [];
        foreach ($targets as $hostname) {
            $h = strtolower(trim((string) $hostname));
            if (!$this->validHostname($h) || !$this->hostnameBelongsToZone($h, (string) $domain['domain'])) {
                throw new \InvalidArgumentException('hostname_outside_domain_domain');
            }
            $out[$h] = $h;
        }
        return array_values($out);
    }

    private function challengeRecordName(string $hostname, string $zoneDomain): string
    {
        $zone = rtrim(strtolower($zoneDomain), '.');
        $host = rtrim(strtolower($hostname), '.');
        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }
        if ($host === $zone) {
            return '_acme-challenge';
        }
        $suffix = '.' . $zone;
        return '_acme-challenge.' . substr($host, 0, -strlen($suffix));
    }

    private function hostnameBelongsToZone(string $hostname, string $zoneDomain): bool
    {
        $zone = rtrim(strtolower($zoneDomain), '.');
        $host = rtrim(strtolower($hostname), '.');
        return $host === $zone || str_ends_with($host, '.' . $zone);
    }

    private function validHostname(string $hostname): bool
    {
        if ($hostname === '') {
            return false;
        }
        if (str_starts_with($hostname, '*.')) {
            $hostname = substr($hostname, 2);
        }
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/', $hostname);
    }

    private function domain(string $domainId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT d.id,d.domain,d.status,
             CASE WHEN EXISTS (SELECT 1 FROM dns_records r WHERE r.domain_id=d.id AND r.proxied=true AND r.status='active') THEN 1 ELSE 0 END AS proxy_enabled
             FROM domains d WHERE d.id=:id LIMIT 1"
        );
        $stmt->execute([':id' => $domainId]);
        $row = $stmt->fetch();
        return $row ? (array) $row : null;
    }

    private function headers(array $lines): array
    {
        $out = [];
        foreach ($lines as $i => $line) {
            if ($i === 0 && preg_match('#\s(\d{3})\s#', (string) $line, $m)) {
                $out['status'] = (int) $m[1];
                continue;
            }
            $pos = strpos((string) $line, ':');
            if ($pos === false) {
                continue;
            }
            $out[substr((string) $line, 0, $pos)] = trim(substr((string) $line, $pos + 1));
        }
        return $out;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
