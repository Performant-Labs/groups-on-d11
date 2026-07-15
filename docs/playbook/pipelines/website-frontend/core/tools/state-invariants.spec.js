// state-invariants.spec.js — Tier 2.5 interaction suite. PLATFORM-AGNOSTIC TOOL
// (Website Front-End Pipeline).
//
// Runs the 4-step state invariant for every enabled surface in the config:
//   1. establish state (apply filter, open panel, advance pager)
//   2. navigate away (to a detail page)
//   3. return (browser back)
//   4. assert the state survived (or, for ephemeral surfaces, that it reset)
//
// All four steps are required — applying a filter and asserting the filtered
// list without leaving the page does NOT cover persistence.
//
// Config: a project-specific surface inventory (see state-invariants.config.example.json
//   and the project's stateful-surfaces doc). Point to it with STATE_INVARIANTS_CONFIG;
//   defaults to ./state-invariants.config.json next to this file.
//
// Run:   STATE_INVARIANTS_CONFIG=<path> npx playwright test state-invariants.spec.js
// Env:   STATE_INVARIANTS_BASE_URL   overrides config baseUrl
//        STATE_INVARIANTS_ONLY=id1,id2  run a subset (T per-cycle runs)

const { test, expect } = require('@playwright/test');
const path = require('path');
const config = require(process.env.STATE_INVARIANTS_CONFIG
  ? path.resolve(process.env.STATE_INVARIANTS_CONFIG)
  : path.join(__dirname, 'state-invariants.config.json'));

test.use({ ignoreHTTPSErrors: true }); // tolerate local dev certs

const BASE = process.env.STATE_INVARIANTS_BASE_URL || config.baseUrl;
const ONLY = process.env.STATE_INVARIANTS_ONLY
  ? process.env.STATE_INVARIANTS_ONLY.split(',').map((s) => s.trim())
  : null;

// State captured during the establish phase, used by "against: established" asserts.
const established = {};

async function act(page, surfaceId, step) {
  switch (step.action) {
    case 'click':
      await page.locator(step.selector).first().click();
      break;
    case 'clickFirst': {
      const link = page.locator(step.selector).first();
      await expect(link, `navigate-away target missing: ${step.selector}`).toBeVisible();
      await link.click();
      await page.waitForLoadState('domcontentloaded');
      break;
    }
    case 'fill':
      await page.locator(step.selector).first().fill(step.value);
      established[`${surfaceId}:${step.selector}`] = step.value;
      break;
    case 'select': {
      const sel = page.locator(step.selector).first();
      let value = step.value;
      if (value === '__FIRST_NON_EMPTY__') {
        value = await sel
          .locator('option')
          .evaluateAll((opts) => opts.map((o) => o.value).find((v) => v && v !== 'All'));
        expect(value, `no non-empty option in ${step.selector}`).toBeTruthy();
      }
      await sel.selectOption(value);
      established[`${surfaceId}:${step.selector}`] = value;
      break;
    }
    case 'goto':
      await page.goto(BASE + step.url, { waitUntil: 'domcontentloaded' });
      break;
    default:
      throw new Error(`unknown action: ${step.action}`);
  }
}

async function assertState(page, surfaceId, a) {
  const url = new URL(page.url());
  switch (a.type) {
    case 'urlParamPresent':
      expect(url.searchParams.has(a.param), `URL param ${a.param} missing after return`).toBe(true);
      break;
    case 'urlParamEquals':
      expect(url.searchParams.get(a.param), `URL param ${a.param} after return`).toBe(a.expected);
      break;
    case 'urlPathPrefixPreserved': {
      const prefix = established[`${surfaceId}:pathPrefix`];
      expect(url.pathname.startsWith(prefix), `path prefix ${prefix} lost (got ${url.pathname})`).toBe(true);
      break;
    }
    case 'valueMatches': {
      const expected = established[`${surfaceId}:${a.selector}`];
      const actual = await page.locator(a.selector).first().inputValue();
      expect(actual, `control ${a.selector} lost its value after return`).toBe(expected);
      break;
    }
    case 'attributeEquals': {
      const actual = await page.locator(a.selector).first().getAttribute(a.attribute);
      expect(actual, `${a.selector}[${a.attribute}] after navigation`).toBe(a.expected);
      break;
    }
    default:
      throw new Error(`unknown assert type: ${a.type}`);
  }
}

const surfaces = config.surfaces.filter(
  (s) => s.enabled !== false && (!ONLY || ONLY.includes(s.id))
);

if (surfaces.length === 0) {
  test('no enabled surfaces', () => {
    test.skip(
      true,
      'All surfaces disabled in state-invariants.config.json — enable entries after confirming selectors live (see docs/pl2/stateful-surfaces.md).'
    );
  });
}

for (const s of surfaces) {
  test(`state invariant: ${s.id}`, async ({ page }) => {
    if (s.viewport) await page.setViewportSize(s.viewport);

    // Step 1 — establish state
    await page.goto(BASE + s.url, { waitUntil: 'domcontentloaded' });
    if (s.assert.some((a) => a.type === 'urlPathPrefixPreserved')) {
      established[`${s.id}:pathPrefix`] = new URL(page.url()).pathname.split('/').slice(0, 2).join('/');
    }
    for (const step of s.establish) await act(page, s.id, step);

    // Step 2 — navigate away
    await act(page, s.id, s.navigateAway);

    // Step 3 — return
    await page.goBack({ waitUntil: 'domcontentloaded' });

    // Step 4 — assert state survived (or reset, for ephemeral surfaces)
    for (const a of s.assert) await assertState(page, s.id, a);
  });
}
