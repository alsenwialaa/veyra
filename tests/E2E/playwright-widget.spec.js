const { test, expect } = require('@playwright/test');
const AxeBuilder = require('axe-core');

const baseURL = process.env.VEYRA_BASE_URL || 'http://127.0.0.1:8080';
const evidenceByPage = new WeakMap();

test.use({ trace: 'retain-on-failure', screenshot: 'only-on-failure' });

function observeRuntime(page) {
  const evidence = { assetResponses: [], requestFailures: [], pageErrors: [], consoleErrors: [] };
  evidenceByPage.set(page, evidence);
  page.on('response', (response) => {
    if (response.url().includes('/assets/customer/veyra-chat.js')) {
      evidence.assetResponses.push({
        url: response.url(),
        status: response.status(),
        contentType: response.headers()['content-type'] || '',
      });
    }
  });
  page.on('requestfailed', (request) => {
    if (request.url().includes('/assets/customer/')) {
      evidence.requestFailures.push({ url: request.url(), error: request.failure()?.errorText || 'unknown' });
    }
  });
  page.on('pageerror', (error) => evidence.pageErrors.push(error.stack || error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') evidence.consoleErrors.push(message.text());
  });
}

async function runtimeDiagnostics(page) {
  const browser = await page.evaluate(() => ({
    readyState: document.readyState,
    bootstrapSchema: window.VeyraChatBootstrap?.schema_version || null,
    bootstrapEnabled: window.VeyraChatBootstrap?.enabled === true,
    contractsLoaded: typeof window.VeyraChatContracts?.validateMessagePayload === 'function',
    scripts: Array.from(document.scripts, (script) => script.src).filter((src) => src.includes('veyra-chat.js')),
    resources: performance.getEntriesByType('resource')
      .map((entry) => entry.name)
      .filter((name) => name.includes('/assets/customer/')),
    roots: Array.from(document.querySelectorAll('[data-veyra-chat]'), (root) => ({
      id: root.id,
      mounted: root.dataset.veyraMounted || null,
      surface: root.dataset.veyraSurface || null,
      hasPanel: root.querySelector('[data-veyra-panel]') !== null,
      hasTimeline: root.querySelector('[data-veyra-timeline]') !== null,
      hasComposer: root.querySelector('[data-veyra-composer]') !== null,
      hasInput: root.querySelector('[data-veyra-input]') !== null,
    })),
  }));
  return { ...browser, ...(evidenceByPage.get(page) || {}) };
}

async function expectRuntimeReady(page) {
  const diagnostics = await runtimeDiagnostics(page);
  const detail = JSON.stringify(diagnostics, null, 2);
  expect(diagnostics.assetResponses.some(({ status }) => status >= 200 && status < 400), detail).toBe(true);
  expect(diagnostics.requestFailures, detail).toEqual([]);
  expect(diagnostics.pageErrors, detail).toEqual([]);
  expect(diagnostics.consoleErrors, detail).toEqual([]);
  expect(diagnostics.bootstrapSchema, detail).toBe('veyra.customer_bootstrap.v1');
  expect(diagnostics.bootstrapEnabled, detail).toBe(true);
  expect(diagnostics.contractsLoaded, detail).toBe(true);
  expect(diagnostics.roots.length, detail).toBeGreaterThan(0);
  expect(diagnostics.roots.every((root) => root.mounted === 'true'), detail).toBe(true);
  expect(
    diagnostics.roots.every((root) => root.hasPanel && root.hasTimeline && root.hasComposer && root.hasInput),
    detail
  ).toBe(true);
}

test.beforeEach(async ({ page }) => observeRuntime(page));

test.afterEach(async ({ page }, testInfo) => {
  if (testInfo.status !== testInfo.expectedStatus) {
    const diagnostics = await runtimeDiagnostics(page).catch((error) => ({ diagnosticError: error.message }));
    await testInfo.attach('veyra-runtime.json', {
      body: JSON.stringify(diagnostics, null, 2),
      contentType: 'application/json',
    });
  }
});

test('launcher opens a keyboard-safe labelled dialog without critical axe violations', async ({ page }) => {
  await page.goto(baseURL, { waitUntil: 'networkidle' });
  await expectRuntimeReady(page);

  const launcher = page.getByRole('button', { name: /open veyra chat/i });
  const panel = page.locator('[data-veyra-panel]').first();
  await expect(launcher).toBeVisible();
  await expect(panel).toHaveCount(1);
  await expect(panel).toBeHidden();
  await launcher.focus();
  await expect(launcher).toBeFocused();
  await launcher.press('Enter');

  await expect(panel).not.toHaveAttribute('hidden', '');
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
  await expectRuntimeReady(page);
  await page.getByRole('button', { name: /open veyra chat/i }).click();

  const panel = page.locator('[data-veyra-panel]').first();
  await expect(panel).not.toHaveAttribute('hidden', '');
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
