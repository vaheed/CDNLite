import type { CacheAnalytics, CacheAnalyticsRow } from '@/types';

const CACHE_STATUS_ORDER = ['HIT', 'MISS', 'EXPIRED', 'STALE', 'BYPASS', 'UNKNOWN'] as const;

export interface CacheAnalyticsTotals {
  rows: CacheAnalyticsRow[];
  hit: number;
  miss: number;
  expired: number;
  stale: number;
  bypass: number;
  unknown: number;
  totalRequests: number;
  bytesOut: number;
  hitRatio: number;
}

function normalizeStatus(value: string): string {
  const normalized = value.trim().toUpperCase();
  return normalized === '' ? 'UNKNOWN' : normalized;
}

function rowsFrom(input?: CacheAnalytics | CacheAnalytics[] | null): CacheAnalyticsRow[] {
  if (!input) return [];
  if (Array.isArray(input)) {
    return input.flatMap((item) => item.rows ?? []);
  }
  return input.rows ?? [];
}

export function summarizeCacheAnalytics(input?: CacheAnalytics | CacheAnalytics[] | null): CacheAnalyticsTotals {
  const rows = rowsFrom(input);
  const bucket = new Map<string, CacheAnalyticsRow>();

  for (const row of rows) {
    const status = normalizeStatus(String(row.cache_status ?? 'UNKNOWN'));
    const current = bucket.get(status) ?? { cache_status: status, count: 0, bytes_out: 0 };
    current.count += Number(row.count ?? 0);
    current.bytes_out += Number(row.bytes_out ?? 0);
    bucket.set(status, current);
  }

  const orderedRows: CacheAnalyticsRow[] = [];
  for (const status of CACHE_STATUS_ORDER) {
    orderedRows.push(bucket.get(status) ?? { cache_status: status, count: 0, bytes_out: 0 });
    bucket.delete(status);
  }
  for (const row of bucket.values()) {
    orderedRows.push(row);
  }

  const totals = orderedRows.reduce(
    (acc, row) => {
      acc.totalRequests += row.count;
      acc.bytesOut += row.bytes_out;
      switch (normalizeStatus(row.cache_status)) {
        case 'HIT':
          acc.hit += row.count;
          break;
        case 'MISS':
          acc.miss += row.count;
          break;
        case 'EXPIRED':
          acc.expired += row.count;
          break;
        case 'STALE':
          acc.stale += row.count;
          break;
        case 'BYPASS':
          acc.bypass += row.count;
          break;
        default:
          acc.unknown += row.count;
          break;
      }
      return acc;
    },
    { hit: 0, miss: 0, expired: 0, stale: 0, bypass: 0, unknown: 0, totalRequests: 0, bytesOut: 0 },
  );

  const denominator = totals.hit + totals.miss + totals.expired + totals.stale;
  return {
    rows: orderedRows,
    ...totals,
    hitRatio: denominator > 0 ? totals.hit / denominator : 0,
  };
}

export function cacheAnalyticsChartData(input?: CacheAnalytics | CacheAnalytics[] | null): Array<{ name: string; value: number }> {
  const summary = summarizeCacheAnalytics(input);
  return summary.rows.map((row) => ({ name: row.cache_status, value: row.count }));
}
