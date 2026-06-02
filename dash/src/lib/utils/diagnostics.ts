import type { CacheAnalytics, EdgeNode, OpsDiagnostic, PurgeRequest, SecurityEvent, Site, SslCertificate, UsageSummary } from '@/types';

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
  sites: Site[];
  edges: EdgeNode[];
  usage?: UsageSummary | null;
  securityEvents?: SecurityEvent[];
  sslCertificates?: SslCertificate[];
  purges?: PurgeRequest[];
  cacheAnalytics?: CacheAnalytics[];
}): OpsDiagnostic {
  const usage = input.usage ?? {};
  const totalHits = input.cacheAnalytics?.reduce((sum, item) => sum + (item.hit ?? 0), 0) ?? 0;
  const totalCache = input.cacheAnalytics?.reduce((sum, item) => sum + (item.hit ?? 0) + (item.miss ?? 0) + (item.bypass ?? 0) + (item.stale ?? 0), 0) ?? 0;
  return {
    sites: input.sites.length,
    edges: input.edges.length,
    recentSecurityEvents: input.securityEvents?.length ?? 0,
    sslRisks: input.sslCertificates?.filter((cert) => ['critical', 'warning'].includes(sslRisk(cert))).length ?? 0,
    totalRequests: usage.total_requests ?? usage.requests ?? 0,
    bytesIn: usage.bytes_in ?? 0,
    bytesOut: usage.bytes_out ?? 0,
    cacheHitRatio: typeof usage.cache_hit_ratio === 'number' ? usage.cache_hit_ratio : totalCache > 0 ? totalHits / totalCache : 0,
    offlineEdges: input.edges.filter((edge) => heartbeatStatus(edge) === 'critical').length,
    recentPurges: input.purges?.length ?? 0,
  };
}
