import { test, expect, Page } from '@playwright/test';

/**
 * SC-F1 (#119) E2E — variant-switcher framework, /showcase tour page, POC
 * ribbon — groups-on-d11.
 *
 * Brief-gate B-3 (brief.md): this single spec covers all three UI surfaces
 * named in the brief:
 *   - the labeled variant-switcher device (render, switch, persist,
 *     non-color selection cue, no-JS ?variant= fallback, unavailable option),
 *   - the site-wide POC ribbon (shows for anonymous + authenticated, dismiss,
 *     persist across navigation, does not reflow primary nav),
 *   - the /showcase tour page (lists all comparisons incl. "coming" +
 *     the persona-switcher entry naming all four personas).
 *
 * do_showcase does not exist yet at RED time (Phase 4, before F implements) —
 * every selector below targets markup/routes the wireframe (wireframe.md)
 * specifies but nothing in the codebase renders yet. See
 * docs/handoffs/0119-variant-framework/handoff-T-red.md for the RED-by-
 * construction reasoning per case (the site is not running in this
 * environment, so these are executed for real at T-GREEN against the
 * namespaced Docker per brief.md's Acceptance criterion "Verified via
 * namespaced throwaway-DB Docker ... local npx playwright test ... green").
 *
 * Selector contract this spec pins (F implements against these, T-GREEN
 * re-runs verbatim):
 *   - Switcher wrapper: [role="radiogroup"][aria-label] on the wired stub
 *     instance (directory.layout, per wireframe.md's own example).
 *   - Switcher options: [role="radio"] with visible text labels and
 *     aria-checked reflecting selection.
 *   - Ribbon: a fixed banner region with the exact copy "This is a
 *     proof-of-concept demo." + a link "See what it compares" -> /showcase,
 *     and a real <button aria-label="Dismiss demo banner">.
 *   - /showcase: <h1> + one block per catalog entry with its title and a
 *     "[ live ]" / "[ coming ]" status badge (wireframe.md's own text-badge
 *     wording — the load-bearing non-color cue).
 *
 * Auth uses the real /user/login form with admin/admin, matching nav.spec.ts
 * and phase1.spec.ts's existing convention (no session injection).
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

/** The stub switcher instance this story wires (wireframe.md's own example). */
const SWITCHER_INSTANCE_ID = 'directory.layout';

/** Log in via the /user/login form (matches nav.spec.ts / phase1.spec.ts). */
async function login(page: Page): Promise<void> {
  await page.goto('/user/login');
  await page.getByLabel('Username').fill(ADMIN_USER);
  await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/\/user(\/\d+)?/),
    page.getByRole('button', { name: 'Log in' }).click(),
  ]);
  await expect(page.locator('body')).not.toContainText(
    'Unrecognized username or password',
  );
}

/**
 * A page that hosts the wired stub switcher instance. Brief.md Acceptance
 * criterion #1 requires "at least one wired demo instance" — /showcase is
 * the one route this story guarantees exists, so the switcher is asserted
 * to render there (F may additionally place it elsewhere; this is the one
 * guaranteed location per the brief's own scope).
 */
const SWITCHER_HOST_PATH = '/showcase';

