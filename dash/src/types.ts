export type Id = string;
export type UsageBucket = 'minute' | 'hour' | 'day';
export type Severity = 'healthy' | 'info' | 'warning' | 'critical' | 'unknown';

export interface ApiEnvelope<T> { data: T; meta?: Record<string, unknown>; }
export interface ApiError { status: number; message: string; code?: string; details?: unknown; }

export interface RuntimeHealth { ok: boolean; ready?: boolean; time?: number; service?: string; error?: string; }
export type ReadinessStatus = 'ok' | 'warning' | 'error';
export interface ReadinessCheck { key: string; status: ReadinessStatus; message: string; fix?: string; link?: string; }
export interface ReadinessGroup { status: ReadinessStatus; checks: ReadinessCheck[]; }
export interface ReadinessResponse { core: ReadinessGroup; edge: ReadinessGroup; domain?: ReadinessGroup; checked_at: number; }

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
export interface NameserverVerification {
  expected_nameservers: string[];
  observed_nameservers: string[];
  matched_nameservers: string[];
  missing_nameservers: string[];
  checked_at: number;
  status: string;
  resolver_errors: string[];
  forced_verified?: boolean;
  reseeded_expected?: boolean;
  reason?: string;
}
export type DomainNameserverVerification = Domain & NameserverVerification;
export interface CreateDomainInput { zone_name: string; display_name?: string; }
export type UpdateDomainInput = Partial<Omit<Domain, 'id' | 'created_at' | 'updated_at' | 'user_id' | 'nameservers'>>;

