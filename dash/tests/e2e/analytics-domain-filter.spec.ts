import { expect, test } from '@playwright/test';

test('analytics can be filtered globally and opened for one domain', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Username', { exact: true }).fill('admin');
  await page.getByLabel('Password', { exact: true }).fill('admin');
  await page.getByRole('button', { name: 'Sign in' }).click();

  await page.goto('/usage');
  await expect(page.getByRole('heading', { name: 'Usage Analytics' })).toBeVisible();
  const domainSelect = page.getByLabel('Domain');
  await expect(domainSelect.getByRole('option', { name: 'All domains' })).toBeAttached();
  const domainOptions = domainSelect.locator('option');
  test.skip(await domainOptions.count() < 2, 'No domain is available for analytics filtering.');
  await domainSelect.selectOption({ index: 1 });
  const domainId = await domainSelect.inputValue();
  await expect(page.getByText('Requests', { exact: true }).first()).toBeVisible();

  await page.goto(`/domains/${domainId}/analytics`);
  await expect(page.getByRole('heading', { name: /Analytics$/ })).toBeVisible();
  await expect(page.getByLabel('Domain')).toHaveCount(0);
  await expect(page.getByText('Cache hit ratio')).toBeVisible();
});