test.describe('SC-F1 — Variant switcher (#119)', () => {
  test('switcher renders as a labeled radiogroup with all stub options', async ({
    page,
  }) => {
    // RED reason: do_showcase module does not exist; no route/markup renders
    // this control at all, so this assertion fails on a 404 or a missing
    // locator, never on a false "wrong option selected" mismatch.
    const res = await page.goto(SWITCHER_HOST_PATH);
    expect(res?.status()).toBe(200);

    const switcher = page.locator(
      `[role="radiogroup"][aria-label]:has([role="radio"])`,
    ).first();
    await expect(switcher).toBeVisible();

    const options = switcher.locator('[role="radio"]');
    await expect(options).not.toHaveCount(0);
    // Wireframe's own example: Compact list / Cards / Map.
    await expect(switcher.getByText('Compact list')).toBeVisible();
    await expect(switcher.getByText('Cards')).toBeVisible();
    await expect(switcher.getByText('Map')).toBeVisible();
  });

  test('clicking an option switches the current selection', async ({
    page,
  }) => {
    await page.goto(SWITCHER_HOST_PATH);
    const switcher = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first();

    const compact = switcher.getByRole('radio', { name: /Compact list/i });
    const cards = switcher.getByRole('radio', { name: /Cards/i });

    await compact.click();
    await expect(compact).toHaveAttribute('aria-checked', 'true');
    await expect(cards).toHaveAttribute('aria-checked', 'false');

    await cards.click();
    await expect(cards).toHaveAttribute('aria-checked', 'true');
    await expect(compact).toHaveAttribute('aria-checked', 'false');
  });

  test('selection is conveyed by more than color (non-color cue present)', async ({
    page,
  }) => {
    await page.goto(SWITCHER_HOST_PATH);
    const switcher = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first();
    const cards = switcher.getByRole('radio', { name: /Cards/i });
    await cards.click();

    // wireframe.md: a leading glyph (e.g. "●") is the non-color cue,
    // in addition to aria-checked. Assert BOTH the ARIA state and a
    // non-empty text/glyph cue distinguishing selected from unselected —
    // an implementation relying on color alone (e.g. only a CSS class with
    // no visible glyph/text delta) fails this.
    await expect(cards).toHaveAttribute('aria-checked', 'true');
    const selectedText = await cards.textContent();
    const unselectedText = await switcher
      .getByRole('radio', { name: /Compact list/i })
      .textContent();
    expect(selectedText).not.toEqual(unselectedText?.replace('Compact list', 'Cards'));
    // The selected option's accessible text must differ in more than the
    // label alone (a glyph/prefix), or carry a distinct data attribute the
    // no-JS-fallback state also exposes ("(current)").
    expect(selectedText?.trim()).not.toBe('Cards');
  });

  test('the choice persists client-side across navigation', async ({
    page,
  }) => {
    await page.goto(SWITCHER_HOST_PATH);
    const switcher = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first();
    await switcher.getByRole('radio', { name: /Map/i }).click({ force: true });

    // Navigate away and back — persistence is client-side (cookie/
    // localStorage), so no server round-trip/session is required, but the
    // choice must survive a full navigation (brief.md Acceptance #1).
    await page.goto('/');
    await page.goto(SWITCHER_HOST_PATH);

    const switcherAfter = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first();
    // Map is unavailable in the stub instance, so a real implementation
    // would fall back — this test targets an AVAILABLE option instead to
    // pin genuine persistence without conflating it with the fallback case.
    await switcherAfter.getByRole('radio', { name: /Compact list/i }).click();
    await page.goto('/');
    await page.goto(SWITCHER_HOST_PATH);
    const persisted = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first()
      .getByRole('radio', { name: /Compact list/i });
    await expect(persisted).toHaveAttribute('aria-checked', 'true');
  });

  test('no-JS ?variant= query param selects the right option', async ({
    page,
  }) => {
    // wireframe.md "State: no-JS": plain links with ?variant=<id>, current
    // one marked in text, works without the segmented-control JS at all.
    await page.goto(`${SWITCHER_HOST_PATH}?variant=cards`);
    const switcher = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first();
    const cards = switcher.getByRole('radio', { name: /Cards/i });
    await expect(cards).toHaveAttribute('aria-checked', 'true');
  });

  // NOTE: the previous "an unavailable option is present, marked, and not
  // a dead click" test (asserting Map had aria-disabled=true, tabindex=-1,
  // and copy matching /soon/i) was retired when #125 SC-6 flipped Map to
  // available in VariantSwitcher::directoryLayoutOptionIds(). Every option
  // in the shared directoryLayoutOptions() list is now live on /showcase
  // too (both /showcase and /all-groups render from the same source), so
  // there is no unavailable option left to pin. The roving-tabindex and
  // arrow-key tests below were updated to reflect the DOM order
  // [compact, cards, map] with all three available.
  test('roving tabindex: only the selected option is Tab-reachable, not every available option', async ({
    page,
  }) => {
    // wireframe.md lines 29-31 / 271: "one option in tab order at a time" —
    // roving tabindex, not every available option simultaneously tabbable.
    // RED reason against current shipped code: VariantSwitcher.php line 92
    // sets tabindex="0" on EVERY available option, so BOTH "Compact list"
    // and "Cards" (the two available stub options) would report
    // tabindex="0" here — this assertion fails on the second (non-selected)
    // option's tabindex, not on a missing locator/selector.
    await page.goto(SWITCHER_HOST_PATH);
    const switcher = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first();

    const cards = switcher.getByRole('radio', { name: /Cards/i });
    const compact = switcher.getByRole('radio', { name: /Compact list/i });

    // "Cards" is the page's default selection (handoff-F.md: ShowcaseController
    // defaults ?variant= to 'cards').
    await expect(cards).toHaveAttribute('aria-checked', 'true');
    await expect(cards).toHaveAttribute('tabindex', '0');
    // The non-selected AVAILABLE option must be tabindex="-1" — reachable
    // only via arrow keys once focus is inside the radiogroup, not via Tab.
    await expect(compact).toHaveAttribute('tabindex', '-1');
  });

  test('ArrowRight moves selection to the next available option and rolls the roving tabindex', async ({
    page,
  }) => {
    // wireframe.md lines 29-31: "Arrow-Left/Right moves selection, matching
    // native radiogroup behavior." Post-#125 SC-6 contract: every
    // directory.layout option is now available (Map flipped live), so
    // DOM order is [Compact list, Cards, Map] and ArrowRight from Cards
    // moves to Map (the next available option) — no skipping needed.
    await page.goto(SWITCHER_HOST_PATH);
    const switcher = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first();
    const cards = switcher.getByRole('radio', { name: /Cards/i });
    const map = switcher.locator('[data-do-showcase-id="map"]');

    // Focus starts on the selected option ("Cards", tabindex=0) via Tab.
    await cards.focus();
    await expect(cards).toBeFocused();

    // ArrowRight from "Cards" moves to the next available option in DOM
    // order: "Map" (all three options are live post-#125 SC-6).
    await page.keyboard.press('ArrowRight');

    await expect(map).toBeFocused();
    await expect(map).toHaveAttribute('aria-checked', 'true');
    await expect(map).toHaveAttribute('tabindex', '0');
    await expect(cards).toHaveAttribute('aria-checked', 'false');
    await expect(cards).toHaveAttribute('tabindex', '-1');
  });

  test('ArrowLeft moves selection to the previous available option', async ({
    page,
  }) => {
    // Same wireframe contract, opposite direction. DOM order is
    // [Compact list, Cards, Map] and ArrowLeft from Cards lands on the
    // previous option, "Compact list". Post-#125 SC-6 every option is
    // available so no skipping is needed on this path either.
    await page.goto(SWITCHER_HOST_PATH);
    const switcher = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first();
    const compact = switcher.getByRole('radio', { name: /Compact list/i });
    const cards = switcher.getByRole('radio', { name: /Cards/i });

    await cards.focus();
    // ArrowLeft from "Cards" lands on "Compact list" — the previous option
    // in DOM order [Compact list, Cards, Map].
    await page.keyboard.press('ArrowLeft');

    await expect(compact).toBeFocused();
    await expect(compact).toHaveAttribute('aria-checked', 'true');
    await expect(compact).toHaveAttribute('tabindex', '0');
    await expect(cards).toHaveAttribute('tabindex', '-1');
  });

  test('no-JS ?variant= fallback still works unmodified by the arrow-key fix', async ({
    page,
  }) => {
    // Regression guard requested by the task: F's roving-tabindex/arrow-key
    // fix must not disturb the existing no-JS query-param fallback. This
    // duplicates the assertion shape of the existing "no-JS ?variant=" case
    // deliberately (as a narrow regression pin for this specific follow-up),
    // not as new coverage of the base fallback behavior itself.
    await page.goto(`${SWITCHER_HOST_PATH}?variant=map`);
    // Post-#125 SC-6: "map" is a LIVE option in directoryLayoutOptions(),
    // so ?variant=map must select "map" itself — no fallback needed,
    // unaffected by any arrow-key change to the JS.
    const switcher = page
      .locator(`[role="radiogroup"][aria-label]:has([role="radio"])`)
      .first();
    const map = switcher.locator('[data-do-showcase-id="map"]');
    await expect(map).toHaveAttribute('aria-checked', 'true');
  });

});

