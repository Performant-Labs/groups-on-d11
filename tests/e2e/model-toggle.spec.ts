import { test, expect } from '@playwright/test';

/**
 * E2E for ST-8 (#130) — stream-model switcher on /stream.
 *
 * ORIGINAL BEHAVIOUR (superseded): this spec pinned a two-option variant
 * switcher — "Content view (soon)" (unavailable) / "Activity view"
 * (selected) — rendered over `/stream` as the view's `#header`.
 *
 * CURRENT BEHAVIOUR (#285): a variant switcher exists to let a visitor
 * COMPARE alternatives, so `VariantSwitcher::build()` now returns an empty
 * render array when fewer than two of its options are available. The
 * `stream.model` instance has exactly one available option (Activity view),
 * because #129 has not shipped a real Content view display — so the switcher
 * is intentionally absent. A comparison control whose other half does not
 * exist read as broken rather than forthcoming, and contradicted the demo's
 * own honesty rule (#133 SD-6: never imply availability that is not there).
 *
 * These tests therefore pin the ABSENCE as the contract, plus the
 * non-regression guarantee that removing the control did not disturb the
 * page it sat on. When #129 ships a real Content view and flips that
 * option's `available` flag to TRUE, the switcher returns automatically and
 * these expectations should be re-inverted to the two-option assertions this
 * file previously carried (recoverable from git history, pre-PR #285).
 */

const STREAM_PATH = '/stream';
const SWITCHER = '[role="radiogroup"][data-do-showcase-instance="stream.model"]';

test.describe('ST-8 (#130) — stream-model switcher', () => {
  test('the switcher is absent while only one model option is available (#285)', async ({
    page,
  }) => {
    await page.goto(STREAM_PATH);
    await expect(page.locator(SWITCHER)).toHaveCount(0);
  });

  test('no "Content view" / "(soon)" placeholder copy is shown to a visitor', async ({
    page,
  }) => {
    await page.goto(STREAM_PATH);
    // The whole point of #285: never offer a control for something that
    // does not exist.
    await expect(page.getByText('Content view', { exact: false })).toHaveCount(0);
    await expect(page.getByText('(soon)', { exact: false })).toHaveCount(0);
  });

  test('the stream page itself still renders normally without the switcher', async ({
    page,
  }) => {
    const response = await page.goto(STREAM_PATH);
    expect(response?.status()).toBe(200);
    // Removing the header switcher must not have taken the view with it.
    await expect(page.locator('.views-element-container').first()).toBeVisible();
  });
});
