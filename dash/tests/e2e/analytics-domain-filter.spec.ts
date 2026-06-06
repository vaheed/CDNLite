import { expect, test } from '@playwright/test';
import { loginAsLocalAdmin } from './helpers';

test.beforeEach(async ({ page }) => {
  await loginAsLocalAdmin(page);
  await page.goto('/usage');
  await expect(page.getByRole('heading', { name: 'Usage Analytics' })).toBeVisible();
});

test('global analytics show seeded non-zero totals and cache states', async ({ page }) => {
  await expectMetric(page, 'Requests', '30');
  await expectMetric(page, 'Bytes in', '2.9 KB');
  await expectMetric(page, 'Bytes out', '29.3 KB');
  await expectMetric(page, 'Records', '6');
  await expectMetric(page, 'Cache hit ratio', '65.4%');
  await expectMetric(page, 'HIT', '17');
  await expectMetric(page, 'MISS', '9');
  await expectMetric(page, 'BYPASS', '3');
  await expectMetric(page, 'UNKNOWN', '1');
});

test('domain dropdown isolates every analytics card', async ({ page }) => {
  const domainSelect = page.getByLabel('Domain');
  await expect(domainSelect.getByRole('option', { name: 'All domains' })).toBeAttached();
  await domainSelect.selectOption({ label: 'Analytics Alpha - analytics-alpha.local' });
  await expectMetric(page, 'Requests', '20');
  await expectMetric(page, 'Bytes in', '2.0 KB');
  await expectMetric(page, 'Bytes out', '19.5 KB');
  await expectMetric(page, 'Records', '4');
  await expectMetric(page, 'Cache hit ratio', '75.0%');
  await expectMetric(page, 'HIT', '12');
  await expectMetric(page, 'MISS', '4');
  await expectMetric(page, 'BYPASS', '3');
  await expectMetric(page, 'UNKNOWN', '1');
});

test('domain analytics route remains scoped while buckets change', async ({ page }) => {
  await page.goto('/domains/22222222-2222-4222-8222-222222222227/analytics');
  await expect(page.getByRole('heading', { name: 'Analytics Beta Analytics' })).toBeVisible();
  await expect(page.getByLabel('Domain')).toHaveCount(0);
  await expectMetric(page, 'Requests', '10');
  await expectMetric(page, 'Cache hit ratio', '50.0%');
  await expectMetric(page, 'HIT', '5');
  await expectMetric(page, 'MISS', '5');
  await page.getByLabel('Bucket').selectOption('hour');
  await expectMetric(page, 'Requests', '10');
  await page.getByLabel('Bucket').selectOption('day');
  await expectMetric(page, 'Requests', '10');
});

async function expectMetric(page: import('@playwright/test').Page, label: string, value: string) {
  const card = page.locator('.card').filter({ has: page.getByText(label, { exact: true }) });
  await expect(card).toContainText(value);
}
