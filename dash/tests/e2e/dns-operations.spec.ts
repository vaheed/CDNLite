import { expect, test } from '@playwright/test';
import { loginAsLocalAdmin } from './helpers';

test('DNS Operations exposes readiness, desired records, and operator actions', async ({ page }) => {
  await loginAsLocalAdmin(page);
  await page.goto('/dns-operations');

  await expect(page.getByRole('heading', { name: 'DNS Operations' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'PowerDNS setup' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'DNSGeo readiness' })).toBeVisible();
  await expect(page.getByText('Apex proxy mode')).toBeVisible();
  await expect(page.getByText('ALIAS', { exact: true })).toBeVisible();
  await expect(page.getByText('ALIAS expansion')).toBeVisible();

  await page.getByRole('button', { name: 'Dry run' }).click();
  await expect(page.getByText(/Dry run: \d+ desired RRsets across \d+ zones\./)).toBeVisible();

  await page.getByRole('button', { name: 'Force sync now' }).click();
  await expect(page.getByText(/Sync (complete|failed):/)).toBeVisible();
});

test('domain DNS UI shows ALIAS, CNAME, and DNS-only effective behavior', async ({ page }) => {
  await loginAsLocalAdmin(page);
  await page.goto('/domains/11111111-1111-4111-8111-111111111117/dns');

  await expect(page.getByRole('heading', { name: 'Analytics Alpha' })).toBeVisible();
  await expect(page.getByText('ALIAS site-11111111-1111-4111-8111-111111111117.cdn.example.net.')).toBeVisible();
  await expect(page.getByText('CNAME site-11111111-1111-4111-8111-111111111117.cdn.example.net.')).toBeVisible();
  await expect(page.getByText('A 192.0.2.88')).toBeVisible();
  await expect(page.getByText('Proxied', { exact: true })).toHaveCount(2);
  await expect(page.getByText('DNS only', { exact: true })).toHaveCount(1);
});
