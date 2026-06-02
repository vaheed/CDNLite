export function formatBytes(bytes = 0): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  return `${(bytes / 1024 ** index).toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
}

export function formatPercent(value = 0): string {
  const pct = value <= 1 ? value * 100 : value;
  return `${pct.toFixed(1)}%`;
}

export function formatDate(value?: string | number | null): string {
  if (value === undefined || value === null || value === '') return 'never';
  const numeric = typeof value === 'number' ? value : Number(value);
  const date = Number.isFinite(numeric) && numeric > 10_000_000_000 ? new Date(numeric) : Number.isFinite(numeric) ? new Date(numeric * 1000) : new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

export function timeAgo(value?: string | number | null, nowMs = Date.now()): string {
  if (!value) return 'never';
  const numeric = typeof value === 'number' ? value : Number(value);
  const dateMs = Number.isFinite(numeric) && numeric < 10_000_000_000 ? numeric * 1000 : Number.isFinite(numeric) ? numeric : new Date(value).getTime();
  const seconds = Math.max(0, Math.round((nowMs - dateMs) / 1000));
  if (seconds < 60) return `${seconds}s ago`;
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 48) return `${hours}h ago`;
  return `${Math.round(hours / 24)}d ago`;
}
