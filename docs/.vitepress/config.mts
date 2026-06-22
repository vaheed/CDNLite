import { defineConfig } from 'vitepress';

const base = process.env.VITEPRESS_BASE ?? '/CDNLite/';

export default defineConfig({
  title: 'CDNLite',
  description: 'Documentation for CDNLite, a self-hosted private CDN control plane and OpenResty edge platform.',
  base,
  cleanUrls: true,
  lastUpdated: true,
  ignoreDeadLinks: false,
  sitemap: {
    hostname: 'https://vaheed.github.io/CDNLite/'
  },
  srcExclude: [
    'node_modules/**',
    '.vitepress/cache/**',
    '.vitepress/dist/**',
    'ide-report.md',
    'legacy-roadmap.md'
  ],
  head: [
    ['meta', { name: 'theme-color', content: '#2563eb' }],
    ['meta', { name: 'robots', content: 'index,follow' }],
    ['meta', { name: 'keywords', content: 'self-hosted CDN, private CDN, CDN control plane, OpenResty CDN, PowerDNS CDN, GeoDNS, edge proxy, WAF, ACME, cache rules' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'CDNLite Docs' }],
    ['meta', { property: 'og:title', content: 'CDNLite Documentation' }],
    ['meta', { property: 'og:description', content: 'Self-hosted private CDN control plane documentation for OpenResty edge proxy, PowerDNS, DNSGeo, WAF rules, cache rules, SSL, and operations.' }],
    ['meta', { property: 'og:url', content: 'https://vaheed.github.io/CDNLite/' }],
    ['meta', { property: 'og:image', content: 'https://vaheed.github.io/CDNLite/ScreenShot.png' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:title', content: 'CDNLite Documentation' }],
    ['meta', { name: 'twitter:description', content: 'Self-hosted private CDN control plane documentation for operators, admins, developers, and private deployment teams.' }],
    ['meta', { name: 'twitter:image', content: 'https://vaheed.github.io/CDNLite/ScreenShot.png' }]
  ],
  themeConfig: {
    logo: '/ScreenShot.png',
    siteTitle: 'CDNLite Docs',
    nav: [
      { text: 'Guide', link: '/what-is-cdnlite' },
      { text: 'API', link: '/api/api' },
      { text: 'Examples', link: '/examples/' },
      { text: 'Security', link: '/security' },
      { text: 'Roadmap', link: '/roadmap' }
    ],
    sidebar: [
      {
        text: 'New User',
        items: [
          { text: 'Overview', link: '/' },
          { text: 'What Is CDNLite?', link: '/what-is-cdnlite' },
          { text: 'CDN In A Minute', link: '/cdn-in-a-minute' },
          { text: 'Quickstart', link: '/quickstart' },
          { text: 'First Examples', link: '/examples/' },
          { text: 'Glossary', link: '/glossary' }
        ]
      },
      {
        text: 'Operators',
        items: [
          { text: 'Deployment', link: '/deployment' },
          { text: 'Production Hardening', link: '/production-hardening' },
          { text: 'DNS And Nameservers', link: '/dns' },
          { text: 'SSL And Security', link: '/security' },
          { text: 'Troubleshooting', link: '/troubleshooting' },
          { text: 'Runbooks', link: '/runbooks/' }
        ]
      },
      {
        text: 'Developers',
        items: [
          { text: 'Architecture', link: '/architecture' },
          { text: 'API Reference', link: '/api/api' },
          { text: 'OpenAPI YAML', link: `${base}api/openapi.yaml` },
          { text: 'Local Development', link: '/setup' },
          { text: 'Extending The Edge', link: '/extensions' },
          { text: 'Dashboard Reporting', link: '/dashboard-reporting' }
        ]
      },
      {
        text: 'Private Deployment',
        items: [
          { text: 'Private CDN', link: '/private-cdn' },
          { text: 'Enterprise Readiness', link: '/enterprise-readiness' },
          { text: 'Security Model', link: '/security' },
          { text: 'Use Cases', link: '/use-cases/' },
          { text: 'Best Practices', link: '/best-practices/' },
          { text: 'Roadmap', link: '/roadmap' }
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
