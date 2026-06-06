import { describe, expect, it, vi } from 'vitest';
import { createMarkdownReport } from './markdown';
describe('Markdown reports', () => {
  it('renders the standard heading and structured data', () => {
    vi.useFakeTimers(); vi.setSystemTime(new Date('2026-06-06T12:00:00Z'));
    const report = createMarkdownReport('Overview', { requests_24h: 42, warnings: [] });
    expect(report).toContain('# CDNLite Report');
    expect(report).toContain('## Overview');
    expect(report).toContain('**Requests 24h:** 42');
    expect(report).toContain('## Warnings');
    vi.useRealTimers();
  });
});
