import { test, expect } from '@playwright/test';

/**
 * REQ-DEMO-013 — ContactFormBundle demo happy path: public form (UiKit guest layout) and the admin pages on the UiKit
 * page contract (list / form / wizard / show scaffolds, confirm dialog). Data from `app:seed-contact-demo`.
 */
test.describe('ContactFormBundle demo', () => {
  test('home lists the seeded forms', async ({ page }) => {
    await page.goto('/en');
    await expect(page.locator('nav.demo-navbar')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Admin' }).first()).toBeVisible();
    await expect(page.locator('main code', { hasText: 'contact' }).first()).toBeVisible();
  });

  test('public form submits and shows the success message', async ({ page }) => {
    await page.goto('/en/contact/contact');
    const root = page.locator('[data-testid="contact-form-public"]');
    await expect(root).toBeVisible();
    await expect(page.locator('h1')).toContainText('Contact');

    await root.getByLabel('Email').fill('e2e@example.com');
    await root.getByLabel('Subject').fill('Playwright check');
    await root.getByLabel('Topic').selectOption({ index: 1 });
    await root.getByLabel('Message').fill('Automated end-to-end submission.');
    const consent = root.locator('input[type="checkbox"][name*="gdpr_consent"]');
    if ((await consent.count()) > 0) {
      await consent.check();
    }
    await root.getByRole('button', { name: 'Submit' }).click();

    await expect(page.locator('[role="alert"], .nowo-ui-flash, .alert').first()).toBeVisible();
  });

  test('admin lists forms and opens the delete confirm dialog', async ({ page }) => {
    await page.goto('/admin/contact-forms/');
    const list = page.locator('[data-testid="contact-form-forms"]');
    await expect(list).toBeVisible();
    await expect(list.locator('table tbody tr').first()).toBeVisible();
    await expect(list.locator('tbody')).toContainText('General contact');

    await list.locator('tbody tr').first().locator('button[data-nowo-confirm-open]').click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('Delete contact form');
    await dialog.locator('[data-nowo-confirm-close]').last().click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);
  });

  test('admin fields, field wizard, submissions and submission detail', async ({ page }) => {
    await page.goto('/admin/contact-forms/');
    const row = page.locator('[data-testid="contact-form-forms"] tbody tr', { hasText: 'General contact' });
    await row.getByRole('link', { name: 'Fields' }).click();

    const fields = page.locator('[data-testid="contact-form-fields"]');
    await expect(fields).toBeVisible();
    await expect(fields.locator('tbody')).toContainText('email');

    await page.getByRole('link', { name: 'New field' }).first().click();
    const wizard = page.locator('[data-testid="contact-form-field-wizard"]');
    await expect(wizard).toBeVisible();
    await expect(wizard.locator('[aria-current="step"]')).toContainText('Field settings');

    await page.goto('/admin/contact-forms/');
    await page
      .locator('[data-testid="contact-form-forms"] tbody tr', { hasText: 'General contact' })
      .getByRole('link', { name: 'Submissions' })
      .click();
    const submissions = page.locator('[data-testid="contact-form-submissions"]');
    await expect(submissions.locator('tbody tr').first()).toBeVisible();

    await submissions.locator('tbody tr').first().getByRole('link', { name: 'View' }).click();
    const detail = page.locator('[data-testid="contact-form-submission"]');
    await expect(detail).toBeVisible();
    await expect(detail).toContainText('Submitted values');
  });

  test('contact form edit page renders the translation tabs', async ({ page }) => {
    await page.goto('/admin/contact-forms/');
    await page
      .locator('[data-testid="contact-form-forms"] tbody tr', { hasText: 'General contact' })
      .getByRole('link', { name: 'Edit' })
      .click();
    const form = page.locator('[data-testid="contact-form-form"]');
    await expect(form).toBeVisible();
    await expect(form.locator('[data-testid="form-translation-tabs"] [role="tab"]').first()).toBeVisible();
  });
});
