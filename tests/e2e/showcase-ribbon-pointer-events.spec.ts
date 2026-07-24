import { test, expect } from '@playwright/test';

/**
 * #167 — Showcase ribbon must not intercept pointer events meant for
 * page controls beneath it, against the FULL SEEDED demo site (assemble ->
 * site:install -> config:import -> seed -> runserver -> playwright), per
 * `persona-switcher.spec.ts`'s own established convention for this repo
 * ("E2E runs against the FULL SEEDED demo site ... not an isolated
 * fixture" — WAVE-EXECUTION-HANDOFF.md §6.6).
 *
 * Bug: `.do-showcase-ribbon` (do_showcase, #119) is
 * `position: fixed; top: 0; z-index: 1000` with the CSS default
 * `pointer-events: auto`, rendered on every page via `hook_page_top`
 * (DoShowcaseHooks::pageTop()). On viewports where the ribbon visually
 * overlays a control positioned near the top of the viewport (e.g. the
 * persona-switcher "Go" button on `/`), clicks meant for that control are
 * intercepted by the ribbon instead — `persona-switcher.spec.ts` itself
 * works around this today via a `beforeEach` that dismisses the ribbon
 * before every test (see the comment there, "Follow-up issue tracks the
 * underlying CSS fix" — this is that follow-up).
 *
 * Fix under test (F implements, not yet shipped at RED time):
 *   `.do-showcase-ribbon { pointer-events: none; }` on the container, plus
 *   opt-in `.do-showcase-ribbon a, .do-showcase-ribbon button,
 *   .do-showcase-ribbon-dismiss { pointer-events: auto; }` so the ribbon's
 *   own anchor + dismiss button remain independently clickable.
 *
 * Selector contract this spec pins (F implements against these, T-GREEN
 * re-runs verbatim):
 *   - Container: `#do-showcase-ribbon` (also `.do-showcase-ribbon`,
 *     `data-do-showcase-ribbon="true"` — DoShowcaseHooks.php lines
 *     179-212).
 *   - Anchor: `#do-showcase-ribbon a` (single anchor in the render array,
 *     "See what it compares ->").
 *   - Dismiss button: `.do-showcase-ribbon-dismiss`
 *     (`data-do-showcase-dismiss="true"`, `aria-label="Dismiss demo
 *     banner"`).
 *
 * Deliberately NO beforeEach ribbon-dismiss here — the whole point of this
 * spec is verifying we do NOT need to work around the ribbon any more.
 *
 * RED-by-construction reasoning (current main, before the CSS fix ships):
 *   - do_showcase.css today sets no `pointer-events` at all on
 *     `.do-showcase-ribbon` or its descendants, so the UA default
 *     (`pointer-events: auto`) applies to the fixed, full-width,
 *     top-`z-index` container. `document.elementFromPoint()` at a point
 *     inside the ribbon's own painted box therefore returns the ribbon (or
 *     a descendant of it) today, regardless of what page content sits
 *     underneath — test (a) asserts the OPPOSITE (the ribbon does NOT
 *     intercept), so it fails on main for the right reason: the real
 *     browser behavior contradicts the assertion, not a selector/setup
 *     typo (the ribbon selectors themselves resolve fine on main; only the
 *     interception assertion is false).
 *   - Tests (b) and (c) assert elementFromPoint on the anchor's / dismiss
 *     button's own center returns that element (or a descendant) — this
 *     is already true on main (nothing here is broken pre-fix, the anchor
 *     and button are the topmost elements at their own coordinates
 *     regardless of the container's pointer-events value) AND remains true
 *     post-fix (the opt-in `pointer-events: auto` rule restores exactly
 *     this). These two pin the "no regression on the opt-in" contract so a
 *     future change that drops the opt-in rule (e.g. someone "simplifies"
 *     the CSS to a single blanket `pointer-events: none`) fails a test.
 */

const RIBBON_SELECTOR = '#do-showcase-ribbon';
const RIBBON_ANCHOR_SELECTOR = '#do-showcase-ribbon a';
const RIBBON_DISMISS_SELECTOR = '.do-showcase-ribbon-dismiss';

