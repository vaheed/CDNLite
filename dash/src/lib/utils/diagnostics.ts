import { summarizeCacheAnalytics } from './cacheAnalytics';
import type { CacheAnalytics, EdgeNode, OpsDiagnostic, PurgeRequest, SecurityEvent, Domain, SslCertificate, UsageSummary } from '@/types';

export type DiagnosticStatus = 'unknown' | 'healthy' | 'warning' | 'critical' | 'info';
export type SettledHealth = PromiseSettledResult<{ ok?: boolean; ready?: boolean; error?: string }>;

export interface HealthStatusSnapshot {
  apiReachable: DiagnosticStatus;
  apiHealthy: DiagnosticStatus;
  apiReady: DiagnosticStatus;
  databaseReady: DiagnosticStatus;
  edgeReachable: DiagnosticStatus;
  overall: DiagnosticStatus;
}

export function mapHealthStatus(coreHealth: SettledHealth, coreReady: SettledHealth, edgeReady: SettledHealth): HealthStatusSnapshot {
  const apiReachable: DiagnosticStatus = coreHealth.status === 'fulfilled' ? 'healthy' : 'unknown';
  const apiHealthy: DiagnosticStatus = coreHealth.status === 'fulfilled' ? (coreHealth.value.ok ? 'healthy' : 'critical') : 'unknown';
  const apiReady: DiagnosticStatus = coreReady.status === 'fulfilled'
    ? (coreReady.value.ok || coreReady.value.ready ? 'healthy' : 'warning')
    : apiReachable === 'healthy' ? 'warning' : 'unknown';
  const databaseReady: DiagnosticStatus = coreReady.status === 'fulfilled'
    ? (coreReady.value.ok || coreReady.value.ready ? 'healthy' : 'warning')
    : 'unknown';
  const edgeReachable: DiagnosticStatus = edgeReady.status === 'fulfilled'
    ? (edgeReady.value.ok || edgeReady.value.ready ? 'healthy' : 'warning')
    : 'unknown';
  const statuses = [apiHealthy, apiReady, databaseReady, edgeReachable];
  const overall: DiagnosticStatus = statuses.includes('critical')
    ? 'critical'
    : statuses.includes('warning')
      ? 'warning'
      : statuses.every((status) => status === 'unknown')
        ? 'unknown'
        : statuses.includes('unknown')
          ? 'warning'
          : 'healthy';
  return { apiReachable, apiHealthy, apiReady, databaseReady, edgeReachable, overall };
}

export function diagnosticError(result: PromiseSettledResult<unknown>): string {
  if (result.status === 'fulfilled') return '';
  return result.reason instanceof Error ? result.reason.message : 'Request failed';
}

export function heartbeatStatus(edge: EdgeNode, nowMs = Date.now()): 'ok' | 'warning' | 'critical' {
  const value = edge.last_heartbeat_at ?? edge.last_heartbeat;
  if (!value || edge.status === 'offline' || edge.health_status === 'offline') return 'critical';
  const numeric = typeof value === 'number' ? value : Number(value);
  const heartbeatMs = Number.isFinite(numeric) && numeric < 10_000_000_000 ? numeric * 1000 : Number.isFinite(numeric) ? numeric : new Date(value).getTime();
  const ageSeconds = (nowMs - heartbeatMs) / 1000;
  if (ageSeconds <= 90) return 'ok';
  if (ageSeconds <= 300) return 'warning';
  return 'critical';
}

export function sslRisk(cert: SslCertificate): 'healthy' | 'info' | 'warning' | 'critical' {
  if (cert.status === 'expired') return 'critical';
  if (typeof cert.days_left === 'number') {
    if (cert.days_left < 0) return 'critical';
    if (cert.days_left <= 14) return 'warning';
    if (cert.days_left <= 30) return 'info';
  }
  return 'healthy';
}

export function cacheEfficiency(analytics?: CacheAnalytics | null): 'healthy' | 'warning' | 'low' | 'unknown' {
  if (!analytics || typeof analytics.hit_ratio !== 'number') return 'unknown';
  if (analytics.hit_ratio < 0.5) return 'low';
  if (analytics.hit_ratio < 0.75) return 'warning';
  return 'healthy';
}

export function buildOpsDiagnostic(input: {
  domains: Domain[];
  edges: EdgeNode[];
  usage?: UsageSummary | null;
  securityEvents?: SecurityEvent[];
  sslCertificates?: SslCertificate[];
  purges?: PurgeRequest[];
  cacheAnalytics?: CacheAnalytics[];
}): OpsDiagnostic {
  const usage = input.usage ?? {};
  const cacheTotals = summarizeCacheAnalytics(input.cacheAnalytics ?? null);
  const totalHits = cacheTotals.hit;
  const denominator = cacheTotals.hit + cacheTotals.miss + cacheTotals.expired + cacheTotals.stale;
  return {
    domains: input.domains.length,
    edges: input.edges.length,
    recentSecurityEvents: input.securityEvents?.length ?? 0,
    sslRisks: input.sslCertificates?.filter((cert) => ['critical', 'warning'].includes(sslRisk(cert))).length ?? 0,
    totalRequests: usage.requests_count ?? usage.total_requests ?? usage.requests ?? 0,
    bytesIn: usage.bytes_in ?? 0,
    bytesOut: usage.bytes_out ?? 0,
    cacheHitRatio: typeof usage.cache_hit_ratio === 'number' ? usage.cache_hit_ratio : denominator > 0 ? totalHits / denominator : 0,
    offlineEdges: input.edges.filter((edge) => heartbeatStatus(edge) === 'critical').length,
    recentPurges: input.purges?.length ?? 0,
  };
}
