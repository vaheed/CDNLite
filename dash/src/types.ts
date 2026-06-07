export type Id = string;
export type UsageBucket = 'minute' | 'hour' | 'day';
export type Severity = 'healthy' | 'info' | 'warning' | 'critical' | 'unknown';

export interface ApiEnvelope<T> { data: T; meta?: Record<string, unknown>; }
export interface ApiError { status: number; message: string; code?: string; details?: unknown; }

export interface RuntimeHealth { ok: boolean; ready?: boolean; time?: number; service?: string; error?: string; }
export type ReadinessStatus = 'ok' | 'warning' | 'error';
export interface ReadinessCheck { key: string; status: ReadinessStatus; message: string; fix?: string; link?: string; }
export interface ReadinessGroup { status: ReadinessStatus; checks: ReadinessCheck[]; }
export interface ReadinessResponse { core: ReadinessGroup; edge: ReadinessGroup; checked_at: number; }

export interface Domain {
  id: Id;
  user_id?: Id;
  name: string;
  domain: string;
  status?: string;
  origin_shield_header_name?: string | null;
  origin_shield_secret?: string | null;
  created_at?: number | string;
  updated_at?: number | string;
  nameserver_status?: 'unknown' | 'not_configured' | 'partial' | 'verified' | string;
  verification_token?: string | null;
  last_ns_check_at?: number | null;
  powerdns_zone_created?: boolean;
  nameservers?: Array<{ hostname: string; expected: boolean; observed: boolean; last_checked_at?: number | null }>;
}
export interface CreateDomainInput { zone_name: string; display_name?: string; }
export type UpdateDomainInput = Partial<Omit<Domain, 'id' | 'created_at' | 'updated_at' | 'user_id' | 'nameservers'>>;

export interface DnsRecord {
  id: Id; type: string; name: string; content: string; ttl?: number; priority?: number | null;
  proxied?: boolean; geo_policy_id?: Id | null; edge_target?: string | null; status?: string;
  origin_type?: string; origin_content?: string; public_type?: string; public_content?: string;
  origin_host?: string | null; origin_tls_verify?: 'verify' | 'ignore'; origin_scheme?: 'http' | 'https' | null;
  origin_status?: string; geo_origins?: Record<string, { host: string; tls_verify?: 'verify' | 'ignore' }>;
  routing_policy?: 'standard' | 'geo' | 'anycast' | 'geo_anycast';
  canonical_edge_hostname?: string | null;
  geo_routes_count?: number;
}
export type CreateDnsRecordInput = Omit<DnsRecord, 'id' | 'origin_type' | 'origin_content' | 'public_type' | 'public_content'>;
export type UpdateDnsRecordInput = Partial<CreateDnsRecordInput>;
export interface DomainRoutingSettings {
  domain_id: Id; routing_mode: 'geo' | 'anycast' | 'dns_only'; geo_health_port: number;
  geo_selector: string; anycast_ipv4?: string | null; anycast_ipv6?: string | null; anycast_cname?: string | null;
}
export interface DnsRoutingPreview { type: string; content: string; routing_mode: string; powerdns: string; warning?: string | null; }
export interface EdgeCountry { country_code: string; name?: string; node_count: number; has_ipv4: boolean; has_ipv6: boolean; }
export interface GeoRoute { id?: Id; country_code?: string | null; edge_country_code?: string; enabled?: boolean; }

