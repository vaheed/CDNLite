import { createRouter, createWebHistory } from 'vue-router';
import { runtimeConfig } from '@/lib/config/env';
import OpsDashboard from '@/views/OpsDashboard.vue';
import SitesView from '@/views/SitesView.vue';
import SiteFeatureView from '@/views/SiteFeatureView.vue';
import EdgeNodesView from '@/views/EdgeNodesView.vue';
import UsageAnalyticsView from '@/views/UsageAnalyticsView.vue';
import ConfigSnapshotView from '@/views/ConfigSnapshotView.vue';
import EventViewerView from '@/views/EventViewerView.vue';
import TroubleshootingView from '@/views/TroubleshootingView.vue';
import SettingsView from '@/views/SettingsView.vue';
import EdgeDevToolsView from '@/views/EdgeDevToolsView.vue';
import { featurePages } from '@/views/featurePages';

export const routes = [
  { path: '/', name: 'ops', component: OpsDashboard, meta: { label: 'OPS Dashboard' } },
  { path: '/sites', name: 'sites', component: SitesView, meta: { label: 'Sites' } },
  ...featurePages.map((feature) => ({ path: feature.path, name: feature.key, component: SiteFeatureView, props: { feature }, meta: { label: feature.title } })),
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
