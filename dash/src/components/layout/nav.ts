import { runtimeConfig } from '@/lib/config/env';

export const navItems = [
  { to: '/', label: 'OPS Dashboard' },
  { to: '/domains', label: 'Domains' },
  { to: '/edge-nodes', label: 'Edge Network' },
  { to: '/usage', label: 'Usage Analytics' },
  { to: '/dns-operations', label: 'DNS Operations' },
  { to: '/events', label: 'Event Viewer' },
  { to: '/jobs', label: 'Job Queue' },
  { to: '/security-events', label: 'Security Events' },
  { to: '/audit-log', label: 'Audit Log' },
  { to: '/settings', label: 'Settings' },
  ...(runtimeConfig.edgeDevTools ? [{ to: '/edge-dev-tools', label: 'Edge Developer Tools' }] : []),
];
