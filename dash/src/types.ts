export type Id = string;
export type UsageBucket = 'minute' | 'hour' | 'day';
export type Severity = 'healthy' | 'info' | 'warning' | 'critical' | 'unknown';

export interface ApiEnvelope<T> { data: T; meta?: Record<string, unknown>; }
export interface ApiError { status: number; message: string; code?: string; details?: unknown; }

export interface RuntimeHealth { ok: boolean; ready?: boolean; time?: number; service?: string; error?: string; }

export interface Site {
  id: Id;
  user_id?: Id;
  name: string;
  domain: string;
  origin_scheme?: 'http' | 'https' | string;
  origin_host: string;
  origin_port: number;
  proxy_enabled: boolean;
  status?: string;
  geo_origins?: Record<string, string> | string[];
  origin_shield_header_name?: string | null;
  origin_shield_secret?: string | null;
  created_at?: number | string;
  updated_at?: number | string;
}
export type CreateSiteInput = Omit<Site, 'id' | 'created_at' | 'updated_at' | 'user_id'>;
export type UpdateSiteInput = Partial<CreateSiteInput>;

export interface DnsRecord {
  id: Id; type: string; name: string; content: string; ttl?: number; priority?: number | null;
  proxied?: boolean; geo_policy_id?: Id | null; edge_target?: string | null; status?: string;
  origin_type?: string; origin_content?: string; public_type?: string; public_content?: string;
}
export type CreateDnsRecordInput = Omit<DnsRecord, 'id' | 'origin_type' | 'origin_content' | 'public_type' | 'public_content'>;
export type UpdateDnsRecordInput = Partial<CreateDnsRecordInput>;

export interface RedirectRule { id: Id; enabled: boolean; source_path: string; target_url: string; status_code: number; priority: number; match_type: string; preserve_query: boolean; }
export interface PageRule { id: Id; enabled: boolean; pattern?: string; path_pattern?: string; priority: number; actions: Record<string, unknown>; }
export interface CacheSettings { enabled: boolean; default_edge_ttl_seconds: number; default_browser_ttl_seconds: number | null; cache_query_string_mode: string; respect_origin_cache_control: boolean; cache_authorized_requests: boolean; stale_if_error_seconds: number; }
export interface CacheRule { id: Id; enabled: boolean; path_prefix: string; ttl_seconds: number; }
export interface PurgeRequest { id: Id; site_id?: Id; type: 'url' | 'prefix' | 'site' | 'everything' | string; value?: string; status?: string; created_at?: number | string; updated_at?: number | string; }
export interface WafRule { id: Id; type: string; pattern: string; action: 'block' | 'log' | 'allow' | string; priority: number; enabled?: boolean; status?: string; }
export interface RateLimitRule { enabled?: boolean; requests_per_minute: number; priority?: number; path_prefix?: string; key_type?: 'ip' | 'ip_path' | string; action?: string; }
export interface SslCertificate { id: Id; hostname: string; status?: string; days_left?: number; expires_at?: number | string; issuer?: string; subject?: string; created_at?: number | string; }
export interface ManualCertificateInput { hostname: string; certificate_pem: string; private_key_pem: string; }
export interface EdgeNode { edge_id: string; hostname?: string; public_ip?: string; public_ipv4?: string; public_ipv6?: string; region?: string; country?: string; continent?: string; version?: string; status?: string; is_enabled?: boolean; health_status?: string; last_heartbeat?: number | string | null; last_heartbeat_at?: number | string | null; created_at?: number | string; updated_at?: number | string; }
export interface UsageSummary { site_id?: Id; bucket?: UsageBucket; requests_count?: number; total_requests?: number; requests?: number; bytes_in?: number; bytes_out?: number; records?: number; cache_hit_ratio?: number; points?: Array<Record<string, unknown>>; }
export interface CacheAnalytics { hit?: number; miss?: number; bypass?: number; stale?: number; hit_ratio?: number; points?: Array<Record<string, unknown>>; }
export interface SecurityEvent { id: Id; site_id?: Id; site_name?: string; type?: string; decision?: string; action?: string; severity?: Severity | string; timestamp?: number | string; created_at?: number | string; payload?: unknown; }
export interface ConfigSnapshot { version?: string; generated_at?: number | string; hosts?: unknown[]; upstreams?: unknown[]; geo_upstreams?: unknown[]; headers?: unknown; dns_records?: unknown[]; cache_rules?: unknown[]; [key: string]: unknown; }

export interface OpsDiagnostic {
  sites: number; edges: number; recentSecurityEvents: number; sslRisks: number;
  totalRequests: number; bytesIn: number; bytesOut: number; cacheHitRatio: number;
  offlineEdges: number; recentPurges: number;
}
