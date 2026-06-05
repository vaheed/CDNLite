import { describe, expect, it } from 'vitest';
import { summarizeCacheAnalytics } from './cacheAnalytics';

describe('cache analytics', () => {
  it('summarizes cache rows and ignores bypass traffic in hit ratio', () => {
    const summary = summarizeCacheAnalytics({
      rows: [
        { cache_status: 'HIT', count: 7, bytes_out: 70 },
        { cache_status: 'MISS', count: 3, bytes_out: 30 },
        { cache_status: 'BYPASS', count: 2, bytes_out: 20 },
        { cache_status: 'UNKNOWN', count: 1, bytes_out: 10 },
      ],
      hit_ratio: 0,
    });

    expect(summary.hit).toBe(7);
    expect(summary.miss).toBe(3);
    expect(summary.bypass).toBe(2);
    expect(summary.unknown).toBe(1);
    expect(summary.hitRatio).toBeCloseTo(0.7);
    expect(summary.rows.map((row) => row.cache_status)).toEqual(['HIT', 'MISS', 'EXPIRED', 'STALE', 'BYPASS', 'UNKNOWN']);
  });
});
