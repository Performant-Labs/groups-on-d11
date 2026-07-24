import { test, expect, Page } from '@playwright/test';

/**
 * E2E for the "Recent posts" profile-activity block — story #114 ST-5
 * (`/user/{uid}`).
 *
 * Pins the acceptance criteria from
 * docs/planning/handoffs/st5-profile-114/brief.md + wireframe.md:
 *   - A `<h2>` heading "Recent posts" renders on the profile owner's
 *     `/user/{uid}` page (wireframe "Row anatomy" + WCAG heading-level note).
 *   - Maria Chen's profile lists (at least) her three seeded topics —
 *     "Sprint Planning: Portland 2026", "Weekly Standup Notes", "Budget
 *     Allocation Q3 2026" (step_700_demo_data.php lines 140/145/149).
 *
 * The `user_activity` view, its block placement
 * (block.block.do_streams_user_activity.yml), and profile-activity.css do
 * not exist yet (brief.md "Files touched", all NEW) — this is the intended
 * RED. F creates: docs/groups/config/views.view.user_activity.yml,
 * docs/groups/config/block.block.do_streams_user_activity.yml,
 * docs/groups/modules/do_streams/css/profile-activity.css.
 *
 * NOT re-asserted at the E2E layer (both intentionally, on T rev-1 re-verify,
 * 2026-07-23 — see docs/planning/handoffs/st5-profile-114/decisions.md):
 *
 *   - Newest-first ordering (created DESC). Originally asserted here via DOM
 *     order of Maria's three seeded titles, on the premise that
 *     step_700_demo_data.php's sequential `$node->save()` calls produce
 *     strictly increasing `created` timestamps. Verified false: all three of
 *     Maria's seeded nodes land in the SAME second (`node_field_data.created`
 *     = 1784847624 for nids 1/6/10, confirmed via direct SQL query against a
 *     seeded site) because the seed script never sets an explicit `created`
 *     value and executes fast enough that second-granularity timestamps tie.
 *     A tied `created DESC` sort legitimately falls back to natural/nid
 *     order (ascending), so the live page correctly renders oldest-first for
 *     THIS seed data — that is not a view bug, it is this test's premise
 *     being wrong. Ordering is already fully and rigorously covered by the
 *     Kernel test `UserActivityViewTest::testResultsOrderNewestFirst`, which
 *     sets three explicit, strictly-increasing `created` values
 *     ($base/$base+100/$base+200) specifically to make the DESC sort
 *     observable — the correct tier for this assertion. Deleted the invalid
 *     DOM-order assertion here rather than re-authoring a seed-data fix,
 *     since the behavior is already pinned at the cheaper, correct tier.
 *
 *   - Outsider/non-member access-scoping (partial render: public-group title
 *     visible, private-group titles absent). Originally asserted here via an
 *     ANONYMOUS visit; O scoped that out (#114, 2026-07-23 decision) as not
 *     what the brief's "outsider viewer" AC means, and directed re-authoring
 *     against a logged-in non-member persona (`ravi_patel` — a member of
 *     "DrupalCon Portland 2026" only, confirmed NOT a member of "Core
 *     Committers" or "Leadership Council", Maria's two private groups; see
 *     step_700_demo_data.php lines 98/99/101). Re-run against that persona
 *     surfaced a SEPARATE, genuine site-level gap: Drupal core's own
 *     `UserAccessControlHandler::checkAccess()` requires the
 *     `access user profiles` permission (or `administer users`) to view ANY
 *     other user's `/user/{uid}` page, and this repo's `authenticated` role
 *     does not grant it (confirmed via `drush role:list --format=json` —
 *     `authenticated`'s perms list has no `access user profiles` entry) — so
 *     EVERY non-admin authenticated persona 403s on someone else's profile,
 *     not just anonymous. This is a pre-existing baseline-permissions gap
 *     unrelated to do_streams (no other spec in this repo visits another
 *     user's `/user/{uid}` as a non-admin persona; phase4.spec.ts's
 *     contribution-stats test logs in as `admin` and visits `/user/1` — its
 *     OWN profile, uid 1 short-circuits via `administer users` before
 *     `access user profiles` is ever consulted). Fixing it is a site-config
 *     change outside this story's scope and outside T's authority to make
 *     unilaterally. DELETED this E2E case as redundant coverage per O's
 *     accepted fallback option: the exact behavior (access-scoped partial
 *     render for a non-member viewer) is already fully pinned by the Kernel
 *     test `UserActivityViewTest::testAccessScopingExcludesPrivateGroupNodeForNonMember`
 *     (+ its sanity companion `...IncludesPrivateGroupNodeForMember`), which
 *     exercises the view directly with an explicit uid argument and does not
 *     depend on the separate, currently-broken `/user/{uid}` route-access
 *     permission at all. Once the site-level permission gap is resolved (a
 *     follow-up outside #114), an E2E case can be re-added cheaply against
 *     `ravi_patel`.
 *
 * Login helper + credential convention follow tests/e2e/following.spec.ts /
 * tests/e2e/directory-cards.spec.ts: seeded personas authenticate with
 * password `demo_password_2026` (docs/groups/scripts/step_700_demo_data.php
 * line 25). Maria's uid is not fixed in the seed script (auto-assigned by
 * user_load_by_name at seed time) — resolved here the same way
 * tests/e2e/*.spec.ts resolve dynamic ids elsewhere (e.g. manage-members.spec.ts
 * / phase3.spec.ts capturing a gid/nid from `page.url()`): the login helper's
 * post-login redirect lands on `/user/{uid}` for the just-authenticated
 * account, so Maria's own uid is captured directly from that URL rather than
 * hardcoded or looked up via drush from within the spec (Playwright specs in
 * this repo do not shell out to drush).
 */

const SEEDED_PASSWORD = process.env.SEEDED_PASSWORD ?? 'demo_password_2026';

const MARIA_TITLES = [
  'Sprint Planning: Portland 2026',
  'Weekly Standup Notes',
  'Budget Allocation Q3 2026',
];

/** Log in via the real /user/login form; returns the authenticated uid captured from the post-login redirect. */
async function loginAndGetUid(page: Page, user: string, pass: string): Promise<string> {
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
  const match = page.url().match(/\/user\/(\d+)/);
  expect(match, 'Post-login redirect resolves to /user/{uid}.').not.toBeNull();
  return match![1];
}

test.describe('ST-5 — Profile activity stream (#114)', () => {
  test('"Recent posts" section renders on Maria\'s profile with her seeded topics', async ({
    page,
  }) => {
    const uid = await loginAndGetUid(page, 'maria_chen', SEEDED_PASSWORD);

    const res = await page.goto(`/user/${uid}`, { waitUntil: 'domcontentloaded' });
    expect(res?.status()).toBe(200);

    // Wireframe: block heading is an <h2>, text "Recent posts".
    const heading = page.getByRole('heading', { level: 2, name: 'Recent posts' });
    await expect(heading).toBeVisible();

    // The section (identified by the heading's containing block wrapper) lists
    // at least Maria's three seeded topics.
    const section = page.locator('.do-streams-profile-activity');
    await expect(section).toBeVisible();

    for (const title of MARIA_TITLES) {
      await expect(
        section.getByRole('link', { name: title, exact: true }),
      ).toBeVisible();
    }
  });
});
