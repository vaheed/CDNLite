import { createRouter, createWebHistory } from 'vue-router';
import { runtimeConfig } from '@/lib/config/env';
import OverviewView from '@/views/OverviewView.vue';
import DomainsView from '@/views/DomainsView.vue';
import DomainDetailView from '@/views/DomainDetailView.vue';
import EdgeNodesView from '@/views/EdgeNodesView.vue';
import UsageAnalyticsView from '@/views/UsageAnalyticsView.vue';
import ConfigSnapshotView from '@/views/ConfigSnapshotView.vue';
import EventViewerView from '@/views/EventViewerView.vue';
import TroubleshootingView from '@/views/TroubleshootingView.vue';
import SettingsView from '@/views/SettingsView.vue';
import EdgeDevToolsView from '@/views/EdgeDevToolsView.vue';

export const routes = [
  { path: '/', name: 'overview', component: OverviewView, meta: { label: 'Overview' } },
  { path: '/domains', name: 'domains', component: DomainsView, meta: { label: 'Domains' } },
  { path: '/domains/:domainId/:tab?', name: 'domain-detail', component: DomainDetailView, meta: { label: 'Domain Detail' } },
  { path: '/edge-nodes', name: 'edge-nodes', component: EdgeNodesView, meta: { label: 'Edge Nodes' } },
  { path: '/usage', name: 'usage', component: UsageAnalyticsView, meta: { label: 'Usage Analytics' } },
  { path: '/config-snapshot', name: 'config-snapshot', component: ConfigSnapshotView, meta: { label: 'Config Snapshot' } },
  { path: '/events', name: 'events', component: EventViewerView, meta: { label: 'Event Viewer' } },
  { path: '/troubleshooting', name: 'troubleshooting', component: TroubleshootingView, meta: { label: 'Troubleshooting' } },
  { path: '/settings', name: 'settings', component: SettingsView, meta: { label: 'Settings' } },
  ...(runtimeConfig.edgeDevTools ? [{ path: '/edge-dev-tools', name: 'edge-dev-tools', component: EdgeDevToolsView, meta: { label: 'Edge Developer Tools' } }] : []),
];

export default createRouter({
  history: createWebHistory(),
  routes,
});
