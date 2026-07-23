import { test, expect, Page, Locator } from '@playwright/test';

/**
 * SC-3 (#122) E2E — Group-type-driven homepages.
 *
 * The `/group/{id}` full-page render adapts to `field_group_type`:
 *   - Event planning   -> events-first lead section (DrupalCon Portland 2026)
 *   - Working group    -> discussion-first (forum) lead section (Core Committers)
 *   - Distribution     -> docs-first lead section (Thunder Distribution)
 *   - any other type / unset -> unchanged fallback (no lead section at all)
 *
 * Reference docs (read before editing this spec):
 *   - docs/planning/handoffs/122-grouptype-home/brief.md (acceptance criteria)
 *   - docs/planning/handoffs/122-grouptype-home/wireframe.md (page anatomy,
 *     fallback contract §7, tooltip pattern §3, "See all" targets §2)
 *   - docs/planning/handoffs/122-grouptype-home/handoff-A.md (Q1/Q2
 *     resolutions: see-all path `/group/{gid}/nodes?type=forum|documentation`,
 *     ⓘ contrast confirmed AA with the existing `.do-chrome-info` styling)
 *
 * RED (Phase 4, before F): `groups_chrome_preprocess_group()` does not yet
 * derive `gc_group.leading_section` / `lead_items`, `group--full.html.twig`
 * does not yet render a `.gc-group-lead` section, and the
 * `group_type.homepage_adapts` HelpText key + its `data-do-tooltip` wiring do
 * not exist — every exemplar test below fails because `.gc-group-lead` has
 * ZERO count on every group page today (the assertion the feature must
 * satisfy fails; there is no missing-import/setup failure masking this).
 *
 * Seed data (docs/groups/scripts/step_720_group_types.php +
 * step_700_demo_data.php), verified present at RED time:
 *   - DrupalCon Portland 2026 -> Event planning; has seeded `event` nodes
 *     (e.g. "DrupalCon Portland Keynote", "Code Sprint: Migrate API").
 *   - Core Committers -> Working group; has seeded `forum` nodes (e.g.
 *     "Patch Review Process RFC", "Drupal 11 Migration Path").
 *   - Thunder Distribution -> Distribution; NO `documentation`-type nodes
 *     exist anywhere in the seed (verified: step_700_demo_data.php seeds
 *     forum/event/comment content only, no `"type" => "documentation"`
 *     node-create call). Per the wireframe's own empty-state contract (§2):
 *     "If the group's leading content type has zero qualifying nodes ...
 *     do NOT render `.gc-group-lead` at all — same as the null fallback."
 *     So Thunder Distribution's own page is INDISTINGUISHABLE from the
 *     fallback case today. This spec therefore does NOT assert a rendered
 *     docs-first lead section for Thunder Distribution (that would be an
 *     invalid assertion against real seed data); instead it pins the
 *     degraded/empty-state behavior for that exemplar explicitly (see the
 *     "Thunder Distribution" describe block) and documents the gap for
 *     F/U so a manual/seed-augmented check isn't silently skipped.
 *   - Drupal France -> Geographical (unmapped type) — used as the fallback
 *     exemplar; still an active (non-archived) group with seeded members.
 *
 * Auth: reuses the existing admin/admin login pattern
 * (directory-cards.spec.ts / showcase.spec.ts) only where a test needs to
 * distinguish permission-independent lead-section rendering; most assertions
 * here run as anonymous (the lead section reads public group content).
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

async function login(page: Page, user: string, pass: string): Promise<void> {
  await page.goto('/user/login');
  await page.getByLabel('Username').fill(user);
  await page.getByLabel('Password', { exact: true }).fill(pass);
  await Promise.all([
    page.waitForURL(/\/user(\/\d+)?/),
    page.getByRole('button', { name: 'Log in' }).click(),
  ]);
  await expect(page.locator('body')).not.toContainText(
    'Unrecognized username or password',
  );
}

/**
 * Resolve a seeded group's canonical `/group/{gid}` URL by its label, via
 * the `/all-groups` directory (directory-cards.spec.ts's own surface) —
 * avoids hard-coding a group id, which is not guaranteed stable across a
 * fresh CI seed run.
 */
async function groupUrlByLabel(page: Page, label: string): Promise<string> {
  await page.goto('/all-groups');
  const card = page
    .locator('.gc-directory-card')
    .filter({ has: page.getByRole('link', { name: label, exact: true }) })
    .first();
  const link = card.locator('.gc-directory-card__title a').first();
  await expect(link).toBeVisible();
  const href = await link.getAttribute('href');
  expect(href).toBeTruthy();
  return href as string;
}