export interface RedirectRule { id: Id; enabled: boolean; source_path: string; target_url: string; status_code: number; priority: number; match_type: string; preserve_query: boolean; }
export interface PageRule { id: Id; enabled: boolean; pattern?: string; path_pattern?: string; priority: number; actions: Record<string, unknown>; }
export interface CacheSettings { enabled: boolean; default_edge_ttl_seconds: number; default_browser_ttl_seconds: number | null; cache_query_string_mode: string; respect_origin_cache_control: boolean; cache_authorized_requests: boolean; stale_if_error_seconds: number; }
export interface CacheRule { id: Id; enabled: boolean; path_prefix: string; ttl_seconds: number; }
export interface PurgeRequest { id: Id; domain_id?: Id; type: 'url' | 'prefix' | 'domain' | 'everything' | string; value?: string; status?: string; created_at?: number | string; updated_at?: number | string; }
export interface WafRule { id: Id; type: string; pattern: string; action: 'block' | 'log' | 'allow' | string; priority: number; enabled?: boolean; status?: string; }
export interface RateLimitRule { id: Id; enabled: boolean; requests_per_minute: number; priority: number; path_prefix: string; key_type: 'ip' | 'ip_path' | string; action: string; }
export interface SslCertificate { id: Id; hostname: string; status?: string; acme_status?: string; days_left?: number; days_until_expiry?: number; not_after?: number; expires_at?: number | string; issuer?: string; subject?: string; last_error?: string; created_at?: number | string; }
export interface SslSettings { domain_id: Id; force_https: boolean; min_tls_version: '1.2' | '1.3'; auto_renew: boolean; created_at?: number; updated_at?: number; }
export interface AcmeProgress { certificate_id: Id; hostname: string; status: string; error?: string | null; updated_at: number; }
export interface SslRenewalHistory { id: Id; hostname: string; action: string; status: string; error?: string | null; started_at: number; completed_at?: number | null; }
export interface AcmeStatus { progress: AcmeProgress[]; history: SslRenewalHistory[]; }
export interface ManualCertificateInput { hostname: string; certificate_pem: string; private_key_pem: string; }
export interface EdgeNode { edge_id: string; identity_status?: 'ok' | 'warning' | string; hostname?: string; public_ip?: string; public_ipv4?: string; public_ipv6?: string; region?: string; country?: string; continent?: string; version?: string; status?: string; is_enabled?: boolean; geo_enabled?: boolean; anycast_enabled?: boolean; health_status?: string; last_heartbeat?: number | string | null; last_heartbeat_at?: number | string | null; created_at?: number | string; updated_at?: number | string; }
export interface EdgePoolMember { id: Id; edge_id: string; hostname: string; status: string; public_ipv4: string; public_ipv6: string; enabled: boolean; weight: number; }
export interface EdgePool { id: Id; name: string; mode: 'geo' | 'anycast'; description?: string | null; members: EdgePoolMember[]; created_at: number; updated_at: number; }
export interface EdgeDnsRecord { name: string; fqdn: string; type: string; ttl: number; content: string; mode?: string; }
export interface EdgeDnsStatus { base_domain: string; zone_prefix: string; powerdns_enabled: boolean; records: EdgeDnsRecord[]; warnings: Array<{ edge_id: string; error: string }>; effective_hash?: string | null; synced_at?: number | null; }
export interface UsagePoint { bucket_ts: number; requests_count: number; bytes_in: number; bytes_out: number; }
export interface UsageSummary { domain_id?: Id; bucket?: UsageBucket; requests_count?: number; total_requests?: number; requests?: number; bytes_in?: number; bytes_out?: number; records?: number; cache_hit_ratio?: number; points?: UsagePoint[]; }
export interface CacheAnalyticsRow { cache_status: string; count: number; bytes_out: number; }
export interface CacheAnalytics { rows?: CacheAnalyticsRow[]; total_requests?: number; bytes_out?: number; hit?: number; miss?: number; expired?: number; stale?: number; bypass?: number; unknown?: number; hit_ratio?: number; }
export interface SecurityEvent { id: Id; domain_id?: Id; domain_name?: string; actor_id?: string | null; edge_id?: string | null; type?: string; decision?: string; action?: string; severity?: Severity | string; timestamp?: number | string; created_at?: number | string; payload?: unknown; details?: Record<string, unknown> | null; }
export interface PaginatedResult<T> { items: T[]; total: number; limit: number; offset: number; }
export interface SecuritySummary { total: number; by_type: Record<string, number>; top_ips: Array<{ value: string; count: number }>; top_domains: Array<{ domain_id?: Id | null; name?: string | null; count: number }>; }
export interface AuditEntry { id: Id; actor_type: string; actor_id?: string | null; action: string; resource_type: string; resource_id?: string | null; domain_id?: Id | null; domain_name?: string | null; type?: string | null; details?: unknown; before?: unknown; after?: unknown; created_at: number; }
export interface ConfigSnapshot { version?: string; generated_at?: number | string; hosts?: unknown[]; upstreams?: unknown[]; geo_upstreams?: unknown[]; headers?: unknown; dns_records?: unknown[]; cache_rules?: unknown[]; [key: string]: unknown; }
export interface ConfigSnapshotSummary { version: number; generated_at: number; content_hash: string; size: number; active: boolean; }
export interface ConfigSnapshotChange { path: string; before: unknown; after: unknown; }
export interface ConfigSnapshotDiff { from_version: number; to_version: number; changes: ConfigSnapshotChange[]; }

export interface OpsDiagnostic {
  domains: number; edges: number; recentSecurityEvents: number; sslRisks: number;
  totalRequests: number; bytesIn: number; bytesOut: number; cacheHitRatio: number;
  offlineEdges: number; recentPurges: number;
}

export interface SettingFieldDefinition { type: 'string' | 'bool' | 'int' | 'list'; secret: boolean; description?: string | null; }
export interface SecretSettingValue { configured: boolean; updated_at?: number | null; }
export interface SettingsAuditEntry { id: Id; key: string; actor?: string | null; old_value: unknown; new_value: unknown; created_at: number; }
export interface SettingsGroup {
  group: string;
  values: Record<string, unknown | SecretSettingValue>;
  fields: Record<string, SettingFieldDefinition>;
  audit: SettingsAuditEntry[];
}
export interface SettingsIndex { groups: Record<string, SettingsGroup>; }
export interface SettingsValidation { valid: boolean; errors: Record<string, string>; }
export interface OverviewDomain { domain_id: Id; name: string; domain: string; requests: number; }
export interface OverviewSnapshot { version: number; generated_at: number; }
export interface Overview {
  domains_count: number; active_domains: number; total_requests_24h: number; bandwidth_24h_bytes: number;
  cache_hit_ratio_24h: number; edge_online: number; edge_offline: number; security_events_24h: number;
  ssl_expiring_count: number; top_domains: OverviewDomain[]; recent_snapshots: OverviewSnapshot[];
}
export interface OverviewWarning { severity: 'warning' | 'critical' | 'info'; message: string; link: string; }
