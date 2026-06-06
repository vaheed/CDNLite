const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
const scalar = (value: unknown) => value == null || value === '' ? 'Not available' : typeof value === 'object' ? `\`${JSON.stringify(value)}\`` : String(value);
function render(value: unknown, depth = 2): string[] {
  if (Array.isArray(value)) return value.length ? value.flatMap((item) => item && typeof item === 'object'
    ? Object.entries(item).map(([key, nested]) => `- **${label(key)}:** ${scalar(nested)}`) : [`- ${scalar(item)}`]) : ['- None'];
  if (value && typeof value === 'object') return Object.entries(value).flatMap(([key, nested]) => nested && typeof nested === 'object'
    ? [`${'#'.repeat(depth)} ${label(key)}`, '', ...render(nested, depth + 1), ''] : [`- **${label(key)}:** ${scalar(nested)}`]);
  return [scalar(value)];
}
export function createMarkdownReport(title: string, data: Record<string, unknown>): string {
  return ['# CDNLite Report', '', `## ${title}`, '', `Generated: ${new Date().toISOString()}`, '', ...render(data)].join('\n').trim() + '\n';
}
