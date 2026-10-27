import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — full demo frame: demo navbar + ContactForm UI in a useful state + Symfony WebProfiler toolbar.
 *
 * Playwright clips to the viewport, so we enlarge the viewport before capture.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

type Box = { x: number; y: number; width: number; height: number };
type Page = import('@playwright/test').Page;

async function boxOf(page: Page, selector: string): Promise<Box | null> {
  const loc = page.locator(selector).first();
  if ((await loc.count()) === 0) {
    return null;
  }
  return loc.boundingBox();
}

/** Navbar → main → profiler (union), padded. */
async function clipDemoFrame(page: Page): Promise<Box> {
  const masthead = await boxOf(page, 'nav.demo-navbar');
  const main = await boxOf(page, 'main');
  const profiler = (await boxOf(page, '.sf-toolbar')) ?? (await boxOf(page, '.sf-minitoolbar'));
  if (!masthead || !main) {
    throw new Error('Missing demo navbar or main for the ContactForm screenshot clip');
  }
  const boxes = [masthead, main, profiler].filter(Boolean) as Box[];
  const x = Math.min(...boxes.map((b) => b.x));
  const y = Math.min(...boxes.map((b) => b.y));
  const right = Math.max(...boxes.map((b) => b.x + b.width));
  const bottom = Math.max(...boxes.map((b) => b.y + b.height));
  const pad = 8;
  return {
    x: Math.max(0, x - pad),
    y: Math.max(0, y - pad),
    width: right - x + pad * 2,
    height: bottom - y + pad * 2,
  };
}

async function waitProfiler(page: Page) {
  await page
    .locator('.sf-toolbar .sf-toolbar-block, .sf-toolbar-status, .sf-minitoolbar')
    .first()
    .waitFor({ state: 'visible', timeout: 10000 })
    .catch(() => {});
}

async function capture(page: Page, file: string) {
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  await page.evaluate(() => document.fonts.ready).catch(() => {});
  await waitProfiler(page);
  // Fit the viewport to the content (the demo body is min-height: 100vh) so the profiler toolbar sits under it.
  await page.setViewportSize({ width: 1280, height: 720 });
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  await page.setViewportSize({ width: 1280, height: Math.min(Math.max(height, 720), 1800) });
  await waitProfiler(page);
  await page.screenshot({ path: resolve(outDir, file), clip: await clipDemoFrame(page) });
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('ContactForm screenshots (full demo context)', () => {
  test('overview — public contact form on the UiKit guest layout', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 1400 });
    await page.goto('/en/contact/contact');
    const root = page.locator('[data-testid="contact-form-public"]');
    await expect(root).toBeVisible();
    await root.getByLabel('Email').fill('ada.lovelace@example.com');
    await root.locator('input[type="tel"]').first().fill('612 345 678');
    await root.getByLabel('Subject').fill('Demo request for our support team');
    await root.getByLabel('Topic').selectOption({ index: 2 });
    await root.getByLabel('Message').fill('Hi! We would like to collect multilingual contact requests with GDPR consent.');
    await root.locator('input[type="checkbox"][name*="gdpr_consent"]').check();
    await capture(page, 'overview.png');
  });

  test('admin — submissions inbox (UiKit list scaffold)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.goto('/admin/contact-forms/');
    await page
      .locator('[data-testid="contact-form-forms"] tbody tr', { hasText: 'General contact' })
      .getByRole('link', { name: 'Submissions' })
      .click();
    await expect(page.locator('[data-testid="contact-form-submissions"] tbody tr').first()).toBeVisible();
    await capture(page, 'admin.png');
  });

  test('submission — detail (UiKit show scaffold)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.goto('/admin/contact-forms/');
    await page
      .locator('[data-testid="contact-form-forms"] tbody tr', { hasText: 'General contact' })
      .getByRole('link', { name: 'Submissions' })
      .click();
    await page.locator('[data-testid="contact-form-submissions"] tbody tr').last().getByRole('link', { name: 'View' }).click();
    await expect(page.locator('[data-testid="contact-form-submission"]')).toBeVisible();
    await capture(page, 'submission.png');
  });
});
