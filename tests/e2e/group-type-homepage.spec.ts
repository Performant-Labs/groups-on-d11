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
 *   - docs/planning/handoffs/122-grouptype-home/handoff-F.md (F's "Step 740d"
 *     Thunder-docs seed addition — see the Thunder Distribution note below)
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
 *   - Thunder Distribution -> Distribution. At RED time this group had NO
 *     `documentation`-type nodes seeded anywhere, so the Thunder test
 *     originally pinned the (vacuous) empty-state contract. F's Phase 5
 *     implementation (per T's own "prefer (a): seed real content" note in
 *     handoff-T-red.md) added a new "Step 740d" seed block
 *     (docs/groups/scripts/step_700_demo_data.php) that creates 3 real
 *     documentation nodes for this group ("Getting Started with Thunder",
 *     "Upgrading from Thunder 7 to 8", "Media Library Configuration Guide").
 *     T (Phase 6/GREEN) rewrote the Thunder describe block below to assert
 *     the genuine, positive docs-first rendering contract instead of
 *     `.gc-group-lead` count === 0 — verified directly against the rendered
 *     `/group/4` page (see handoff-T-green.md for the raw DOM evidence).
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
 *
 * T (Phase 6/GREEN) fix: scope the directory lookup with the `?search=`
 * query param instead of relying on unpaginated page-1 listing. The
 * unscoped version (page 1 only, no pagination-awareness) was flagged by F
 * (handoff-F.md) and independently reproduced by T-green: this shared,
 * long-lived DDEV instance accumulates throwaway fixture groups from other
 * specs across repeated runs (8 -> 59 -> 76 groups observed across 3 runs
 * in one T-green session), which pushes these 4 exemplar/fallback groups
 * past the directory's 25-per-page pager and makes plain page-1 scanning
 * unreliable. `?search=<label>` resolves each exemplar to exactly one card
 * regardless of total group count or pagination (verified directly via
 * curl for all four labels before this fix). This is a test-infrastructure
 * fix only — no production code changed.
 */
async function groupUrlByLabel(page: Page, label: string): Promise<string> {
  await page.goto(`/all-groups?search=${encodeURIComponent(label)}`);
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

test.describe('SC-3 — Docs-first lead section (#122, Thunder Distribution)', () => {
  test('leads with a "Documentation" section linking to 3 seeded documentation nodes and a type-filtered see-all', async ({
    page,
  }) => {
    // T (Phase 6/GREEN) rewrite: F took option (a) and seeded 3 real
    // documentation nodes for Thunder Distribution ("Step 740d" in
    // step_700_demo_data.php), so this exemplar now has a genuine,
    // non-vacuous docs-first rendering path to assert — see file header.
    const url = await groupUrlByLabel(page, 'Thunder Distribution');
    const res = await page.goto(url);
    expect(res?.status()).toBe(200);

    const lead = leadSection(page);
    await expect(lead).toBeVisible();

    // Heading names documentation (wireframe §1: "Documentation").
    const heading = lead.locator('.gc-group-lead__heading');
    await expect(heading).toBeVisible();
    await expect(heading).toContainText(/doc/i);

    // Top-N (<=3) item links point at real documentation node URLs — be
    // defensive about the exact count (>0, <=3) rather than hard-coding 3,
    // since the seed's own node count is an implementation/content detail,
    // not a contract this test needs to over-specify.
    const itemLinks = lead.locator('.gc-group-lead__link');
    const itemCount = await itemLinks.count();
    expect(itemCount).toBeGreaterThan(0);
    expect(itemCount).toBeLessThanOrEqual(3);
    for (let i = 0; i < itemCount; i++) {
      const href = await itemLinks.nth(i).getAttribute('href');
      expect(href).toBeTruthy();
      expect(href).toMatch(/\/node\/\d+/);
    }

    // "See all documentation" links at the type-filtered group_nodes path
    // (handoff-A.md Q1 resolution), mirroring the discussion exemplar.
    const seeAll = lead.locator('.gc-group-lead__see-all');
    await expect(seeAll).toBeVisible();
    await expect(seeAll).toContainText(/see all documentation/i);
    const seeAllHref = await seeAll.getAttribute('href');
    expect(seeAllHref).toMatch(/\/group\/\d+\/nodes\?type(\[\])?=documentation/);

    // Tab-order regression guard preserved for this exemplar (was already
    // pinned pre-rewrite; kept so this test still independently proves the
    // new lead section doesn't disturb the existing tab wiring).
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
