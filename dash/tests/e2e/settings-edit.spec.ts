import { expect, test } from '@playwright/test';
import { loginAsLocalAdmin } from './helpers';

test('settings persist and secrets remain masked', async ({ page }) => {
  await loginAsLocalAdmin(page);
  await page.goto('/settings');
  await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
  const apiUrl = page.getByLabel('Api Url');
  await apiUrl.fill('http://powerdns:8081');
  await page.getByRole('button', { name: 'Update' }).click();
  await page.getByPlaceholder('Enter new secret').fill('e2e-settings-secret');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByText('Settings saved.')).toBeVisible();

  await page.reload();
  await expect(page.getByLabel('Api Url')).toHaveValue('http://powerdns:8081');
  await expect(page.getByText('••••• (configured)')).toBeVisible();
  await expect(page.getByText('admin', { exact: false }).first()).toBeVisible();
  await page.getByRole('button', { name: 'Test PowerDNS connection' }).click();
  await expect(page.getByText(/PowerDNS connection (succeeded|failed)/)).toBeVisible();
});