/** The `.gc-group-lead` region on a rendered group page, if any. */
function leadSection(page: Page): Locator {
  return page.locator('.gc-group-lead');
}

test.describe('SC-3 — Events-first lead section (#122, DrupalCon Portland 2026)', () => {
  test('leads with an "Upcoming events" section linking to event nodes and events tab', async ({
    page,
  }) => {
    const url = await groupUrlByLabel(page, 'DrupalCon Portland 2026');
    const res = await page.goto(url);
    expect(res?.status()).toBe(200);

    const lead = leadSection(page);
    await expect(lead).toBeVisible();

    // Heading names events (wireframe §1: "Upcoming events").
    const heading = lead.locator('.gc-group-lead__heading');
    await expect(heading).toBeVisible();
    await expect(heading).toContainText(/events/i);

    // Top-N item links point at real node URLs (not the group page itself,
    // not a placeholder/hash href).
    const itemLinks = lead.locator('.gc-group-lead__link');
    const itemCount = await itemLinks.count();
    expect(itemCount).toBeGreaterThan(0);
    for (let i = 0; i < itemCount; i++) {
      const href = await itemLinks.nth(i).getAttribute('href');
      expect(href).toBeTruthy();
      expect(href).toMatch(/\/node\/\d+/);
    }

    // "See all events" links at the existing per-group events tab URL
    // (D's wireframe §2 — no new route).
    const seeAll = lead.locator('.gc-group-lead__see-all');
    await expect(seeAll).toBeVisible();
    await expect(seeAll).toContainText(/see all events/i);
    const seeAllHref = await seeAll.getAttribute('href');
    expect(seeAllHref).toMatch(/\/group\/\d+\/events$/);
  });
});

test.describe('SC-3 — Discussion-first lead section (#122, Core Committers)', () => {
  test('leads with a "Recent discussions" section linking to forum nodes and a type-filtered see-all', async ({
    page,
  }) => {
    const url = await groupUrlByLabel(page, 'Core Committers');
    const res = await page.goto(url);
    expect(res?.status()).toBe(200);

    const lead = leadSection(page);
    await expect(lead).toBeVisible();

    const heading = lead.locator('.gc-group-lead__heading');
    await expect(heading).toBeVisible();
    await expect(heading).toContainText(/discuss/i);

    const itemLinks = lead.locator('.gc-group-lead__link');
    const itemCount = await itemLinks.count();
    expect(itemCount).toBeGreaterThan(0);
    for (let i = 0; i < itemCount; i++) {
      const href = await itemLinks.nth(i).getAttribute('href');
      expect(href).toBeTruthy();
      expect(href).toMatch(/\/node\/\d+/);
    }

    // A — handoff-A.md Q1 resolution: the type-filtered `group_nodes` path
    // (`/group/{gid}/nodes?type=forum`), not the unfiltered stream.
    const seeAll = lead.locator('.gc-group-lead__see-all');
    await expect(seeAll).toBeVisible();
    await expect(seeAll).toContainText(/see all discussions/i);
    const seeAllHref = await seeAll.getAttribute('href');
    expect(seeAllHref).toMatch(/\/group\/\d+\/nodes\?type(\[\])?=forum/);
  });
});

test.describe('SC-3 — Thunder Distribution (#122, Distribution type, no seeded documentation nodes)', () => {
  test('degrades to the empty-state contract: leading_section maps to docs, but no lead section renders because zero documentation nodes are seeded', async ({
    page,
  }) => {
    // This is the DOCUMENTED gap (see file header): the seed has no
    // `documentation`-type nodes anywhere, so this exemplar cannot exercise
    // a *rendered* docs-first section against real data. Per the wireframe's
    // own empty-state decision (§2), the correct behavior here is IDENTICAL
    // to the fallback contract — the theme suggestion may still resolve to
    // `group__community_group__docs_first`, but `.gc-group-lead` must not
    // render because `gc_group.lead_items` is empty.
    //
    // RED reason: today NEITHER branch exists (no leading_section logic at
    // all), so this assertion currently passes vacuously (no `.gc-group-lead`
    // exists because nothing does) — see "RED validity note" in
    // handoff-T-red.md for why this specific test is not counted as a
    // meaningful RED gate and is retained primarily as a GREEN-phase
    // regression pin once F's empty-state gating exists.
    const url = await groupUrlByLabel(page, 'Thunder Distribution');
    const res = await page.goto(url);
    expect(res?.status()).toBe(200);

    await expect(leadSection(page)).toHaveCount(0);
    // No lead-section library payload should be present either (mirrors
    // the fallback contract's library assertion below).
    const libRefs = page.locator('link[href*="group-type-homepage"]');
    await expect(libRefs).toHaveCount(0);

    // Tab bar stays intact regardless (this group still has an Events tab
    // etc. — only the lead section is gated).
    await expect(page.locator('.gc-group-tabs__link')).toHaveText([
      'Stream',
      'Events',
      'Members',
      'About',
    ]);
  });
});

