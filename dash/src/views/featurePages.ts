export type FeatureKey = 'dns' | 'redirects' | 'page-rules' | 'cache' | 'purge' | 'security' | 'rate-limit' | 'ssl';

export type FeaturePage = {
  key: FeatureKey;
  path: string;
  title: string;
  subtitle: string;
  endpointSummary: string[];
  columns?: Array<{ key: string; label: string }>;
  fields: Array<{ name: string; label: string; what: string; works: string; example: string; required?: boolean; type?: 'text' | 'number' | 'select' | 'textarea' | 'checkbox'; options?: Array<{ label: string; value: string }> }>;
};

export const featurePages: FeaturePage[] = [
  {
    key: 'dns', path: '/dns', title: 'DNS', subtitle: 'Manage site-scoped DNS records and proxy transformations.',
    endpointSummary: ['GET/POST /api/v1/sites/{id}/dns/records', 'PATCH/DELETE /api/v1/sites/{id}/dns/records/{recordId}'],
    columns: [{ key: 'name', label: 'Name' }, { key: 'type', label: 'Origin type' }, { key: 'content', label: 'Origin content' }, { key: 'public_type', label: 'Public type' }, { key: 'public_content', label: 'Public content' }, { key: 'ttl', label: 'TTL' }, { key: 'proxied', label: 'Proxied' }, { key: 'status', label: 'Status' }, { key: 'actions', label: 'Actions' }],
    fields: [
      { name: 'type', label: 'Type', what: 'DNS record type.', works: 'A/AAAA/CNAME/etc. define how hostnames resolve.', example: 'A', required: true, type: 'select', options: [{ label: 'A', value: 'A' }, { label: 'AAAA', value: 'AAAA' }, { label: 'CNAME', value: 'CNAME' }, { label: 'TXT', value: 'TXT' }, { label: 'MX', value: 'MX' }] },
      { name: 'name', label: 'Name', what: 'DNS name relative to the site domain.', works: '@ means the root domain.', example: '@ or www', required: true },
      { name: 'content', label: 'Content', what: 'Origin DNS target before CDN proxy transformation.', works: 'If proxied, CDNLite may publish edge-facing DNS content.', example: '192.0.2.10', required: true },
      { name: 'ttl', label: 'TTL', what: 'DNS cache lifetime in seconds.', works: 'Lower values update faster; higher values reduce DNS load.', example: '300', type: 'number' },
      { name: 'proxied', label: 'Proxied', what: 'Whether traffic goes through the CDN edge.', works: 'If true, public DNS points to the edge target instead of origin.', example: 'true', type: 'checkbox' },
    ],
  },
  {
    key: 'redirects', path: '/redirects', title: 'Redirects', subtitle: 'Create, test, import, and export redirect rules.',
    endpointSummary: ['GET/POST /api/v1/sites/{id}/redirects', 'PATCH/DELETE /api/v1/sites/{id}/redirects/{redirectId}', 'POST /import', 'GET /export', 'POST /test'],
    columns: [{ key: 'enabled', label: 'Enabled' }, { key: 'source_path', label: 'Source' }, { key: 'target_url', label: 'Target' }, { key: 'status_code', label: 'Status' }, { key: 'match_type', label: 'Match' }, { key: 'preserve_query', label: 'Query' }, { key: 'priority', label: 'Priority' }, { key: 'actions', label: 'Actions' }],
    fields: [
      { name: 'enabled', label: 'Enabled', what: 'Whether this rule is active.', works: 'Disabled rules remain saved but do not affect traffic.', example: 'true', type: 'checkbox' },
      { name: 'source_path', label: 'Source path', what: 'Incoming path to match.', works: 'Must start with /.', example: '/old-page', required: true },
      { name: 'target_url', label: 'Target URL', what: 'Destination URL.', works: 'The user is redirected here when the rule matches.', example: 'https://example.com/new-page', required: true },
      { name: 'status_code', label: 'Status code', what: 'HTTP redirect status.', works: '301/308 are permanent; 302/307 are temporary.', example: '308', type: 'select', options: [{ label: '301 Permanent', value: '301' }, { label: '302 Temporary', value: '302' }, { label: '307 Temporary', value: '307' }, { label: '308 Permanent', value: '308' }] },
      { name: 'match_type', label: 'Match type', what: 'How the source path is matched.', works: 'Exact matches one path; prefix matches a path family; wildcard allows simple * patterns.', example: 'exact_path', type: 'select', options: [{ label: 'Exact path', value: 'exact_path' }, { label: 'Prefix', value: 'prefix' }, { label: 'Wildcard', value: 'wildcard_simple' }] },
      { name: 'preserve_query', label: 'Preserve query', what: 'Whether the original query string is appended to the target.', works: 'Keep this on for most page redirects.', example: 'true', type: 'checkbox' },
      { name: 'priority', label: 'Priority', what: 'Ordering value.', works: 'Lower priority can run before less specific rules depending on implementation.', example: '10', type: 'number' },
    ],
  },
  {
    key: 'page-rules', path: '/page-rules', title: 'Page Rules', subtitle: 'Route, cache, and behavior rules by path/pattern.',
    endpointSummary: ['GET/POST /api/v1/sites/{id}/page-rules', 'PATCH/DELETE /api/v1/sites/{id}/page-rules/{ruleId}', 'POST /test'],
    columns: [{ key: 'enabled', label: 'Enabled' }, { key: 'pattern', label: 'Pattern' }, { key: 'priority', label: 'Priority' }, { key: 'actions', label: 'Actions' }],
    fields: [
      { name: 'enabled', label: 'Enabled', what: 'Whether the rule is active.', works: 'Only enabled rules are evaluated by the edge.', example: 'true', type: 'checkbox' },
      { name: 'pattern', label: 'Pattern', what: 'URL or path pattern to match.', works: 'Use this to target a subset of requests.', example: '/assets/*', required: true },
      { name: 'priority', label: 'Priority', what: 'Evaluation order.', works: 'More specific rules should usually run first.', example: '20', type: 'number' },
      { name: 'actions', label: 'Actions JSON', what: 'Operations to apply when matched.', works: 'The edge reads this config from the generated snapshot.', example: '{ "cache_ttl": 3600 }', type: 'textarea' },
    ],
  },
  {
    key: 'cache', path: '/cache', title: 'Cache', subtitle: 'Tune cache settings, TTLs, and cache rules.',
    endpointSummary: ['GET/PUT /api/v1/sites/{id}/cache/settings', 'GET/POST /api/v1/sites/{id}/cache-rules', 'GET /analytics/cache'],
    columns: [{ key: 'kind', label: 'Kind' }, { key: 'enabled', label: 'Enabled' }, { key: 'path_prefix', label: 'Path prefix' }, { key: 'ttl_seconds', label: 'TTL' }, { key: 'cache_query_string_mode', label: 'Query mode' }, { key: 'hit_ratio', label: 'Hit ratio' }, { key: 'actions', label: 'Actions' }],
    fields: [
      { name: 'enabled', label: 'Enabled', what: 'Whether edge caching is active.', works: 'When off, the edge proxies more traffic to origin.', example: 'true', type: 'checkbox' },
      { name: 'default_edge_ttl_seconds', label: 'Default edge TTL', what: 'How long edge keeps cached content.', works: 'Higher TTL improves speed but may delay content updates.', example: '3600', type: 'number' },
      { name: 'default_browser_ttl_seconds', label: 'Default browser TTL', what: 'How long browsers cache content.', works: 'Controls client-side freshness.', example: '300', type: 'number' },
      { name: 'cache_query_string_mode', label: 'Query string mode', what: 'Controls whether query strings affect cache keys.', works: 'ignore_all improves hit ratio but can serve wrong content if query changes responses.', example: 'include_all', type: 'select', options: [{ label: 'Include all', value: 'include_all' }, { label: 'Ignore all', value: 'ignore_all' }, { label: 'Include allowlist', value: 'include_allowlist' }] },
      { name: 'respect_origin_cache_control', label: 'Respect origin cache control', what: 'Whether origin Cache-Control can influence edge TTLs.', works: 'Turn on when origin headers are authoritative.', example: 'true', type: 'checkbox' },
      { name: 'cache_authorized_requests', label: 'Cache authorized requests', what: 'Whether requests with Authorization headers may be cached.', works: 'Usually off unless responses are safely public.', example: 'false', type: 'checkbox' },
      { name: 'stale_if_error_seconds', label: 'Stale if error', what: 'Grace period for serving stale content.', works: 'Can shield users during origin errors.', example: '86400', type: 'number' },
      { name: 'path_prefix', label: 'Cache rule path prefix', what: 'Path prefix for a path-specific cache rule.', works: 'Use Create cache rule for this field.', example: '/assets/' },
      { name: 'ttl_seconds', label: 'Cache rule TTL', what: 'TTL for the path-specific cache rule.', works: 'Use Create cache rule for this field.', example: '3600', type: 'number' },
    ],
  },
  {
    key: 'purge', path: '/purge', title: 'Purge Cache', subtitle: 'Choose a purge scope and invalidate cached content for the selected site.',
    endpointSummary: ['POST /api/v1/sites/{id}/cache/purge', 'GET /api/v1/sites/{id}/cache/purge-requests'],
    columns: [{ key: 'type', label: 'Type' }, { key: 'value', label: 'Value' }, { key: 'status', label: 'Status' }, { key: 'edge_seen_count', label: 'Edges seen' }, { key: 'error', label: 'Error' }, { key: 'created_at', label: 'Created' }, { key: 'completed_at', label: 'Completed' }, { key: 'actions', label: 'Actions' }],
    fields: [
      { name: 'type', label: 'Purge scope', what: 'Required. Choose what cached content to invalidate.', works: 'URL purges one exact URL; prefix purges matching paths; site purges the selected site; everything purges all cached content for the selected site.', example: 'prefix', required: true, type: 'select', options: [{ label: 'URL - one exact URL', value: 'url' }, { label: 'Prefix - paths starting with value', value: 'prefix' }, { label: 'Site - selected site', value: 'site' }, { label: 'Everything - all site cache', value: 'everything' }] },
      { name: 'value', label: 'URL or prefix', what: 'Required only when purge scope is URL or Prefix.', works: 'Use an exact URL/path for URL purge, or a path prefix such as /assets/ for Prefix purge. Leave empty for Site or Everything.', example: '/assets/' },
    ],
  },
  {
    key: 'security', path: '/security', title: 'Security / WAF', subtitle: 'Manage WAF rules and inspect recent security events.',
    endpointSummary: ['GET/POST /api/v1/sites/{id}/waf-rules', 'PATCH/DELETE /api/v1/sites/{id}/waf-rules/{wafId}', 'GET /security/events'],
    columns: [{ key: 'enabled', label: 'Enabled' }, { key: 'name', label: 'Name' }, { key: 'type', label: 'Type' }, { key: 'pattern', label: 'Pattern' }, { key: 'action', label: 'Action' }, { key: 'priority', label: 'Priority' }, { key: 'description', label: 'Description' }, { key: 'actions', label: 'Actions' }],
    fields: [
      { name: 'enabled', label: 'Enabled', what: 'Whether this WAF rule is active.', works: 'Disabled rules remain saved but are not evaluated.', example: 'true', type: 'checkbox' },
      { name: 'name', label: 'Name', what: 'Optional rule label.', works: 'Use it to identify the rule in tables and exports.', example: 'Block curl' },
      { name: 'type', label: 'Type', what: 'What part of the request to inspect.', works: 'Example: path_prefix checks URL path prefix.', example: 'user_agent_contains', required: true, type: 'select', options: [{ label: 'Path contains', value: 'path_contains' }, { label: 'Path prefix', value: 'path_prefix' }, { label: 'User agent contains', value: 'user_agent_contains' }, { label: 'IP CIDR', value: 'ip_cidr' }, { label: 'Country is', value: 'country_is' }, { label: 'Method is', value: 'method_is' }, { label: 'Header contains', value: 'header_contains' }] },
      { name: 'pattern', label: 'Pattern', what: 'Value to match.', works: 'Match behavior depends on selected type.', example: 'curl', required: true },
      { name: 'action', label: 'Action', what: 'What edge should do when matched.', works: 'block denies, log records, allow permits.', example: 'block', required: true, type: 'select', options: [{ label: 'Block', value: 'block' }, { label: 'Log', value: 'log' }, { label: 'Allow', value: 'allow' }] },
      { name: 'priority', label: 'Priority', what: 'Evaluation order.', works: 'Use priorities to place allow/block rules in predictable order.', example: '10', type: 'number' },
      { name: 'description', label: 'Description', what: 'Optional operator note.', works: 'Useful for explaining why this rule exists.', example: 'Blocks scripted clients', type: 'textarea' },
    ],
  },
  {
    key: 'rate-limit', path: '/rate-limit', title: 'Rate Limiting', subtitle: 'Configure per-site request limiting.',
    endpointSummary: ['GET/PUT/DELETE /api/v1/sites/{id}/rate-limit'],
    fields: [
      { name: 'enabled', label: 'Enabled', what: 'Whether the rate limit is actively enforced.', works: 'Save with enabled on to enforce. Use Disable rate limit to remove the active rule.', example: 'true', type: 'checkbox' },
      { name: 'requests_per_minute', label: 'Requests per minute', what: 'Maximum requests allowed per minute.', works: 'Requests above this threshold are blocked.', example: '120', required: true, type: 'number' },
      { name: 'priority', label: 'Priority', what: 'Evaluation order when multiple limits exist in generated config.', works: 'Lower numbers can be evaluated earlier by the edge snapshot.', example: '100', type: 'number' },
      { name: 'key_type', label: 'Key type', what: 'How clients are grouped for limiting.', works: 'ip limits by IP; ip_path limits by IP + path.', example: 'ip_path', type: 'select', options: [{ label: 'IP', value: 'ip' }, { label: 'IP + path', value: 'ip_path' }] },
      { name: 'path_prefix', label: 'Path prefix', what: 'Path scope for the limiter.', works: 'Use / to apply site-wide, or a prefix for hot paths.', example: '/api/' },
      { name: 'action', label: 'Action', what: 'Decision when the limit is exceeded.', works: 'The current API supports block.', example: 'block', type: 'select', options: [{ label: 'Block', value: 'block' }] },
    ],
  },
  {
    key: 'ssl', path: '/ssl', title: 'SSL', subtitle: 'Inspect certificates, run SSL checks, and import manual PEM material.',
    endpointSummary: ['GET /api/v1/sites/{id}/ssl/certificates', 'POST /ssl/check', 'POST /ssl/manual-certificate'],
    columns: [{ key: 'hostname', label: 'Hostname' }, { key: 'provider', label: 'Provider' }, { key: 'status', label: 'Status' }, { key: 'issuer', label: 'Issuer' }, { key: 'serial_number', label: 'Serial' }, { key: 'not_before', label: 'Valid from' }, { key: 'not_after', label: 'Expires' }, { key: 'days_until_expiry', label: 'Days left' }, { key: 'last_checked_at', label: 'Checked' }, { key: 'last_error', label: 'Error' }],
    fields: [
      { name: 'hostname', label: 'Hostname', what: 'Hostname the certificate secures.', works: 'Edge uses SNI to select certificate.', example: 'example.com', required: true },
      { name: 'certificate_pem', label: 'Certificate PEM', what: 'Public certificate chain in PEM format.', works: 'Sent to browsers during TLS handshake.', example: '-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----', type: 'textarea' },
      { name: 'private_key_pem', label: 'Private key PEM', what: 'Private key matching the certificate.', works: 'Required for TLS; must be kept secret and never logged.', example: '-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----', type: 'textarea' },
    ],
  },
];
