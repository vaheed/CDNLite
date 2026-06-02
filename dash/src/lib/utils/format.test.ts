import { describe, expect, it } from 'vitest';
import { formatBytes, formatPercent } from './format';

describe('format utils', () => {
  it('formats bytes and percentages', () => {
    expect(formatBytes(1536)).toBe('1.5 KB');
    expect(formatPercent(0.456)).toBe('45.6%');
  });
});
