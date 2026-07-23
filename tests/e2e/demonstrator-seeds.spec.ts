import { test, expect, Page } from '@playwright/test';

/**
 * Issue #128 (SD-3) — Archive Demonstrator Seeds, anonymous-persona e2e.
 *
 * #128 redefines the seeded "Legacy Infrastructure" group from
 * PUBLISHED=0 (a stand-in "hidden" simulation of archiving) to
 * PUBLISHED=1 + Archive-typed (the real runbook semantic: Archived = visible +
 * read-only + badge, distinct from Unpublished = hidden). This spec is the
 * anonymous-visitor demonstrator the story exists to prove: an anonymous
 * visitor can discover Legacy Infrastructure is Archive-typed on
 * `/all-groups`, see the full Archive badge + tooltip on the group page
 * itself, and independently observe the archive's read-only enforcement and
 * the seeded pinned post's badge, all without logging in (except where
 * AC-1c requires an authenticated non-Organizer control, see below).
 *
 * All mechanisms exercised here already ship (#92 badge/tooltip, #143
 * restore, do_group_pin pin badge) — #128's only production change is
 * removing the redundant `$g->set('status', 0)` line from
 * step_700_demo_data.php's "Archive Legacy Infrastructure" block. This spec
 * therefore RED/GREENs purely on that seed change, with zero code changes to
 * any do_* module.
 *
 * --- AC-1a card-markup finding (T-RED, empirical, 2026-07-23) ------------
 * Probed the actual `/all-groups` (`.gc-directory-card`) markup with
 * Legacy Infrastructure's `status` temporarily flipped to 1 (simulating
 * F's fix). The card does NOT render `span.group__archived-badge` or any
 * `data-do-tooltip` attribute — those are emitted by
 * `ArchivePinHooks::preprocessGroup()` / `DoGroupExtrasHooks::preprocessGroup()`,
 * both `hook_preprocess_group` implementations that fire on a full GROUP
 * ENTITY render (the canonical `/group/{gid}` page). The `all_groups` View
 * renders each row via Views fields (a `.gc-directory-card` component
 * outside `docs/groups`, not in #128's Files In Scope), which does NOT
 * invoke `hook_preprocess_group` and therefore never gets that badge/tooltip
 * markup. What the card DOES render is a plain group-type taxonomy label:
 * `<span class="gc-badge gc-badge--primary gc-directory-card__type">Archive</span>`
 * (no tooltip). This is a real, pre-existing gap in the card component
 * (out of #128's scope to fix — the brief's Files In Scope is limited to
 * the seed script + 3 test files, no theme/template changes), not a test
 * authorship error. AC-1a is therefore asserted against what the card
 * ACTUALLY and CORRECTLY renders (the visible "Archive" type-badge text,
 * proving discoverability), while the full real badge + tooltip assertion
 * is reserved for AC-1b (the group page, where it legitimately renders).
 * Flagged for F/O: consider a follow-up ticket for the `.gc-directory-card`
 * component to surface the archive tooltip directly on the card (out of
 * #128's non-goals: "No new HelpText, no copy edits... no visual/CSS
 * changes").
 * --------------------------------------------------------------------------
 *
 * --- AC-1c enforcement-path decision (T-RED, empirical, 2026-07-23) -------
 * Two candidate anonymous-reachable content-create routes were probed against
 * a live copy of gid=8 (Legacy Infrastructure) with `status` temporarily
 * flipped to 1 (simulating F's fix) to determine which route the archive
 * enforcement actually gates:
 *
 *   - `/node/add/forum?group={gid}`            -> 403 for ANONYMOUS on EVERY
 *     group tested, archived or not (gid=1 DrupalCon Portland, published,
 *     non-archive: 403; gid=8 Legacy Infrastructure, published, archive: 403).
 *     This is the "truism, not enforcement" case flagged in the brief:
 *     anonymous cannot reach this route at all, regardless of archive state,
 *     so it proves nothing about archive-specific denial.
 *   - `/group/{gid}/content/create/group_node%3Aforum` -> also 403 for
 *     ANONYMOUS on every group (same truism — anonymous lacks the site-wide
 *     "create group content" permission needed to even attempt this route).
 *     BUT tested as an AUTHENTICATED NON-ORGANIZER user (elena_garcia, a
 *     seeded member of other groups but NOT a member of Legacy
 *     Infrastructure), this route returns:
 *       - 403 on gid=8 (Legacy Infrastructure, Archive-typed, published)
 *       - 200 on gid=3 (Core Committers, non-archive, elena IS a member)
 *     This is the archive-driven signal AC-1c requires: the SAME route,
 *     the SAME user, differs ONLY by the target group's Archive-type,
 *     proving `DoGroupExtrasHooks::nodeAccess()` (or an equivalent access
 *     check on this route) is the effective, observable enforcement path —
 *     contrary to survey.md's flagged concern that this route bypasses
 *     `hook_node_access` via `_group_relationship_create_any_entity_access`.
 *     In THIS build, the route is in fact gated.
 *
 * Decision: AC-1c is asserted via `/group/{gid}/content/create/group_node%3Aforum`
 * with the authenticated non-Organizer persona (elena_garcia / seeded
 * password), NOT anonymous — anonymous cannot reach this route at all so it
 * cannot serve as the archive-vs-non-archive differential signal. This is a
 * stronger proof than an anonymous 403 would have been (which could not be
 * distinguished from "anonymous can never create content").
 * --------------------------------------------------------------------------
 *
 * --- AC-2 anonymous surface decision (T-RED, empirical, 2026-07-23) -------
 * Sprint Planning ("Sprint Planning: Portland 2026") is already pinned in
 * DrupalCon Portland 2026 (`open` visibility). Probed three anonymous
 * surfaces:
 *   - `/group/1` (DrupalCon Portland 2026 group page)   -> NO pin-badge markup
 *   - `/group/1/stream` (the group's content stream view) -> NO pin-badge markup
 *   - `/node/1` (Sprint Planning's own canonical node page) -> renders
 *     `<span class="pin-badge" data-do-tooltip="...">Pinned</span>` for
 *     ANONYMOUS visitors right now, on the CURRENT (pre-#128) seed.
 * The group/stream listing pages render nodes in a teaser/fields view mode
 * that does not include `title_suffix` (where do_group_pin/ArchivePinHooks
 * attach the badge), so only the node's own canonical page surfaces it.
 * AC-2 is therefore a REGRESSION GUARD, not a RED-driver for #128: it already
 * passes today and must keep passing after the seed change (nothing in
 * #128's diff touches the pin flag or DrupalCon Portland 2026 visibility).
 * --------------------------------------------------------------------------
 *
 * AC-3 (archived != unpublished holds in the seed, i.e. no
 * `set("status", 0)` remains in step_700_demo_data.php) is diff-verifiable —
 * F's diff itself proves it (a grep, not a browser assertion); not encoded
 * as a Playwright test here.
 *
 * AC-4 (seed idempotency) is out of scope for e2e: the seeded site this
 * suite runs against has already executed the seed scripts once; re-run
 * idempotency is a property of step_700's existing `loadByProperties` guard
 * (unaffected by this change) and is not independently re-provable by a
 * browser-driven spec.
 *
 * Self-contained: own login helper (mirrors group-restore.spec.ts /
 * manage-members.spec.ts's pattern), no imports from other specs.
 */