test.describe('#167 showcase ribbon does not intercept pointer events', () => {
  test('the ribbon does not intercept a click meant for the page beneath it', async ({
    page,
  }) => {
    await page.goto('/');

    const ribbon = page.locator(RIBBON_SELECTOR);
    await expect(ribbon).toBeVisible();

    const box = await ribbon.boundingBox();
    expect(box).not.toBeNull();

    // Safe interior zone: horizontal middle of the ribbon (avoids the
    // leftmost text+link region and the rightmost dismiss button),
    // vertical middle of the ribbon's own height.
    const midX = box!.x + box!.width / 2;
    const midY = box!.y + box!.height / 2;

    const isRibbonOrDescendant = await page.evaluate(
      ({ selector, x, y }) => {
        const ribbonEl = document.querySelector(selector);
        const hit = document.elementFromPoint(x, y);
        if (!ribbonEl || !hit) {
          return false;
        }
        return ribbonEl === hit || ribbonEl.contains(hit);
      },
      { selector: RIBBON_SELECTOR, x: midX, y: midY },
    );

    // On current main this is `true` (the ribbon's fixed, full-width box
    // has default `pointer-events: auto` and paints above everything at
    // this coordinate) — the fix (`pointer-events: none` on the
    // container) makes it `false` by letting hits fall through to
    // whatever page element is actually at that point.
    expect(isRibbonOrDescendant).toBe(false);
  });

  test('the ribbon\'s own "See what it compares ->" link remains clickable', async ({
    page,
  }) => {
    await page.goto('/');

    const anchor = page.locator(RIBBON_ANCHOR_SELECTOR);
    await expect(anchor).toBeVisible();

    const box = await anchor.boundingBox();
    expect(box).not.toBeNull();

    const midX = box!.x + box!.width / 2;
    const midY = box!.y + box!.height / 2;

    const isAnchorOrDescendant = await page.evaluate(
      ({ selector, x, y }) => {
        const anchorEl = document.querySelector(selector);
        const hit = document.elementFromPoint(x, y);
        if (!anchorEl || !hit) {
          return false;
        }
        return anchorEl === hit || anchorEl.contains(hit);
      },
      { selector: RIBBON_ANCHOR_SELECTOR, x: midX, y: midY },
    );

    // True both before and after the fix: the anchor is the topmost
    // element at its own coordinates on main today, and the fix's opt-in
    // `.do-showcase-ribbon a { pointer-events: auto; }` rule preserves
    // this once the container itself switches to `pointer-events: none`.
    expect(isAnchorOrDescendant).toBe(true);
  });

  test('the ribbon\'s dismiss button remains clickable and dismisses the ribbon', async ({
    page,
  }) => {
    await page.goto('/');

    const dismissButton = page.locator(RIBBON_DISMISS_SELECTOR);
    await expect(dismissButton).toBeVisible();

    const box = await dismissButton.boundingBox();
    expect(box).not.toBeNull();

    const midX = box!.x + box!.width / 2;
    const midY = box!.y + box!.height / 2;

    const isDismissOrDescendant = await page.evaluate(
      ({ selector, x, y }) => {
        const dismissEl = document.querySelector(selector);
        const hit = document.elementFromPoint(x, y);
        if (!dismissEl || !hit) {
          return false;
        }
        return dismissEl === hit || dismissEl.contains(hit);
      },
      { selector: RIBBON_DISMISS_SELECTOR, x: midX, y: midY },
    );

    // Same load-bearing contract as the anchor test above, pinned
    // separately because the dismiss button carries its own opt-in rule
    // (`.do-showcase-ribbon-dismiss { pointer-events: auto; }`) distinct
    // from the generic `.do-showcase-ribbon button` rule.
    expect(isDismissOrDescendant).toBe(true);

    await dismissButton.click();
    await expect(page.locator(RIBBON_SELECTOR)).toHaveCount(0);
  });
});