export interface DnsRecord {
  id: Id; type: string; name: string; content: string; ttl?: number; priority?: number | null;
  proxied?: boolean; geo_policy_id?: Id | null; status?: string;
  effective_status?: 'active' | 'disabled'; disabled_reason?: string | null;
  origin_type?: string; origin_content?: string; public_type?: string; public_content?: string;
  origin_host?: string | null; origin_tls_verify?: 'verify' | 'ignore'; origin_scheme?: 'http' | 'https' | null;
  origin_status?: string; geo_origins?: Record<string, { host: string; scheme?: 'http' | 'https'; port?: 80 | 443 | number; tls_verify?: 'verify' | 'ignore' }>;
  routing_policy?: 'standard' | 'geo' | 'anycast' | 'geo_anycast';
  geo_routes_count?: number;
  managed_by?: string | null; readonly?: boolean;
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
export interface CacheSettings { enabled: boolean; default_edge_ttl_seconds: number; default_browser_ttl_seconds: number | null; cache_query_string_mode: string; respect_origin_cache_control: boolean; cache_authorized_requests: boolean; stale_if_error_seconds: number; static_asset_cache_enabled: boolean; ignore_query_strings_for_static: boolean; bypass_logged_in_users: boolean; }
export interface ManagedRuleMetadata { profile_id?: Id | null; intent_id?: Id | null; template_key?: string | null; managed_by?: string | null; user_modified?: boolean; last_generated_at?: number | null; last_applied_at?: number | null; }
export type ProtectionRisk = 'safe' | 'moderate' | 'risky' | string;
export interface ProtectionIntentRecord {
  id: Id; domain_id?: Id; profile_id?: Id | null; intent_key: string; name: string; status: string; mode?: string;
  settings?: Record<string, unknown>; created_at?: number; updated_at?: number;
}
export interface ProtectionGeneratedRule {
  rule_table: string; rule_id?: Id; template_key: string; payload?: Record<string, unknown>;
  enabled?: boolean; user_modified?: boolean; managed_by?: string | null;
}
export interface ProtectionIntentSummary {
  intent_key: string; name: string; summary: string; risk: ProtectionRisk; recommended_mode: string;
  status: string; intent?: ProtectionIntentRecord | null; generated_rules: ProtectionGeneratedRule[];
}
export interface ProtectionIntentPreview {
  intent_key: string; name: string; mode: string; risk: ProtectionRisk; rules: ProtectionGeneratedRule[]; mutates: boolean;
}
export interface ProtectionIntentMutationResult {
  intent: ProtectionIntentRecord; rules: ProtectionGeneratedRule[]; rollback_point_id?: Id;
}
export interface ProtectionProfileRecord {
  id: Id; domain_id?: Id; profile_key: string; name: string; status: string;
  settings?: Record<string, unknown>; created_at?: number; updated_at?: number;
}
export interface ProtectionProfileSummary {
  profile_key: string; name: string; summary: string; risk: ProtectionRisk; intent_keys: string[];
  status: string; profile?: ProtectionProfileRecord | null;
}
export interface ProtectionProfilePreview {
  profile_key: string; name: string; risk: ProtectionRisk; intent_keys: string[];
  intents: ProtectionIntentPreview[]; mutates: boolean;
}
export interface ProtectionProfileMutationResult {
  profile: ProtectionProfileRecord | null; intents: ProtectionIntentMutationResult[];
}
export interface OnboardingAnswers {
  site_type?: string; has_login?: boolean; has_api?: boolean; sells_products?: boolean;
  countries?: string[]; under_attack?: boolean; framework?: string; enable_now?: boolean;
}
export interface OnboardingProgressStep { key: string; label: string; status: string; }
export interface OnboardingState {
  domain_id: Id; status: string; answers: OnboardingAnswers; recommended_profile_key: string;
  recommendation: { profile_key: string; name: string; reason: string };
  progress: OnboardingProgressStep[]; skipped_at?: number | null; completed_at?: number | null; updated_at?: number | null;
}
export interface OnboardingPreview {
  onboarding: OnboardingState; profile_preview: ProtectionProfilePreview; mutates: boolean;
}
export interface OnboardingApplyResult {
  onboarding: OnboardingState; profile_result: ProtectionProfileMutationResult;
}
export interface ApiProtectionPathSuggestion {
  path_prefix: string; requests_24h: number; default?: boolean;
}
export interface ApiProtectionDiscovery {
  domain_id: Id; paths: ApiProtectionPathSuggestion[]; recommended_methods: string[]; recommended_header_key: string;
}
export interface CacheRule extends ManagedRuleMetadata { id: Id; enabled: boolean; path_prefix: string; ttl_seconds: number; }
export interface PurgeRequest { id: Id; domain_id?: Id; type: 'url' | 'prefix' | 'domain' | 'everything' | string; value?: string; status?: string; created_at?: number | string; updated_at?: number | string; }
export interface WafRule extends ManagedRuleMetadata { id: Id; type: string; pattern: string; action: 'block' | 'log' | 'allow' | string; priority: number; enabled?: boolean; status?: string; }
export interface RateLimitRule extends ManagedRuleMetadata { id: Id; enabled: boolean; requests_per_minute: number; priority: number; path_prefix: string; key_type: 'ip' | 'ip_path' | 'header' | 'header_path' | string; key_header_name?: string | null; action: string; }
export interface HeaderRule extends ManagedRuleMetadata { id: Id; enabled: boolean; priority: number; operation: 'set' | 'remove' | 'append' | string; header_name: string; header_value?: string | null; path_pattern: string; }
export interface IpRule extends ManagedRuleMetadata { id: Id; enabled: boolean; rule_type: 'allow' | 'block' | string; cidr: string; description?: string | null; }
export interface DomainOrigin {
  id: Id; domain_id: Id; scheme: 'http' | 'https'; host: string; port: 80 | 443 | number;
  dns_record_id?: Id | null; source?: 'manual' | 'dns_record' | 'imported' | string; role?: 'primary' | 'backup' | string;
  weight?: number; host_header?: string | null; sni?: string | null; tls_verify?: 'verify' | 'ignore';
  preserve_host?: boolean;
  is_primary: boolean; health_check_path: string; health_check_interval_seconds: number;
  health_check_timeout_seconds: number; health_status: 'healthy' | 'unhealthy' | 'unknown' | string;
  last_check_at?: number | null; last_error?: string | null; enabled: boolean;
  created_at?: number; updated_at?: number;
}
export interface SslCertificate { id: Id; hostname: string; status?: string; acme_status?: string; days_left?: number; days_until_expiry?: number; not_after?: number; expires_at?: number | string; issuer?: string; subject?: string; last_error?: string; created_at?: number | string; }
export interface SslSettings { domain_id: Id; force_https: boolean; min_tls_version: '1.2' | '1.3'; auto_renew: boolean; created_at?: number; updated_at?: number; }
export interface AcmeProgress { certificate_id: Id; hostname: string; status: string; error?: string | null; updated_at: number; }
export interface SslRenewalHistory { id: Id; hostname: string; action: string; status: string; error?: string | null; started_at: number; completed_at?: number | null; }
export interface SslJob {
  id: Id; domain_id: Id; status: string; progress_percent: number; message: string;
  error_code?: string | null; error_detail?: string | null; hostnames: string[];
  scheduler_stale?: boolean; scheduler_hint?: string | null; stale_seconds?: number;
  created_at: number; updated_at: number; finished_at?: number | null;
}
export interface SslJobRequest { job_id: Id; status: string; message: string; job: SslJob; }
export interface AcmeStatus { progress: AcmeProgress[]; history: SslRenewalHistory[]; jobs?: SslJob[]; }
export interface ManualCertificateInput { hostname: string; certificate_pem: string; private_key_pem: string; }
export interface EdgeNode { edge_id: string; identity_status?: 'ok' | 'warning' | string; hostname?: string; public_ip?: string; public_ipv4?: string; public_ipv6?: string; region?: string; country?: string; continent?: string; version?: string; status?: string; is_enabled?: boolean; geo_enabled?: boolean; anycast_enabled?: boolean; health_status?: string; applied_config_version?: number | null; last_config_pull_at?: number | null; config_apply_error?: string | null; last_heartbeat?: number | string | null; last_heartbeat_at?: number | string | null; created_at?: number | string; updated_at?: number | string; }
export interface EdgePoolMember { id: Id; edge_id: string; hostname: string; status: string; public_ipv4: string; public_ipv6: string; enabled: boolean; weight: number; }
export interface EdgePool { id: Id; name: string; mode: 'geo' | 'anycast'; description?: string | null; members: EdgePoolMember[]; created_at: number; updated_at: number; }
export interface EdgeDnsRecord { zone_name: string; rrset_name: string; rrset_type: string; ttl: number; records: string[]; source: string; }
export interface EdgeDnsState { edge_id: string; ip: string; ip_family: 'A' | 'AAAA'; region: string; anycast: boolean; healthy: boolean; last_check_at: number; }
export interface EdgeDnsStatus { cdn_zone: string; proxy_host: string; static_anycast?: { ipv4: string[]; ipv6: string[] }; powerdns_enabled: boolean; records: EdgeDnsRecord[]; edge_state: EdgeDnsState[]; warnings: Array<{ edge_id: string; error: string }>; effective_hash?: string | null; synced_at?: number | null; }
export interface DnsZoneStatus { zone_name: string; status: string; pending_changes: number; desired_rrsets: number; last_attempt_at?: number | null; last_success_at?: number | null; last_error?: string | null; desired_hash?: string | null; applied_hash?: string | null; converged: boolean; }
export interface DomainDnsStatus { zone: string; status: string; last_attempt_at?: number | null; last_success_at?: number | null; last_error?: string | null; pending_changes: number; converged: boolean; }
export interface DnsOperations {
  setup: { enabled: boolean; configured: boolean; api_url: string; server_id: string; api_key_configured: boolean; cdn_zone: string; cdn_proxy_host: string; static_anycast?: { ipv4: string[]; ipv6: string[] }; apex_proxy_mode: 'DIRECT'; bundled_dnsgeo: boolean; poweradmin_url: string; api: { ok?: boolean; error?: string } };
  dnsgeo: { powerdns_auth: boolean; postgresql: boolean; mmdb: boolean; edns_subnet_processing: boolean; lua_records: boolean; alias_expansion: boolean; resolver_configured: boolean; resolver: string; api_publicly_exposed: boolean };
}
export interface UsagePoint { bucket_ts: number; requests_count: number; bytes_in: number; bytes_out: number; }
export interface UsageSummary { domain_id?: Id; bucket?: UsageBucket; requests_count?: number; total_requests?: number; requests?: number; bytes_in?: number; bytes_out?: number; records?: number; cache_hit_ratio?: number; points?: UsagePoint[]; }
export interface RequestActivity {
  id: Id; ts: number; request_id?: string | null; domain_id: Id; edge_node_id: string;
  host?: string | null; method?: string | null; path?: string | null; query_redacted?: Record<string, unknown>;
  client_country?: string | null; status: number; bytes_in: number; bytes_out: number;
  cache_status?: string | null; origin_id?: string | null; origin_host?: string | null;
  upstream_status?: string | null; upstream_response_time_ms?: number | null;
  upstream_addr?: string | null; request_time_ms?: number | null; router_error?: string | null;
  security_event_type?: string | null; rule_id?: string | null;
}
export interface ActivityTimelineItem {
  id: string; type: 'request' | 'error' | 'audit' | 'security' | string; ts: number;
  title: string; summary?: string | null; request_id?: string | null; friendly?: ActivityFriendly; details: unknown;
}
export interface ActivityTimeline { items: ActivityTimelineItem[]; total: number; limit: number; offset: number; cursor?: string | null; }
export interface ActivityFriendly {
  category: string; intent: string; label: string; title: string; summary: string;
  severity: Severity | string; recommendation?: string | null;
}
export interface BeginnerActivitySummary {
  headline: string;
  counts: Record<string, number>;
  cards: Array<{ key: string; label: string; count: number; category: string }>;
  recommendations: Array<{ type: string; label: string; reason: string }>;
}
export interface ActivitySummary {
  total_requests: number; forwarded_requests: number; bytes_in: number; bytes_out: number;
  cache_hit_ratio: number; status_counts: Record<string, number>;
  top_paths: Array<{ value: string; count: number }>;
  top_countries: Array<{ value: string; count: number }>;
  top_origins: Array<{ value: string; count: number }>;
  top_edge_nodes: Array<{ value: string; count: number }>;
  recent_origin_errors: RequestActivity[];
  beginner?: BeginnerActivitySummary;
}
export interface ActivityExport { domain_id: Id; generated_at: number; format: 'json'; items: ActivityTimelineItem[]; }
export interface CacheAnalyticsRow { cache_status: string; count: number; bytes_out: number; }
export interface CacheAnalytics { rows?: CacheAnalyticsRow[]; total_requests?: number; bytes_out?: number; hit?: number; miss?: number; expired?: number; stale?: number; bypass?: number; unknown?: number; hit_ratio?: number; }
export interface SecurityEvent { id: Id; domain_id?: Id; domain_name?: string; actor_id?: string | null; edge_id?: string | null; type?: string; decision?: string; action?: string; severity?: Severity | string; timestamp?: number | string; created_at?: number | string; payload?: unknown; details?: Record<string, unknown> | null; }
export interface PaginatedResult<T> { items: T[]; total: number; limit: number; offset: number; }
export interface SecuritySummary { total: number; by_type: Record<string, number>; top_ips: Array<{ value: string; count: number }>; top_domains: Array<{ domain_id?: Id | null; name?: string | null; count: number }>; }
export interface AuditEntry { id: Id; actor_type: string; actor_id?: string | null; action: string; resource_type: string; resource_id?: string | null; domain_id?: Id | null; domain_name?: string | null; type?: string | null; details?: unknown; before?: unknown; after?: unknown; created_at: number; }
export interface OperationsEvent {
  id: Id; source: 'audit' | 'security' | 'dns' | 'job' | string; type: string; severity: Severity | string;
  status: string; summary: string; domain_id?: Id | null; domain_name?: string | null;
  created_at: number; details: unknown;
}
export interface SystemJob extends SslJob { domain_name?: string | null; }
export interface ConfigSnapshot { version?: string; generated_at?: number | string; hosts?: unknown[]; upstreams?: unknown[]; geo_upstreams?: unknown[]; headers?: unknown; dns_records?: unknown[]; cache_rules?: unknown[]; [key: string]: unknown; }
export interface ConfigSnapshotSummary { version: number; generated_at: number; content_hash: string; size: number; active: boolean; }
export interface ConfigSnapshotChange { path: string; before: unknown; after: unknown; }
export interface ConfigSnapshotDiff { from_version: number; to_version: number; changes: ConfigSnapshotChange[]; }

export interface OpsDiagnostic {
  domains: number; edges: number; recentSecurityEvents: number; sslRisks: number;
  totalRequests: number; bytesIn: number; bytesOut: number; cacheHitRatio: number;
  offlineEdges: number; recentPurges: number;
}

export interface SettingFieldDefinition { type: 'string' | 'bool' | 'int' | 'list' | 'ipv4_list_optional' | 'ipv6_list_optional'; secret: boolean; description?: string | null; }
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
export interface ReportQuery { domain_id?: Id; from?: number; to?: number; bucket?: UsageBucket; compare?: boolean; limit?: number; }
export interface ReportTimeRange { from: number; to: number; bucket: UsageBucket; domain_id?: Id | null; }
export interface ReportPoint { bucket_ts: number; value?: number; count?: number; requests_count?: number; bytes_in?: number; bytes_out?: number; }
export interface ReportKpis {
  total_requests: number; bandwidth_in_bytes: number; bandwidth_out_bytes: number; cache_hit_ratio: number;
  active_domains: number; online_edges: number; offline_edges: number; security_events: number; waf_blocks: number;
  rate_limited_requests: number; origin_errors: number; ssl_expiring_count: number; pending_dns_changes: number; failed_jobs: number;
}
export interface ReportWarning { severity: 'warning' | 'critical' | 'info'; message: string; link: string; count?: number; }
export interface ReportSummary { time_range: ReportTimeRange; previous_time_range?: ReportTimeRange | null; kpis: ReportKpis; deltas?: Record<string, { absolute: number; percent: number | null }> | null; warnings: ReportWarning[]; generated_at: number; }
export interface ReportDistributionRow { value?: string; status?: string; status_class?: string; severity?: string; action?: string; count: number; requests?: number; bytes_out?: number; }
export interface ReportTraffic {
  time_range: ReportTimeRange; requests: ReportPoint[]; bandwidth: { in: ReportPoint[]; out: ReportPoint[] };
  cache_hit_ratio: ReportPoint[]; status_distribution: ReportDistributionRow[]; top_domains: OverviewDomain[];
  top_paths: ReportDistributionRow[]; top_countries: ReportDistributionRow[]; top_edge_nodes: Array<{ edge_node_id: string; hostname?: string | null; requests: number; bytes_out: number }>;
  recent_problem_requests: RequestActivity[]; generated_at: number;
}
export interface ReportCache {
  time_range: ReportTimeRange; status_distribution: Array<{ status: string; count: number; bytes_out: number }>;
  hit_ratio_trend: ReportPoint[]; bytes: { served_from_cache_bytes: number; served_from_origin_bytes: number };
  top_uncached_paths: Array<{ path: string; requests: number; bytes_out: number }>; purge_timeline: ReportPoint[];
  cache_rule_match_counts: null | ReportDistributionRow[]; unavailable?: Record<string, string>; generated_at: number;
}
export interface ReportEdge {
  time_range: ReportTimeRange; counts: { online: number; offline: number; total: number };
  by_region: ReportDistributionRow[]; by_country: ReportDistributionRow[]; last_heartbeat_age: Array<{ edge_id: string; hostname: string; age_seconds: number }>;
  config_version_drift: Array<{ edge_id: string; hostname: string; applied_config_version?: number | null; latest_config_version: number; drift: boolean }>;
  failed_config_pulls: Array<{ edge_id: string; hostname: string; config_apply_error: string; last_config_pull_at?: number | null }>;
  traffic_by_edge_node: Array<{ edge_node_id: string; hostname?: string | null; requests: number; bytes_out: number }>;
  error_rate_by_edge_node: Array<{ edge_node_id: string; hostname?: string | null; requests: number; errors: number; error_rate: number }>;
  nodes: EdgeNode[]; generated_at: number;
}
export interface ReportSecurity {
  time_range: ReportTimeRange; events_over_time: ReportPoint[]; by_severity: ReportDistributionRow[]; by_type: ReportDistributionRow[];
  waf_actions: ReportDistributionRow[]; rate_limit_actions: ReportDistributionRow[]; top_attacking_ips: ReportDistributionRow[];
  top_attacked_domains: Array<{ domain_id?: Id | null; name?: string | null; count: number }>; recent_critical_events: SecurityEvent[];
  unavailable?: Record<string, string>; generated_at: number;
}
export interface ReportReliability {
  time_range: ReportTimeRange; ssl_statuses: ReportDistributionRow[]; certificates_expiring_soon: SslCertificate[];
  acme_job_progress: ReportDistributionRow[]; dns_zones: { total: number; converged: number; pending: number };
  powerdns_sync_status: ReportDistributionRow[]; nameserver_verification_status: ReportDistributionRow[]; recent_dns_errors: unknown[];
  pending_dns_changes: DnsZoneStatus[]; origin_health_counts: ReportDistributionRow[]; generated_at: number;
}
export interface ReportOperations {
  time_range: ReportTimeRange; job_queue_status_counts: ReportDistributionRow[]; failed_jobs_over_time: ReportPoint[];
  recent_jobs: SystemJob[]; event_timeline: unknown[]; recent_audit_entries: AuditEntry[]; most_active_actors: ReportDistributionRow[];
  most_changed_resources: ReportDistributionRow[]; recent_config_snapshots: ConfigSnapshotSummary[]; generated_at: number;
}
export interface Recommendation {
  id: Id; domain_id: Id; domain_name?: string; type: string; title: string; message: string; why: string;
  confidence: number; risk: ProtectionRisk; impact: 'security' | 'reliability' | 'performance' | 'ssl' | string;
  preview_payload: Record<string, unknown>; one_click_action: Record<string, unknown>;
  status: 'open' | 'snoozed' | 'dismissed' | 'applied' | string;
  snoozed_until?: number | null; dismissed_at?: number | null; applied_at?: number | null;
  created_at: number; updated_at: number;
}
