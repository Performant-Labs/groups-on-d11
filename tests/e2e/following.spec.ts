import { test, expect, Page } from '@playwright/test';

/**
 * E2E for the following-scoped feed — story #111 ST-2 (`/following`).
 *
 * Pins the acceptance criteria from
 * docs/handoffs/111-stream-following/brief.md:
 *   - Anonymous -> /following -> 403 or login redirect (authenticated-only
 *     access model per brief step 1 delta 3, `access: role: authenticated`).
 *   - elena_garcia sees, each EXACTLY ONCE (dedupe across the 3 OR'd scope
 *     branches — do_streams_following_scope, see FollowingScope.php):
 *       * "Patch Review Process RFC"      (follow_content, seeded today)
 *       * a Maria-authored node           (follow_user, NEW seed this story)
 *       * a `core`-tagged node            (follow_term, seeded today)
 *       * a `drupalcon`-tagged node       (follow_term, NEW seed this story)
 *   - ravi_patel sees Maria-authored content (follow_user, seeded today).
 *   - sophie_mueller sees "Getting Started with Paragraphs" (follow_content,
 *     NEW seed this story).
 *   - A user with NO follows sees the empty state, with a keyboard-focusable
 *     link to /stream. (The empty-state copy originally also linked to /tags;
 *     that link was dropped per the O Phase-8 ADVISORY resolution — see
 *     decisions.md "O Phase-8 ADVISORY resolution" — because /tags 404s on
 *     this build. F's corresponding Phase-8 delta removed the /tags anchor
 *     from views.view.following_feed.yml's empty-state content string.)
 *   - WCAG-relevant DOM checks: an <h1>, accessible link names.
 *
 * The view `following_feed`, its route `/following`, and the three NEW seed
 * flags (elena->maria via follow_user, elena->drupalcon via follow_term,
 * sophie->paragraphs-tutorial via follow_content) do not exist yet — see
 * handoff-T-red.md for the RED verification. This is the intended RED: F
 * creates docs/groups/config/views.view.following_feed.yml (clone of
 * activity_stream.yml + 3 deltas) and appends the 3 new seed lines to
 * step_700_demo_data.php.
 *
 * Login helper + credential convention follow tests/e2e/directory-cards.spec.ts
 * and tests/e2e/demonstrator-seeds.spec.ts: seeded personas authenticate with
 * password `demo_password_2026` (see docs/groups/scripts/step_700_demo_data.php
 * line 25).
 *
 * "No follows" persona: alex_novak. Confirmed by inspection of the Step 750
 * flag block in step_700_demo_data.php — only elena_garcia, ravi_patel, and
 * sophie_mueller ever appear as the flagging viewer (3rd arg to
 * $flag_service->flag()) across follow_content/follow_user/follow_term.
 * alex_novak is only ever an author / event participant, never a follower,
 * so it is a stable "zero follows" fixture without needing runtime user
 * registration (which is itself environment-sensitive: email verification /
 * admin-approval settings vary by site config and are not what this
 * acceptance criterion is about).
 */

