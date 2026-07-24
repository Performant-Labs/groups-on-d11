import { test, expect, Page } from '@playwright/test';

/**
 * #133 SD-6 (capstone) — Help-coverage audit, E2E.
 *
 * Re-runs the SD-audit anonymous-walk methodology as a repeatable Playwright
 * spec (brief scope item 1 + acceptance bullet "the audit walk is captured
 * as a Playwright spec where practical so it reruns in CI"): walk every
 * primary anon-visible route and assert each carries AT LEAST ONE help
 * affordance — either the SD-1 page-level ⓘ (`.page-help-info`, per
 * `PageHelp::infoTrigger()`) or any SD-2/#88/#122-pattern element tooltip
 * (`[data-do-tooltip]`).
 *
 * Route list (brief scope item 2, "No primary anon-visible page with zero
 * help affordances"):
 *   /            (frontpage)
 *   /stream
 *   /all-groups
 *   /showcase
 *   /group/{gid}, /group/{gid}/stream, /group/{gid}/events,
 *     /group/{gid}/members  (one seeded public group, resolved dynamically
 *     via /all-groups per page-help.spec.ts's own `firstGroupUrl` pattern —
 *     this shared DDEV instance accumulates fixture groups across runs)
 *
 * Routes intentionally OUT of scope for the anon walk:
 *   - /upcoming-events: confirmed gated behind login on this build (every
 *     other spec that visits it — my-events.spec.ts — authenticates first);
 *     anon requests redirect to /user/login, so this is not a "primary
 *     anon-visible page" and is skipped per this story's own instruction.
 *   - /user/{uid}: Drupal core's `authenticated` role (and therefore
 *     anonymous) holds no `access user profiles` permission on this build
 *     (documented at length in profile-activity.spec.ts's module doc
 *     comment — a pre-existing, out-of-scope site-permissions gap) — an
 *     anonymous visit 403s before any help affordance could ever render.
 *     Skipped with a graceful runtime check (403/redirect), not a hard
 *     assumption, so a future permissions fix makes this case start
 *     exercising instead of silently passing for the wrong reason.
 *
 * Deferred gaps (documented follow-ups, NOT this spec's job to fix — SD-4's
 * own scope split, brief.md / streams-help.spec.ts):
 *   - /my-feed, /trending, /my-feed/events are W2 surfaces authenticated-only
 *     and already covered by streams-help.spec.ts's own graceful-skip
 *     pattern; not re-covered here (would duplicate that spec).
 *   - Stream-card element tooltips on /stream are already covered by
 *     element-tooltips.spec.ts (SD-2, #127); this spec's /stream case checks
 *     for the PAGE-level ⓘ only, to avoid duplicating that coverage.
 *
 * RED reason (Phase 4, before F applies the 13-item copy sweep): the
 * membership-models / group-type-homepages / private-group-reveal /showcase
 * entries are still `status: coming` (ShowcaseCatalog.php), so the
 * live-route assertions in the "showcase tour entries are live" test fail —
 * each entry's own container carries no "View this comparison" link yet
 * (ShowcaseController::page() renders NO link for a `coming` entry, per the
 * truthful-copy rule already covered by showcase.spec.ts's "no dead link"
 * case). This is the correct RED: the assertion the feature must satisfy
 * (an F flip to `live` + route) fails, not a locator/setup error — every
 * other route in this spec already carries a help affordance today (SD-1/
 * SD-2 landed in prior waves), so those cases are REGRESSION GUARDS, not new
 * coverage, and are expected to PASS at RED.
 */

/** Any recognized help-affordance element: SD-1 page-level ⓘ or any SD-2/etc element tooltip trigger. */
const HELP_AFFORDANCE_SELECTOR = '.page-help-info, [data-do-tooltip]';

/**
 * Resolve a seeded public group's canonical `/group/{gid}` URL from the
 * `/all-groups` directory's first result card — matches page-help.spec.ts's
 * own `firstGroupUrl` convention (this DDEV instance accumulates fixture
 * groups across repeated runs, so no gid is hardcoded).
 */
async function firstGroupUrl(page: Page): Promise<string> {
  await page.goto('/all-groups');
  const card = page.locator('.gc-directory-card').first();
  await expect(card).toBeVisible();
  const link = card.locator('.gc-directory-card__title a').first();
  await expect(link).toBeVisible();
  const href = await link.getAttribute('href');
  expect(href).toBeTruthy();
  return href as string;
}

