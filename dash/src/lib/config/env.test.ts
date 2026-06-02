import { describe, expect, it } from 'vitest';
import { parseRuntimeConfig } from './env';

describe('parseRuntimeConfig', () => {
  it('parses booleans, numbers, and urls', () => {
    const cfg = parseRuntimeConfig({ VITE_CDNLITE_CORE_URL: 'http://localhost:8080/', VITE_CDNLITE_EDGE_URL: 'http://localhost:8081', VITE_CDNLITE_APP_NAME: 'CDNLite Admin', VITE_ENABLE_EDGE_DEV_TOOLS: 'true', VITE_ENABLE_USAGE_SIMULATOR: 'false', VITE_ENABLE_SSL_TOOLS: 'true', VITE_ENABLE_SECURITY_EVENT_VIEWER: 'true', VITE_ENABLE_LOG_VIEWER: 'true', VITE_DEFAULT_USAGE_BUCKET: 'hour', VITE_DASHBOARD_REFRESH_SECONDS: '30', VITE_REQUEST_TIMEOUT_MS: '15000' });
    expect(cfg.coreUrl).toBe('http://localhost:8080');
    expect(cfg.edgeDevTools).toBe(true);
    expect(cfg.defaultUsageBucket).toBe('hour');
  });
});