test.describe('SC-F1 — POC ribbon (#119)', () => {
  test('ribbon shows for anonymous visitors, links to /showcase', async ({
    page,
  }) => {
    await page.goto('/');
    const ribbon = page.getByText('This is a proof-of-concept demo.');
    await expect(ribbon).toBeVisible();
    const link = page.getByRole('link', { name: /see what it compares/i });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('href', '/showcase');
  });

  test('ribbon shows identically for an authenticated user', async ({
    page,
  }) => {
    await login(page);
    await page.goto('/');
    await expect(
      page.getByText('This is a proof-of-concept demo.'),
    ).toBeVisible();
  });

  test('ribbon does not cover or reflow primary nav (nav.spec.ts non-regression)', async ({
    page,
  }) => {
    await page.goto('/');
    // nav.spec.ts's own selector for the primary-nav block — must still be
    // visible and not visually displaced by the ribbon's presence.
    const nav = page.locator('#block-groups-chrome-main-menu');
    await expect(nav).toBeVisible();
    const groupsLink = nav.getByRole('link', { name: 'Groups', exact: true });
    await expect(groupsLink).toBeVisible();
  });

  test('dismiss button removes the ribbon', async ({ page }) => {
    await page.goto('/');
    const dismiss = page.getByRole('button', { name: 'Dismiss demo banner' });
    await expect(dismiss).toBeVisible();
    await dismiss.click();
    await expect(
      page.getByText('This is a proof-of-concept demo.'),
    ).toHaveCount(0);
  });

  test('dismissal persists client-side across navigation', async ({
    page,
  }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Dismiss demo banner' }).click();
    await expect(
      page.getByText('This is a proof-of-concept demo.'),
    ).toHaveCount(0);

    // No server session is written (client-side persistence) — the
    // dismissal must still survive a fresh navigation.
    await page.goto('/all-groups');
    await expect(
      page.getByText('This is a proof-of-concept demo.'),
    ).toHaveCount(0);
  });
});