test.describe('SC-3 — Fallback contract (#122, unmapped group type)', () => {
  test('an unmapped-type group (Drupal France, Geographical) renders with no lead section, no new library, unchanged tabs', async ({
    page,
  }) => {
    const url = await groupUrlByLabel(page, 'Drupal France');
    const res = await page.goto(url);
    expect(res?.status()).toBe(200);

    // No new DOM anywhere in the group article (wireframe §7's exact
    // assertion).
    await expect(
      page.locator('.gc-group-page .gc-group-lead'),
    ).toHaveCount(0);

    // No new library reference on the fallback page.
    await expect(
      page.locator('link[href*="group-type-homepage"]'),
    ).toHaveCount(0);

    // No tooltip markup for the new HelpText key leaks onto a fallback page.
    await expect(
      page.locator('[data-do-tooltip*="adapts to the group"]'),
    ).toHaveCount(0);

    // Tab bar text + order unchanged (wireframe §7's exact assertion).
    await expect(page.locator('.gc-group-tabs__link')).toHaveText([
      'Stream',
      'Events',
      'Members',
      'About',
    ]);
  });
});

test.describe('SC-3 — Tooltip accessibility (#122)', () => {
  test('the ⓘ trigger on the lead-section heading is keyboard-focusable with a non-empty accessible name and the expected copy', async ({
    page,
  }) => {
    const url = await groupUrlByLabel(page, 'DrupalCon Portland 2026');
    await page.goto(url);

    const trigger = leadSection(page).locator('.gc-group-lead__help');
    await expect(trigger).toBeVisible();

    // Focusable via keyboard (tabindex="0" per wireframe §3 — a bare <span>
    // isn't natively focusable without it).
    await expect(trigger).toHaveAttribute('tabindex', '0');
    // Dual-channel a11y pattern (exact GroupTypeContentHelp::infoTrigger()
    // shape, per wireframe §3): role="note" + aria-label + data-do-tooltip.
    await expect(trigger).toHaveAttribute('role', 'note');

    const ariaLabel = await trigger.getAttribute('aria-label');
    expect(ariaLabel).toBeTruthy();
    expect((ariaLabel ?? '').length).toBeGreaterThan(0);
    // Copy substring pinned by the brief/wireframe's own approved wording.
    expect(ariaLabel).toMatch(/adapts to the group's type/i);

    const tooltipData = await trigger.getAttribute('data-do-tooltip');
    expect(tooltipData).toBeTruthy();
    expect(tooltipData).toMatch(/adapts to the group's type/i);

    // Actually reachable by Tab (not just attributed) — focus it directly
    // and confirm it becomes the active element, proving it sits in the
    // normal focus order rather than being visually present but skipped.
    await trigger.focus();
    await expect(trigger).toBeFocused();
  });

  test('no axe-core dependency is available in this repo (documented gap, not silently skipped)', async () => {
    // manage-members.spec.ts already documents this exact gap (this repo's
    // package.json carries only `@playwright/test`, no
    // `@axe-core/playwright`). Rather than re-skip silently, this spec pins
    // the gap as an explicit assertion so a future `npm install
    // @axe-core/playwright` regression-tests itself: if the dependency IS
    // added, this test's premise (require.resolve throws) flips, which is
    // the intended signal to replace this stub with a real AxeBuilder scan
    // per docs/playbook/pipelines/website-frontend/core/tools/axe-check.cjs.
    let resolvable = true;
    try {
      // eslint-disable-next-line @typescript-eslint/no-var-requires
      require.resolve('@axe-core/playwright');
    }
    catch {
      resolvable = false;
    }
    expect(resolvable).toBe(false);
  });
});

test.describe('SC-3 — Regression guard: existing tab wiring unchanged on all exemplar pages (#122)', () => {
  for (const label of [
    'DrupalCon Portland 2026',
    'Core Committers',
    'Thunder Distribution',
    'Drupal France',
  ]) {
    test(`"${label}" still shows the Stream/Events/Members/About tabs in order`, async ({
      page,
    }) => {
      const url = await groupUrlByLabel(page, label);
      await page.goto(url);
      await expect(page.locator('.gc-group-tabs__link')).toHaveText([
        'Stream',
        'Events',
        'Members',
        'About',
      ]);
    });
  }
});
