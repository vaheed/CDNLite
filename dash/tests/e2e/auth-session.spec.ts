import { expect, test } from '@playwright/test';

test('admin session survives refresh and password visibility is explicit', async ({ page }) => {
  await page.goto('/');

  const password = page.getByLabel('Password', { exact: true });
  await page.getByLabel('Username', { exact: true }).fill('admin');
  await password.fill('admin');
  await expect(password).toHaveAttribute('type', 'password');
  await page.getByLabel('Show password').check();
  await expect(password).toHaveAttribute('type', 'text');

  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('OPS Dashboard', { exact: true }).first()).toBeVisible();

  await page.reload();
  await expect(page.getByText('OPS Dashboard', { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Admin login' })).toHaveCount(0);
});
