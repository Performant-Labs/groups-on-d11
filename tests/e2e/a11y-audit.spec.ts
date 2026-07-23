import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Issue #145 (MC-A11Y) — WCAG 2.2 AA automated audit sweep.
 *
 * One `test(...)` per named surface (per survey.md's "Audit method" +
 * A's Phase-3 review: per-route `test(...)` loop, not describe-per-surface,
 * matching phase4.spec.ts's multi-surface-single-spec shape and keeping
 * waivers grep-able as `test.skip(...)` declarations).
 *
 * Each test runs axe-core (wcag2a/aa, wcag21a/aa, wcag22aa tags) against the
 * rendered page and fails only on 'serious' or 'critical' impact violations
 * (brief.md Acceptance: "Zero serious/critical axe violations across the
 * eight surfaces"). Minor/moderate findings are recorded in the audit table
 * but do not fail the suite — the POC bar per brief/survey.
 *
 * Surfaces (brief.md "Surfaces (fixed list - do not expand)"):
 *   /, /all-groups (the real route behind the brief's shorthand "/groups" -
 *     see nav.spec.ts/directory-cards.spec.ts, which all use /all-groups;
 *     no /groups route exists in this codebase), /group/{seed}, /showcase,
 *     the persona-switcher surface (reached via `/` — see "Persona-switcher
 *     route correction" below; the brief's shorthand "/personas" is not a
 *     real route), /group/{seed}/members, /group/add/{type}, and one
 *     representative /do-streams/demo/{scope} route (do_streams ships
 *     `do-streams/demo/membership|following|global` per
 *     do_streams/config/install/views.view.do_streams_demo.yml — there is no
 *     literal `/streams/*` route in this codebase; `global` is the
 *     representative scope, matching survey.md's "one representative route").
 *
 * Group-seed / group-type resolution mirrors existing spec convention
 * (group-about.spec.ts / group-links.spec.ts): resolve a real seeded group
 * via the public /all-groups directory rather than assuming a stable gid.
 * Group type for /group/add/{type} mirrors create-group.spec.ts /
 * nav.spec.ts's `community_group` machine name.
 *
 * Persona-switcher route correction (T-GREEN, Phase 6): the brief/survey's
 * shorthand "/personas" is not a real route — confirmed via `grep` across
 * every `*.routing.yml` under docs/groups/modules/ (only `/showcase` and
 * `/persona-switch/{persona}` exist in do_showcase.routing.yml) and via
 * `curl`, which 404s. F's handoff-F.md independently reconfirmed this at
 * GREEN. The persona banner/switcher is embedded UI on the front page, not a
 * standalone page (see persona-switcher.spec.ts, which always navigates via
 * `/` + the switcher widget). Rather than assert against a 404 (a
 * false-positive "0 violations" pass that validates nothing — axe would just
 * be scanning Drupal's generic 404 page), this spec's persona-surface test
 * below navigates to `/` and scopes its axe scan with `.include()` to the
 * persona-switcher widget/banner region specifically, so it pins real
 * behavior distinct from the already-covered "/ (front page)" test (which
 * scans the whole page, not this widget in isolation).
 *
 * Waivers (brief.md "Out of scope / waivers"): RTL toggle (display-only, no
 * seeded RTL locale) and maps (no maps surface in the demo) are documented
 * as `test.skip(condition, reason)` calls inside a `test(...)` body below —
 * the same in-test-body waiver shape already used by this repo's
 * manage-members.spec.ts (`test.skip(!pendingRowExists, '...')`). Grep for
 * `test.skip(` to find them. (T-GREEN fix: the previous Phase-4 authoring
 * used `test.skip(title, reasonString)` at file/module scope — an invalid
 * Playwright overload. Per Playwright's `TestTypeImpl._modifier()`, when the
 * second argument isn't a function, `test.skip(title, string)` at file-load
 * time is interpreted as a whole-suite skip condition, which silently
 * skipped ALL 10 declarations in this file, not just the two intended
 * waivers — a false "everything skipped, 0 violations" pass. Confirmed by F
 * (handoff-F.md) via `node_modules/playwright/lib/common/index.js` and an
 * empirical scratch repro. Fixed by declaring each waiver as its own real
 * `test(...)` that calls `test.skip(true, reason)` in its body — this is a
 * valid 2-arg overload (condition, reason) and cannot leak file-wide.)
 *
 * RED reason (Phase 4, before F): `@axe-core/playwright` is not yet an
 * installed devDependency (package.json only lists `@playwright/test` at
 * RED time — this spec's own `package.json` edit adds the entry but F must
 * run `npm install`), so every test below fails at import/module-resolution
 * time with a "Cannot find module '@axe-core/playwright'" error until F
 * installs the dependency — never a false-negative "no violations found"
 * green. Once installed, on the live seeded site, tests may additionally
 * surface real serious/critical violations for F to fix at their source
 * (brief's a11y-only fix envelope) or downgrade to a documented waiver if a
 * fix would require a module refactor (survey.md "Fixes envelope").
 */

const REPORT_PATH = path.join('test-results', 'a11y-audit.md');

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

// Seeded community groups (step_700_demo_data.php), mirrors
// group-about.spec.ts / group-links.spec.ts's SEEDED_GROUP_LABELS / GROUP_LABEL.
const GROUP_LABEL = 'DrupalCon Portland 2026';

// The community_group machine name used throughout this repo's specs
// (create-group.spec.ts, nav.spec.ts) for /group/add/{type}.
const GROUP_TYPE = 'community_group';

// do_streams ships three demo scope routes (views.view.do_streams_demo.yml);
// 'global' is the representative one per survey.md "one representative route".
const STREAMS_ROUTE = '/do-streams/demo/global';

// Persona-switcher widget selector, matching persona-switcher.spec.ts's own
// convention (embedded UI reached via `/`, not a standalone page).
const PERSONA_SWITCHER_SELECTOR = 'form.do-showcase-persona-switcher-form';

/**
 * Resolves a seeded group's canonical URL via the public directory, mirroring
 * group-about.spec.ts's gotoGroupByLabel — gids are not stable across re-seeds.
 */
async function resolveSeededGroupPath(page: Page): Promise<string> {
  await page.goto('/all-groups');
  const card = page.locator('.gc-directory-card', { hasText: GROUP_LABEL }).first();
  await expect(card).toBeVisible();
  const link = card.locator('.gc-directory-card__title a').first();
  await link.click();
  await page.waitForURL(/\/group\/\d+/);
  return new URL(page.url()).pathname;
}

/** Appends one Markdown table row to the audit report. */
function appendReportRow(
  route: string,
  violations: number,
  serious: number,
  critical: number,
  notes: string,
): void {
  const row = `| ${route} | ${violations} | ${serious} | ${critical} | ${notes} |\n`;
  fs.appendFileSync(REPORT_PATH, row);
}

/** Runs the shared axe scan + report-row emission + serious/critical assertion. */
async function auditRoute(
  page: Page,
  route: string,
  notes = '',
  includeSelector?: string,
): Promise<void> {
  let builder = new AxeBuilder({ page }).withTags(AXE_TAGS);
  if (includeSelector) {
    builder = builder.include(includeSelector);
  }
  const results = await builder.analyze();
  const seriousOrCritical = results.violations.filter(
    (v) => v.impact === 'serious' || v.impact === 'critical',
  );
  const seriousCount = results.violations.filter((v) => v.impact === 'serious').length;
  const criticalCount = results.violations.filter((v) => v.impact === 'critical').length;

  appendReportRow(route, results.violations.length, seriousCount, criticalCount, notes);

  expect(
    seriousOrCritical,
    JSON.stringify(
      seriousOrCritical.map((v) => ({
        id: v.id,
        impact: v.impact,
        help: v.help,
        nodes: v.nodes.length,
      })),
      null,
      2,
    ),
  ).toEqual([]);
}

test.beforeAll(async () => {
  fs.mkdirSync('test-results', { recursive: true });
  fs.writeFileSync(
    REPORT_PATH,
    '# #145 WCAG 2.2 AA audit sweep\n\n' +
      '| Route | violations | serious | critical | notes |\n' +
      '| --- | --- | --- | --- | --- |\n',
  );
});

test('/ (front page) has no serious/critical axe violations', async ({ page }) => {
  await page.goto('/');
  await auditRoute(page, '/');
});

test('/all-groups (directory + card grid + filters) has no serious/critical axe violations', async ({
  page,
}) => {
  await page.goto('/all-groups');
  await auditRoute(page, '/all-groups');
});

test('/group/{seed} (group homepage) has no serious/critical axe violations', async ({
  page,
}) => {
  const groupPath = await resolveSeededGroupPath(page);
  await auditRoute(page, groupPath, `seeded group "${GROUP_LABEL}"`);
});

test('/showcase (variant switcher + POC ribbon) has no serious/critical axe violations', async ({
  page,
}) => {
  await page.goto('/showcase');
  await auditRoute(page, '/showcase');
});

test('persona-switcher widget (embedded on /, no standalone /personas route) has no serious/critical axe violations', async ({
  page,
}) => {
  // Route correction (T-GREEN): the brief's "/personas" is not a real route
  // (confirmed via routing.yml grep + curl 404; see file header comment and
  // handoff-F.md). The persona banner/switcher is reached via `/`, matching
  // persona-switcher.spec.ts's own convention. Scoping the axe scan to the
  // widget itself (rather than re-scanning the whole front page, already
  // covered by the "/ (front page)" test above) keeps this test's assertion
  // non-redundant.
  await page.goto('/');
  await expect(page.locator(PERSONA_SWITCHER_SELECTOR)).toBeVisible();
  await auditRoute(page, '/ (persona-switcher widget)', 'scoped to persona-switcher form/banner region', PERSONA_SWITCHER_SELECTOR);
});

test('/group/{seed}/members (manage-members table) has no serious/critical axe violations', async ({
  page,
}) => {
  const groupPath = await resolveSeededGroupPath(page);
  await page.goto(`${groupPath}/members`, { waitUntil: 'domcontentloaded' });
  await auditRoute(page, `${groupPath}/members`, `seeded group "${GROUP_LABEL}"`);
});

test('/group/add/{type} (create-group form) has no serious/critical axe violations', async ({
  page,
}) => {
  await page.goto(`/group/add/${GROUP_TYPE}`);
  await auditRoute(page, `/group/add/${GROUP_TYPE}`);
});

test('/do-streams/demo/{scope} (shared stream shell, representative route) has no serious/critical axe violations', async ({
  page,
}) => {
  await page.goto(STREAMS_ROUTE);
  await auditRoute(page, STREAMS_ROUTE, 'representative do_streams scope route (global)');
});

// --- Documented waivers (brief.md "Out of scope / waivers") ---------------
//
// Each waiver is declared as a real `test(...)` that calls `test.skip(true,
// reason)` in its body — the in-test-body shape already established by this
// repo's manage-members.spec.ts (`test.skip(!pendingRowExists, '...')`).
// This is a valid 2-arg `test.skip(condition, reason)` overload and — unlike
// the Phase-4 file-scope `test.skip(title, string)` misuse it replaces —
// cannot leak into a whole-suite skip of the 8 real tests above.

test('RTL toggle audit (waived)', () => {
  test.skip(
    true,
    'Display-only toggle with no seeded RTL locale in this demo (brief.md waiver) — no automatable surface exists to scan.',
  );
});

test('Maps surface audit (waived)', () => {
  test.skip(
    true,
    'No maps surface exists in the demo (brief.md waiver) — nothing to scan.',
  );
});
