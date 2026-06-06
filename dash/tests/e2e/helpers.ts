import { expect, type Page } from '@playwright/test';

export async function loginAsLocalAdmin(page: Page) {
  await page.goto('/');
  await page.getByLabel('Username', { exact: true }).fill('admin');
  await page.getByLabel('Password', { exact: true }).fill('admin');
  const loginResponse = page.waitForResponse(
    (response) => response.url().endsWith('/api/v1/admin/login') && response.request().method() === 'POST',
    { timeout: 20_000 },
  );
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(await loginResponse).toBeOK();
  await expect(page.getByText('OPS Dashboard', { exact: true }).first()).toBeVisible({ timeout: 15_000 });
}