test.describe('SC-F1 — /showcase tour page (#119)', () => {
  test('lists all six comparison entries with truthful [live]/[coming] badges', async ({
    page,
  }) => {
    const res = await page.goto('/showcase');
    expect(res?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // #133 SD-6 (capstone) flipped every remaining `coming` entry to
    // `live`: directory-presentation, membership-models, group-type-homepages,
    // stream-model, and private-group-reveal all shipped in earlier waves.
    // Only `[ live ]` badges exist now; the `[ coming ]` badge is
    // deliberately absent, guarded by
    // ShowcaseCatalogTest::testNoEntriesAreComing at the unit level.
    const entries: Array<[string, 'live']> = [
      ['Discovery ranking', 'live'],
      ['Directory presentation', 'live'],
      ['Membership models', 'live'],
      ['Group-type homepages', 'live'],
      ['Stream model', 'live'],
      ['Private-group reveal', 'live'],
    ];

    // Scope the title search to the tour catalog container
    // (`.do-showcase-catalog`, per ShowcaseController::page()) — the #123
    // SC-4 story added a sibling `<h2>Discovery ranking: Recent / Hot /
    // Promoted</h2>` section OUTSIDE this catalog, which would otherwise
    // make an unscoped `getByText('Discovery ranking')` a strict-mode
    // collision.
    const catalog = page.locator('.do-showcase-catalog');
    for (const [title, status] of entries) {
      const entry = catalog.getByText(title, { exact: false });
      await expect(entry).toBeVisible();
      const badgeText = status === 'live' ? '[ live ]' : '[ coming ]';
      // The status badge is text (non-color cue) near the entry — assert the
      // badge text is present on the page at all (loose page-level check,
      // since exact DOM proximity is an F implementation detail).
      await expect(page.getByText(badgeText).first()).toBeVisible();
    }
  });

  test('the private-group-reveal entry references #134', async ({ page }) => {
    await page.goto('/showcase');
    await expect(page.getByText(/#134/)).toBeVisible();
  });

  test('every showcase entry carries a "View this comparison" deep-link (no [ coming ] entries remain post-#133)', async ({
    page,
  }) => {
    await page.goto('/showcase');
    // #133 flipped every remaining `coming` entry to `live`; the truthful-
    // copy invariant is now the inverse: every entry must expose a
    // "View this comparison" link (ShowcaseController::page() only renders
    // that link for live entries). If a future story reintroduces a
    // `coming` entry, this fails loud because that entry will lack the link.
    const entryIds = [
      'discovery-ranking',
      'directory-presentation',
      'membership-models',
      'group-type-homepages',
      'stream-model',
      'private-group-reveal',
    ];
    for (const id of entryIds) {
      const entry = page.locator(`[data-do-showcase-entry="${id}"]`);
      await expect(entry).toBeVisible();
      const link = entry.getByRole('link', { name: 'View this comparison' });
      await expect(link, `entry "${id}" must render a "View this comparison" deep-link -- no [ coming ] entries remain post-#133`).toHaveCount(1);
    }
  });

  test('a live entry (Discovery ranking) renders its deep-link to /showcase', async ({
    page,
  }) => {
    // diff-gate B-3: the spec previously only asserted the ABSENCE of a
    // dead link on "coming" entries, never the PRESENCE of the required
    // deep-link on a "live" one. Per ShowcaseController::page(), a live
    // entry gets `#type => 'link'`, title "View this comparison", scoped
    // inside the entry's own `data-do-showcase-entry` container (the DOM
    // contract handoff-F.md documents) — resolving `do_showcase.showcase`
    // (the only route this story builds) to `/showcase`. If a live entry's
    // link were omitted or mis-scoped, this fails on a missing/zero-count
    // locator or a wrong href, not on an unrelated symptom.
    await page.goto('/showcase');
    const entry = page.locator(
      '[data-do-showcase-entry="discovery-ranking"]',
    );
    const link = entry.getByRole('link', { name: 'View this comparison' });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('href', '/showcase');
  });

  test('lists the persona switcher naming all four public personas', async ({
    page,
  }) => {
    await page.goto('/showcase');
    await expect(page.getByText('Persona switcher')).toBeVisible();
    // Scoped to the persona-switcher catalog entry's own DOM
    // (data-do-showcase-entry="persona-switcher", per handoff-F.md's DOM
    // contract) rather than a page-wide text search: the entry's own
    // decision_sentence legitimately contains the substring "anonymous"
    // (lowercase, "...one generic anonymous view...") and Playwright's
    // getByText is case-insensitive by default, so an unscoped page-wide
    // getByText('Anonymous') collides with that unrelated sentence in a
    // strict-mode violation. Scoping to the entry's persona <ul> pins the
    // real behavior (all four personas are named IN THE PERSONA LIST)
    // without being sensitive to incidental word overlap elsewhere on the
    // page.
    const personaList = page.locator(
      '[data-do-showcase-entry="persona-switcher"] ul',
    );
    for (const persona of [
      'Anonymous',
      'Elena Garcia',
      'Maria Chen',
      // #133 SD-6 renamed the persona display `name` from 'Moderator' to
      // 'Groups-Moderate' to match the canonical persona vocabulary
      // (Anonymous / Member / Organizer / Groups-Moderate).
      'Groups-Moderate',
    ]) {
      await expect(
        personaList.getByText(persona, { exact: false }),
      ).toBeVisible();
    }
  });
});
