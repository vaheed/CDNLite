import { expect, type Page } from '@playwright/test';

export async function loginAsLocalAdmin(page: Page) {
  await page.goto('/');
  await page.getByLabel('Username', { exact: true }).fill('admin');
  await page.getByLabel('Password', { exact: true }).fill('admin');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('OPS Dashboard', { exact: true }).first()).toBeVisible();
}
