export const queryKeys = {
  domains: () => 'domains',
  domain: (id: string) => `domain:${id}`,
  domainDns: (id: string) => `domain-dns:${id}`,
  domainOrigins: (id: string) => `domain-origins:${id}`,
  domainSsl: (id: string) => `domain-ssl:${id}`,
  domainActivity: (id: string) => `domain-activity:${id}`,
  edgeNodes: () => 'edge-nodes',
  usageSummary: () => 'usage-summary',
  auditLog: () => 'audit-log',
};

export function keysForMutation(method: string, path: string): string[] {
  if (method.toUpperCase() === 'GET') return [];
  const keys = new Set<string>();

  if (/^\/api\/v1\/domains\/?$/.test(path)) {
    keys.add(queryKeys.domains());
  }

  const domainMatch = path.match(/^\/api\/v1\/domains\/([^/?#]+)/);
  if (domainMatch) {
    const domainId = decodeURIComponent(domainMatch[1]);
    keys.add(queryKeys.domains());
    keys.add(queryKeys.domain(domainId));
    keys.add(queryKeys.domainActivity(domainId));
    if (path.includes('/nameservers/')) keys.add(queryKeys.domainDns(domainId));
    if (path.includes('/dns/')) {
      keys.add(queryKeys.domainDns(domainId));
      keys.add(queryKeys.domainOrigins(domainId));
    }
    if (path.includes('/origins')) keys.add(queryKeys.domainOrigins(domainId));
    if (path.includes('/ssl')) keys.add(queryKeys.domainSsl(domainId));
    if (path.includes('/cache/purge') || path.includes('/analytics')) keys.add(queryKeys.usageSummary());
  }

  if (path.includes('/edge/') || path.includes('/edges/')) keys.add(queryKeys.edgeNodes());
  if (path.includes('/audit')) keys.add(queryKeys.auditLog());
  return [...keys];
}
