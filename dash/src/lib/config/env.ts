import { z } from 'zod';
import type { UsageBucket } from '@/types';

const boolFromString = z.preprocess((value) => {
  if (typeof value === 'boolean') return value;
  if (typeof value !== 'string') return value;
  return ['true', '1', 'yes', 'on'].includes(value.toLowerCase());
}, z.boolean());

const numberFromString = z.preprocess((value) => {
  if (typeof value === 'number') return value;
  if (typeof value === 'string' && value.trim() !== '') return Number(value);
  return value;
}, z.number().int().positive());

export const envSchema = z.object({
  VITE_CDNLITE_CORE_URL: z.string().url(),
  VITE_CDNLITE_EDGE_URL: z.string().url(),
  VITE_CDNLITE_APP_NAME: z.string().min(1),
  VITE_CDNLITE_API_TOKEN: z.string().optional().default(''),
  VITE_ENABLE_EDGE_DEV_TOOLS: boolFromString.default(false),
  VITE_ENABLE_USAGE_SIMULATOR: boolFromString.default(false),
  VITE_ENABLE_SSL_TOOLS: boolFromString.default(true),
  VITE_ENABLE_SECURITY_EVENT_VIEWER: boolFromString.default(true),
  VITE_ENABLE_LOG_VIEWER: boolFromString.default(true),
  VITE_DEFAULT_USAGE_BUCKET: z.enum(['minute', 'hour', 'day']).default('minute'),
  VITE_DASHBOARD_REFRESH_SECONDS: numberFromString.default(15),
  VITE_REQUEST_TIMEOUT_MS: numberFromString.default(15000),
});

export type RuntimeConfig = {
  coreUrl: string;
  edgeUrl: string;
  appName: string;
  apiToken: string;
  edgeDevTools: boolean;
  usageSimulator: boolean;
  sslTools: boolean;
  securityEventViewer: boolean;
  logViewer: boolean;
  defaultUsageBucket: UsageBucket;
  dashboardRefreshSeconds: number;
  requestTimeoutMs: number;
};

export function parseRuntimeConfig(source: Record<string, unknown>): RuntimeConfig {
  const parsed = envSchema.safeParse(source);
  if (!parsed.success) {
    const message = parsed.error.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`).join('; ');
    throw new Error(`Invalid CDNLite dashboard environment: ${message}`);
  }
  const env = parsed.data;
  return {
    coreUrl: env.VITE_CDNLITE_CORE_URL.replace(/\/$/, ''),
    edgeUrl: env.VITE_CDNLITE_EDGE_URL.replace(/\/$/, ''),
    appName: env.VITE_CDNLITE_APP_NAME,
    apiToken: env.VITE_CDNLITE_API_TOKEN,
    edgeDevTools: env.VITE_ENABLE_EDGE_DEV_TOOLS,
    usageSimulator: env.VITE_ENABLE_USAGE_SIMULATOR,
    sslTools: env.VITE_ENABLE_SSL_TOOLS,
    securityEventViewer: env.VITE_ENABLE_SECURITY_EVENT_VIEWER,
    logViewer: env.VITE_ENABLE_LOG_VIEWER,
    defaultUsageBucket: env.VITE_DEFAULT_USAGE_BUCKET,
    dashboardRefreshSeconds: env.VITE_DASHBOARD_REFRESH_SECONDS,
    requestTimeoutMs: env.VITE_REQUEST_TIMEOUT_MS,
  };
}

export const runtimeConfig = parseRuntimeConfig(import.meta.env);
export const hasApiToken = () => runtimeConfig.apiToken.trim().length > 0;