const SEEDED_PASSWORD = process.env.SEEDED_PASSWORD ?? 'demo_password_2026';
const NO_FOLLOWS_USER = 'alex_novak';

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
 * Counts how many times a card whose title link has EXACTLY this text
 * appears among the rendered stream_card rows on /following. Used for the
 * per-item dedupe assertion — a node satisfying more than one OR branch
 * (e.g. it's both directly followed AND tagged `core`) must still render as
 * a single card.
 */
async function countCardsWithTitle(page: Page, title: string): Promise<number> {
  return page.getByRole('link', { name: title, exact: true }).count();
}

test.describe('ST-2 — Following feed (#111)', () => {
  test('anonymous visiting /following gets 403 or a login redirect', async ({
    page,
  }) => {
    const res = await page.goto('/following');
    const status = res?.status() ?? 0;
    const redirectedToLogin = /\/user\/login/.test(page.url());
    // Authenticated-only access model (brief step 1, delta 3): Drupal's
    // `access: type: role, role: authenticated` view access plugin returns a
    // 403 for anonymous visitors; some site configs instead redirect
    // anonymous users to the login form. Either is acceptance-criterion
    // compliant per brief line 64.
    expect(status === 403 || redirectedToLogin).toBeTruthy();
  });

  test('elena_garcia sees all 4 following-scope branches, each exactly once', async ({
    page,
  }) => {
    await login(page, 'elena_garcia', SEEDED_PASSWORD);

    const res = await page.goto('/following');
    expect(res?.status()).toBe(200);

    // WCAG: the page has exactly one <h1>.
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);

    // Branch 1: follow_content (seeded today) — Elena directly follows this
    // node (docs/groups/scripts/step_700_demo_data.php:369-377).
    await expect(
      page.getByRole('link', { name: 'Patch Review Process RFC', exact: true }),
    ).toBeVisible();

    // Branch 2: follow_user (NEW seed) — Elena follows Maria; any
    // Maria-authored node should appear. "Sprint Planning: Portland 2026" and
    // "Weekly Standup Notes" are both authored by maria_chen in the existing
    // seed (step_700_demo_data.php:140,145) — assert at least one renders.
    const mariaAuthoredTitles = [
      'Sprint Planning: Portland 2026',
      'Weekly Standup Notes',
      'Budget Allocation Q3 2026',
    ];
    let mariaNodeVisible = false;
    for (const title of mariaAuthoredTitles) {
      if (await page.getByRole('link', { name: title, exact: true }).count()) {
        mariaNodeVisible = true;
        break;
      }
    }
    expect(
      mariaNodeVisible,
      'At least one Maria-authored node renders via the NEW follow_user seed (elena follows maria_chen).',
    ).toBeTruthy();

    // Branch 3: follow_term `core` (seeded today) — "Patch Review Process
    // RFC" and "Drupal 11 Migration Path" and "Weekly Standup Notes" are all
    // tagged `core`. "Drupal 11 Migration Path" is NOT authored by Maria and
    // is NOT the follow_content node, so it isolates the follow_term branch.
    await expect(
      page.getByRole('link', { name: 'Drupal 11 Migration Path', exact: true }),
    ).toBeVisible();

    // Branch 4: follow_term `drupalcon` (NEW seed) — Elena newly follows the
    // `drupalcon` tag. "Venue Logistics Thread" (james_okafor, tagged
    // drupalcon+logistics) isolates this branch: different author than
    // Maria, not the follow_content node, not tagged `core`.
    await expect(
      page.getByRole('link', { name: 'Venue Logistics Thread', exact: true }),
    ).toBeVisible();

    // Dedupe: "Patch Review Process RFC" matches BOTH follow_content (direct)
    // AND follow_term (`core`) for Elena. It must render exactly once, not
    // twice, proving the view's distinct/EXISTS-based scope does not fan out.
    expect(
      await countCardsWithTitle(page, 'Patch Review Process RFC'),
    ).toBe(1);
  });

  test('ravi_patel sees Maria-authored content via the existing follow_user seed', async ({
    page,
  }) => {
    await login(page, 'ravi_patel', SEEDED_PASSWORD);
    const res = await page.goto('/following');
    expect(res?.status()).toBe(200);

    // ravi follows maria_chen today (step_700_demo_data.php:391-395).
    const mariaAuthoredTitles = [
      'Sprint Planning: Portland 2026',
      'Weekly Standup Notes',
      'Budget Allocation Q3 2026',
    ];
    let mariaNodeVisible = false;
    for (const title of mariaAuthoredTitles) {
      if (await page.getByRole('link', { name: title, exact: true }).count()) {
        mariaNodeVisible = true;
        break;
      }
    }
    expect(
      mariaNodeVisible,
      'ravi_patel sees at least one Maria-authored node via the existing follow_user seed.',
    ).toBeTruthy();
  });

  test('sophie_mueller sees the Paragraphs tutorial via the NEW follow_content seed', async ({
    page,
  }) => {
    await login(page, 'sophie_mueller', SEEDED_PASSWORD);
    const res = await page.goto('/following');
    expect(res?.status()).toBe(200);

    await expect(
      page.getByRole('link', { name: 'Getting Started with Paragraphs', exact: true }),
    ).toBeVisible();
  });

  test('a user with no follows sees an accessible empty state', async ({
    page,
  }) => {
    await login(page, NO_FOLLOWS_USER, SEEDED_PASSWORD);
    const res = await page.goto('/following');
    expect(res?.status()).toBe(200);

    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);

    const emptyState = page.locator('.gc-empty');
    await expect(emptyState).toBeVisible();
    await expect(emptyState.locator('.gc-empty__title')).toContainText(
      "not following anything yet",
    );

    // Empty-state links to /stream, with an accessible name, and is
    // keyboard-focusable (a real <a href> is focusable by default; assert it
    // can receive focus, not merely that it exists in the DOM).
    //
    // The approved empty-state copy (brief.md line 47, as amended by the O
    // Phase-8 ADVISORY resolution) contains TWO links whose accessible name
    // matches /stream/i: an inline link with accessible name exactly "stream"
    // (inside the sentence "Browse the stream to find people, content, and
    // topics to follow.") AND a separate button-styled link with accessible
    // name "Browse the stream". A loose /stream/i regex matches both and
    // trips Playwright's strict-mode violation. Target the inline link
    // unambiguously via `exact: true` — its accessible name is the literal
    // string "stream", which the button's "Browse the stream" name does not
    // equal.
    //
    // The /tags link and its assertions were dropped in this Phase-8 delta:
    // the operator resolved U's ADVISORY finding (that /tags 404s on this
    // build) by removing the /tags anchor from the empty-state copy entirely
    // (decisions.md "O Phase-8 ADVISORY resolution", option (b)). F's
    // corresponding delta to views.view.following_feed.yml confirms no
    // remaining /tags reference in the empty-state content string.
    const streamLink = emptyState.getByRole('link', { name: 'stream', exact: true });
    await expect(streamLink).toBeVisible();
    await expect(streamLink).toHaveAttribute('href', /\/stream/);

    await streamLink.focus();
    await expect(streamLink).toBeFocused();
  });
});
