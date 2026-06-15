import { createRouter, createWebHistory } from 'vue-router';
import { runtimeConfig } from '@/lib/config/env';
import OverviewView from '@/views/OverviewView.vue';
import DomainsView from '@/views/DomainsView.vue';
import DomainDetailView from '@/views/DomainDetailView.vue';
import EdgeNetworkView from '@/views/EdgeNetworkView.vue';
import UsageAnalyticsView from '@/views/UsageAnalyticsView.vue';
import DnsOperationsView from '@/views/DnsOperationsView.vue';
import EventViewerView from '@/views/EventViewerView.vue';
import JobQueueView from '@/views/JobQueueView.vue';
import SettingsView from '@/views/SettingsView.vue';
import EdgeDevToolsView from '@/views/EdgeDevToolsView.vue';
import SecurityEventsView from '@/views/SecurityEventsView.vue';
import AuditLogView from '@/views/AuditLogView.vue';

export const routes = [
  { path: '/', name: 'overview', component: OverviewView, meta: { label: 'Overview' } },
  { path: '/domains', name: 'domains', component: DomainsView, meta: { label: 'Domains' } },
  { path: '/domains/:domainId/:tab?', name: 'domain-detail', component: DomainDetailView, meta: { label: 'Domain Detail' } },
  { path: '/edge-nodes', name: 'edge-nodes', component: EdgeNetworkView, meta: { label: 'Edge Network' } },
  { path: '/usage', name: 'usage', component: UsageAnalyticsView, meta: { label: 'Usage Analytics' } },
  { path: '/dns-operations', name: 'dns-operations', component: DnsOperationsView, meta: { label: 'DNS Operations' } },
  { path: '/events', name: 'events', component: EventViewerView, meta: { label: 'Event Viewer' } },
  { path: '/jobs', name: 'jobs', component: JobQueueView, meta: { label: 'Job Queue' } },
  { path: '/security-events', name: 'security-events', component: SecurityEventsView, meta: { label: 'Security Events' } },
  { path: '/audit-log', name: 'audit-log', component: AuditLogView, meta: { label: 'Audit Log' } },
  { path: '/settings', name: 'settings', component: SettingsView, meta: { label: 'Settings' } },
  ...(runtimeConfig.edgeDevTools ? [{ path: '/edge-dev-tools', name: 'edge-dev-tools', component: EdgeDevToolsView, meta: { label: 'Edge Developer Tools' } }] : []),
];

export default createRouter({
  history: createWebHistory(),
  routes,
});
