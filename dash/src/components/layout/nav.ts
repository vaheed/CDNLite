import { runtimeConfig } from '@/lib/config/env';

export const navItems = [
  { to: '/', label: 'OPS Dashboard' },
  { to: '/domains', label: 'Domains' },
  { to: '/dns', label: 'DNS' },
  { to: '/redirects', label: 'Redirects' },
  { to: '/page-rules', label: 'Page Rules' },
  { to: '/cache', label: 'Cache' },
  { to: '/purge', label: 'Purge' },
  { to: '/security', label: 'Security / WAF' },
  { to: '/rate-limit', label: 'Rate Limiting' },
  { to: '/ssl', label: 'SSL' },
  { to: '/edge-nodes', label: 'Edge Nodes' },
  { to: '/usage', label: 'Usage Analytics' },
  { to: '/config-snapshot', label: 'Config Snapshot' },
  { to: '/events', label: 'Event Viewer' },
  { to: '/troubleshooting', label: 'Troubleshooting' },
  { to: '/settings', label: 'Settings' },
  ...(runtimeConfig.edgeDevTools ? [{ to: '/edge-dev-tools', label: 'Edge Developer Tools' }] : []),
];