const SEEDED_NON_ORGANIZER_USER = 'elena_garcia';
const SEEDED_NON_ORGANIZER_PASS = 'demo_password_2026';

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
 * Resolves the numeric group id of a card on `/all-groups` by its visible
 * label, and returns it. Assumes the caller has already asserted the card
 * is present (this fails loudly via Playwright's own locator timeout if not).
 */
async function gidFromAllGroupsCard(page: Page, labelPattern: RegExp): Promise<string> {
  const link = page.locator('.gc-directory-card__title a', { hasText: labelPattern }).first();
  await expect(link).toBeVisible();
  const href = await link.getAttribute('href');
  const gid = href?.match(/\/group\/(\d+)/)?.[1];
  expect(gid, 'card link resolves to a numeric group id').toBeTruthy();
  return gid as string;
}

/**
 * Finds a non-archive group id that SEEDED_NON_ORGANIZER_USER is a member of
 * (used as the AC-1c control group — same user, same route, non-archive
 * group, to prove the denial on Legacy Infrastructure is archive-driven and
 * not merely "elena isn't a member anywhere").
 */
async function gidOfControlGroup(page: Page, labelPattern: RegExp): Promise<string> {
  await page.goto('/all-groups');
  return gidFromAllGroupsCard(page, labelPattern);
}

test.describe('#128 SD-3 — Archive demonstrator seeds (anonymous persona)', () => {
  test('AC-1a: anonymous sees Legacy Infrastructure on /all-groups, tagged with the Archive type', async ({
    page,
  }) => {
    const res = await page.goto('/all-groups');
    expect(res?.status()).toBe(200);

    const card = page.locator('.gc-directory-card', { hasText: /Legacy Infrastructure/i });
    await expect(card).toBeVisible();

    // See file header "AC-1a card-markup finding": the directory card
    // renders the group-type taxonomy label via Views fields (not a full
    // group-entity preprocess), so the discoverable signal here is the
    // visible "Archive" type badge -- NOT `span.group__archived-badge`
    // (that markup only renders on the group's own canonical page, see
    // AC-1b below, where the full badge + tooltip assertion belongs).
    const typeBadge = card.locator('.gc-directory-card__type', { hasText: /^Archive$/ });
    await expect(typeBadge).toBeVisible();
  });

  test('AC-1b: clicking the Legacy Infrastructure card lands on the group page showing the Archived state (badge + tooltip)', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    const gid = await gidFromAllGroupsCard(page, /Legacy Infrastructure/i);

    const res = await page.goto(`/group/${gid}`, { waitUntil: 'domcontentloaded' });
    expect(res?.status()).toBe(200);
    await expect(page).toHaveURL(new RegExp(`/group/${gid}$`));

    const badge = page.locator('span.group__archived-badge');
    await expect(badge).toBeVisible();
    await expect(badge).toContainText(/Archived/i);
    const tooltip = await badge.getAttribute('data-do-tooltip');
    expect(tooltip, 'Archive badge carries a non-empty tooltip on the group page').toBeTruthy();
  });

  test('AC-1c: content-create is denied on the archived group but allowed on a non-archived control group for the same non-Organizer user', async ({
    page,
  }) => {
    // See file header "AC-1c enforcement-path decision" — anonymous cannot
    // reach `/group/{gid}/content/create/...` at all (403 regardless of
    // archive state), so the differential signal requires an authenticated
    // non-Organizer persona.
    await login(page, SEEDED_NON_ORGANIZER_USER, SEEDED_NON_ORGANIZER_PASS);

    await page.goto('/all-groups');
    const archivedGid = await gidFromAllGroupsCard(page, /Legacy Infrastructure/i);
    // Core Committers: non-archive, elena_garcia is a seeded member (see
    // step_700_demo_data.php Step 730a memberships table).
    const controlGid = await gidOfControlGroup(page, /Core Committers/i);

    const archivedRes = await page.goto(
      `/group/${archivedGid}/content/create/group_node%3Aforum`,
      { waitUntil: 'domcontentloaded' },
    );
    expect(
      archivedRes?.status(),
      'content-create is DENIED on the Archive-typed group',
    ).toBe(403);

    const controlRes = await page.goto(
      `/group/${controlGid}/content/create/group_node%3Aforum`,
      { waitUntil: 'domcontentloaded' },
    );
    expect(
      controlRes?.status(),
      'the SAME route, SAME user, is ALLOWED on a non-archived group -- proving the denial above is archive-driven, not a blanket permission gap',
    ).toBe(200);
  });

  test('AC-2 (regression guard): anonymous sees the Pinned badge + tooltip on the pinned post\'s canonical page', async ({
    page,
  }) => {
    // See file header "AC-2 anonymous surface decision" -- the group page and
    // stream listing do NOT render title_suffix (where the pin badge is
    // attached), only the node's own canonical page does.
    const res = await page.goto('/node/1');
    expect(res?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
      /Sprint Planning: Portland 2026/i,
    );

    const badge = page.locator('span.pin-badge');
    await expect(badge).toBeVisible();
    await expect(badge).toContainText(/Pinned/i);
    const tooltip = await badge.getAttribute('data-do-tooltip');
    expect(tooltip, 'Pin badge carries a non-empty tooltip').toBeTruthy();
  });
});