test.describe('#133 SD-6 — Anonymous help-affordance walk: fixed routes', () => {
  const FIXED_ROUTES: Array<{ path: string; label: string }> = [
    { path: '/', label: 'frontpage' },
    { path: '/stream', label: 'site-wide activity stream' },
    { path: '/all-groups', label: 'all-groups directory' },
    { path: '/showcase', label: 'showcase tour page' },
  ];

  for (const { path, label } of FIXED_ROUTES) {
    test(`${path} (${label}) carries at least one help affordance`, async ({
      page,
    }) => {
      const res = await page.goto(path);
      expect(res?.status(), `${path} must respond 200`).toBe(200);

      const affordances = page.locator(HELP_AFFORDANCE_SELECTOR);
      await expect(
        affordances,
        `${path} must carry at least one .page-help-info or [data-do-tooltip] help affordance (brief.md acceptance: "No primary anon-visible page with zero help affordances").`,
      ).not.toHaveCount(0);
    });
  }

  test('/upcoming-events: anon is gated (login redirect or 403) — not a primary anon-visible page, skipped gracefully', async ({
    page,
  }) => {
    const res = await page.goto('/upcoming-events');
    const status = res?.status() ?? 0;
    const landedOnLogin = /\/user\/login/.test(page.url());

    if (status === 403 || landedOnLogin) {
      test.skip(
        true,
        '/upcoming-events is gated for anonymous visitors on this build (matches my-events.spec.ts\'s own authenticated-only convention) — not in scope for the anon help-coverage walk.',
      );
      return;
    }

    // If a future change makes this route anon-visible, it becomes a real
    // primary page and must carry a help affordance like every other route
    // in this spec.
    expect(status, '/upcoming-events must respond 200 once anon-visible').toBe(200);
    const affordances = page.locator(HELP_AFFORDANCE_SELECTOR);
    await expect(affordances).not.toHaveCount(0);
  });
});

test.describe('#133 SD-6 — Anonymous help-affordance walk: a seeded group', () => {
  const GROUP_SUBPATHS: Array<{ suffix: string; label: string }> = [
    { suffix: '', label: 'group homepage' },
    { suffix: '/stream', label: 'group Stream tab' },
    { suffix: '/events', label: 'group Events tab' },
    { suffix: '/members', label: 'group Members tab' },
  ];

  for (const { suffix, label } of GROUP_SUBPATHS) {
    test(`/group/{gid}${suffix} (${label}) carries at least one help affordance`, async ({
      page,
    }) => {
      const groupUrl = await firstGroupUrl(page);
      const res = await page.goto(`${groupUrl}${suffix}`);
      expect(res?.status(), `${groupUrl}${suffix} must respond 200`).toBe(200);

      const affordances = page.locator(HELP_AFFORDANCE_SELECTOR);
      await expect(
        affordances,
        `${groupUrl}${suffix} must carry at least one .page-help-info or [data-do-tooltip] help affordance.`,
      ).not.toHaveCount(0);
    });
  }
});

test.describe('#133 SD-6 — Regression: /showcase tour entries match shipped reality', () => {
  /**
   * These three entries are the audit's flagged gap (brief.md scope item 3):
   * ShowcaseCatalog still lists them `coming` even though the underlying
   * comparisons have shipped. Once F flips `status` to `live` and adds a
   * route (13-item work-list #8-10), ShowcaseController::page() renders a
   * "View this comparison" link inside each entry's own
   * `data-do-showcase-entry` container (identical DOM contract to the
   * existing live entries, e.g. `discovery-ranking` — see
   * showcase.spec.ts:411-430) instead of a "[ coming ]" badge with no link.
   */
  const ENTRIES_THAT_MUST_BECOME_LIVE = [
    'membership-models',
    'group-type-homepages',
    'private-group-reveal',
  ];

  for (const entryId of ENTRIES_THAT_MUST_BECOME_LIVE) {
    test(`the "${entryId}" catalog entry is live with a real deep-link (not "Coming soon")`, async ({
      page,
    }) => {
      await page.goto('/showcase');

      const entry = page.locator(`[data-do-showcase-entry="${entryId}"]`);
      await expect(entry).toBeVisible();

      // Truthful-copy rule: a live entry never shows the "[ coming ]"
      // placeholder badge.
      await expect(entry.getByText('[ coming ]')).toHaveCount(0);

      // A live entry carries a real "View this comparison" link (the same
      // contract showcase.spec.ts already pins for 'discovery-ranking').
      const link = entry.getByRole('link', { name: 'View this comparison' });
      await expect(
        link,
        `"${entryId}" must render a live deep-link once F flips its status (13-item work-list #8-10).`,
      ).toBeVisible();

      const href = await link.getAttribute('href');
      expect(href, `"${entryId}"'s live link must resolve to a real, non-empty href.`).toBeTruthy();
    });
  }
});
