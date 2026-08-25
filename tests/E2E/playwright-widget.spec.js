const { test, expect } = require('@playwright/test');
const AxeBuilder = require('axe-core');

const baseURL = process.env.VEYRA_BASE_URL || 'http://127.0.0.1:8080';

test('launcher opens a keyboard-safe labelled dialog without critical axe violations', async ({ page }) => {
  await page.goto(baseURL, { waitUntil: 'networkidle' });

  const launcher = page.getByRole('button', { name: /open veyra chat/i });
  await expect(launcher).toBeVisible();
  await launcher.focus();
  await expect(launcher).toBeFocused();
  await launcher.press('Enter');

  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute('aria-modal', 'true');

  const textbox = page.getByRole('textbox', { name: /message veyra/i });
  await expect(textbox).toBeVisible();
  await expect(textbox).toBeFocused();
  await expect(page.getByText(/AI test fixture/i)).toBeVisible();

  // Inject axe-core without a page-side network request or dynamic evaluation.
  await page.addScriptTag({ content: AxeBuilder.source });
  const results = await page.evaluate(async () => {
    return window.axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] },
    });
  });

  const critical = results.violations.filter((violation) => ['critical', 'serious'].includes(violation.impact));
  expect(critical, JSON.stringify(critical, null, 2)).toEqual([]);

  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(launcher).toBeFocused();
});

test('mobile viewport keeps launcher and composer inside the visual viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(baseURL, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: /open veyra chat/i }).click();

  const dialog = page.getByRole('dialog');
  const textbox = page.getByRole('textbox', { name: /message veyra/i });
  await expect(dialog).toBeVisible();
  await expect(textbox).toBeVisible();

  const bounds = await textbox.boundingBox();
  expect(bounds).not.toBeNull();
  expect(bounds.x).toBeGreaterThanOrEqual(0);
  expect(bounds.y).toBeGreaterThanOrEqual(0);
  expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
  expect(bounds.y + bounds.height).toBeLessThanOrEqual(844);
});
