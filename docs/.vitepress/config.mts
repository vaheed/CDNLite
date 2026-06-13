import { defineConfig } from 'vitepress';

const base = process.env.VITEPRESS_BASE ?? '/CDNLite/';

export default defineConfig({
  title: 'CDNLite',
  description: 'Documentation for the CDNLite control plane, dashboard, edge runtime, and operations workflows.',
  base,
  cleanUrls: true,
  lastUpdated: true,
  ignoreDeadLinks: false,
  srcExclude: ['node_modules/**', '.vitepress/cache/**', '.vitepress/dist/**'],
  head: [
    ['meta', { name: 'theme-color', content: '#2563eb' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'CDNLite Documentation' }]
  ],
  themeConfig: {
    logo: '/ScreenShot.png',
    siteTitle: 'CDNLite Docs',
    nav: [
      { text: 'Guide', link: '/setup' },
      { text: 'API', link: '/api/api' },
      { text: 'Examples', link: '/examples/' },
      { text: 'Security', link: '/security' }
    ],
    sidebar: [
      {
        text: 'Start',
        items: [
          { text: 'Overview', link: '/' },
          { text: 'Setup', link: '/setup' },
          { text: 'Production Deployment', link: '/deployment' },
          { text: 'Architecture', link: '/architecture' },
          { text: 'DNS Stress Testing', link: '/stress-testing' },
          { text: 'Extensions', link: '/extensions' }
        ]
      },
      {
        text: 'Usage',
        items: [
          { text: 'User Guide', link: '/usage/user' },
          { text: 'Admin Guide', link: '/usage/admin' },
          { text: 'API Reference', link: '/api/api' },
          { text: 'OpenAPI YAML', link: `${base}api/openapi.yaml` }
        ]
      },
      {
        text: 'Operations',
        items: [
          { text: 'Troubleshooting', link: '/troubleshooting' },
          { text: 'Security', link: '/security' },
          { text: 'Operations Runbooks', link: '/runbooks/' },
          { text: 'Examples', link: '/examples/' },
          { text: 'Use Cases', link: '/use-cases/' },
          { text: 'Best Practices', link: '/best-practices/' }
        ]
      }
    ],
    socialLinks: [
      { icon: 'github', link: 'https://github.com/vaheed/CDNLite' }
    ],
    search: {
      provider: 'local'
    },
    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright (c) 2026 CDNLite contributors'
    }
  }
});
