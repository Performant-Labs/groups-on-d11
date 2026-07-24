import { test, expect, Page } from '@playwright/test';

/**
 * Issue #112 (ST-3) — /my-feed/events (Events + My RSVPs) E2E.
 *
 * Brief acceptance criteria under test
 * (`docs/planning/handoffs/112-events-rsvps/brief.md`), exercised against
 * the seeded demo site (`docs/groups/scripts/step_700_demo_data.php` lines
 * 179-320: 5 events, 9 RSVPs; elena_garcia has 3 RSVPs — Keynote/Sprint/
 * Barcelona; Keynote has 4 RSVPs — elena, ravi, sophie, alex; Barcelona
 * +60d, Keynote +90d, Sprint +91d; Thunder Editorial Workshop +45d and
 * Governance Town Hall +30d belong to groups elena is NOT a member of):
 *  - Upcoming (My Groups scope) lists Barcelona -> Keynote -> Sprint in
 *    that DOM order (date ASC: +60d, +90d, +91d).
 *  - My RSVPs lists elena's 3 RSVPs in date ASC (same 3 events, same order).
 *  - Keynote's card shows the RSVP chip text "4 going" AND the
 *    viewer-state indicator "You're going" (elena has RSVP'd).
 *  - Clicking the Global toggle widens Upcoming to include a group elena is
 *    not a member of (Thunder Editorial Workshop OR Governance Town Hall).
 *  - Both iCal `<a>` links (`/upcoming-events/ical`,
 *    `/user/<uid>/events/ical`) render with correct hrefs.
 *
 * do_streams' `/my-feed/events` route, `MyEventsController`, and the
 * shipped `views.view.my_events.yml` do not exist yet at RED time (Phase 4,
 * before F implements) — every selector below targets markup/routes the
 * wireframe (wireframe.html) specifies but nothing in the codebase renders
 * yet, mirroring showcase.spec.ts's own RED-by-construction convention (see
 * docs/planning/handoffs/112-events-rsvps/handoff-T-red.md for the
 * RED-by-construction reasoning per case). These run for real at T-GREEN
 * against a fully seeded site, per the brief's own delivery instruction
 * ("branch -> assemble config -> kernel+functional+E2E green in namespaced
 * DDEV throwaway (or CI)").
 *
 * Auth uses the real /user/login form, matching manage-members.spec.ts's
 * and showcase.spec.ts's existing convention (no session injection).
 * elena_garcia is a real seeded demo account (step_700_demo_data.php), not a
 * fixture this spec creates — the ordering/chip assertions below are only
 * meaningful against her specific seeded RSVP/membership state. Per
 * my-feed.spec.ts's own T-red self-correction note, the real seeded
 * password is the shared "demo_password_2026" every demo user gets, NOT
 * their username — defaulted here to the same value.
 *
 * T-GREEN self-correction: the "Global toggle" test originally asserted on
 * BOTH "Thunder Editorial Workshop" (Thunder Distribution) AND "Governance
 * Town Hall" (Leadership Council) as events from groups elena is NOT a
 * member of. That is only true for Thunder Editorial Workshop —
 * step_700_demo_data.php's Step 730a membership seed (`"Leadership
 * Council" => ["james_okafor", "maria_chen", "elena_garcia"]`) makes elena
 * a Leadership Council MEMBER, so Governance Town Hall legitimately
 * appears under the DEFAULT (My Groups) scope too. Asserting `.some(...)`
 * across both titles in the "before" (default-scope) check produced a false
 * RED against real behavior (Governance Town Hall correctly appears
 * pre-toggle). Fixed by pinning the assertion to Thunder Editorial Workshop
 * only — the one event in the seed that is unambiguously outside every
 * group elena belongs to.
 */

const ELENA_USER = process.env.ELENA_USER ?? 'elena_garcia';
const ELENA_PASS = process.env.ELENA_PASS ?? 'demo_password_2026';

const MY_EVENTS_PATH = '/my-feed/events';

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

test.describe('/my-feed/events (#112 ST-3)', () => {
  test('Upcoming (My Groups scope) lists Barcelona, Keynote, Sprint in date order', async ({
    page,
  }) => {
    await login(page, ELENA_USER, ELENA_PASS);
    const res = await page.goto(MY_EVENTS_PATH);
    expect(res?.status()).toBe(200);

    const upcoming = page.locator('[data-testid="upcoming-events-results"]');
    await expect(upcoming).toBeVisible();

    const titles = await upcoming.locator('.event-card__title').allInnerTexts();
    const barcelonaIdx = titles.findIndex((t) => /Barcelona/i.test(t));
    const keynoteIdx = titles.findIndex((t) => /Keynote/i.test(t));
    const sprintIdx = titles.findIndex((t) => /Sprint/i.test(t));

    expect(barcelonaIdx, 'Barcelona appears in the Upcoming section').toBeGreaterThanOrEqual(0);
    expect(keynoteIdx, 'Keynote appears in the Upcoming section').toBeGreaterThanOrEqual(0);
    expect(sprintIdx, 'Sprint appears in the Upcoming section').toBeGreaterThanOrEqual(0);

    expect(
      barcelonaIdx,
      'Barcelona (+60d) sorts before Keynote (+90d) — date ASC.',
    ).toBeLessThan(keynoteIdx);
    expect(
      keynoteIdx,
      'Keynote (+90d) sorts before Sprint (+91d) — date ASC.',
    ).toBeLessThan(sprintIdx);
  });

  test('My RSVPs lists elena\'s three RSVPs in date order', async ({ page }) => {
    await login(page, ELENA_USER, ELENA_PASS);
    await page.goto(MY_EVENTS_PATH);

    const myRsvps = page.locator('[data-testid="my-rsvps-results"]');
    await expect(myRsvps).toBeVisible();

    const titles = await myRsvps.locator('.event-card__title').allInnerTexts();
    expect(titles).toHaveLength(3);

    const barcelonaIdx = titles.findIndex((t) => /Barcelona/i.test(t));
    const keynoteIdx = titles.findIndex((t) => /Keynote/i.test(t));
    const sprintIdx = titles.findIndex((t) => /Sprint/i.test(t));

    expect(barcelonaIdx, 'Barcelona appears in My RSVPs').toBeGreaterThanOrEqual(0);
    expect(keynoteIdx, 'Keynote appears in My RSVPs').toBeGreaterThanOrEqual(0);
    expect(sprintIdx, 'Sprint appears in My RSVPs').toBeGreaterThanOrEqual(0);

    expect(barcelonaIdx, 'Barcelona (+60d) sorts first in My RSVPs — date ASC.').toBeLessThan(keynoteIdx);
    expect(keynoteIdx, 'Keynote (+90d) sorts before Sprint (+91d) in My RSVPs — date ASC.').toBeLessThan(sprintIdx);
  });

  test('Keynote card shows "4 going" and viewer-state "You\'re going"', async ({
    page,
  }) => {
    await login(page, ELENA_USER, ELENA_PASS);
    await page.goto(MY_EVENTS_PATH);

    const keynoteCard = page
      .locator('.event-card')
      .filter({ hasText: /Keynote/i })
      .first();
    await expect(keynoteCard).toBeVisible();

    const chip = keynoteCard.locator('[data-testid="rsvp-chip"]');
    await expect(chip).toBeVisible();
    await expect(chip).toHaveAttribute('data-going-count', '4');
    await expect(chip).toHaveAttribute('data-viewer-state', 'going');
    await expect(chip).toContainText(/4 going/i);
    await expect(chip).toContainText(/you're going/i);
  });

  test('Global toggle widens Upcoming beyond elena\'s memberships', async ({
    page,
  }) => {
    await login(page, ELENA_USER, ELENA_PASS);
    await page.goto(MY_EVENTS_PATH);

    // Thunder Editorial Workshop belongs to Thunder Distribution, the ONE
    // seeded group among the two candidate "non-member" events that elena
    // is genuinely not a member of (step_700_demo_data.php Step 730a:
    // Thunder Distribution's members are ravi_patel/sophie_mueller only —
    // elena is NOT listed). Governance Town Hall's group (Leadership
    // Council) DOES include elena, so it is deliberately excluded from
    // this assertion (see class docblock's T-GREEN self-correction note).
    const upcomingBefore = page.locator('[data-testid="upcoming-events-results"]');
    const titlesBefore = await upcomingBefore.locator('.event-card__title').allInnerTexts();
    expect(
      titlesBefore.some((t) => /Thunder Editorial Workshop/i.test(t)),
      'Thunder Editorial Workshop does NOT appear under the default (My Groups) scope — elena is not a member of Thunder Distribution.',
    ).toBe(false);

    const globalTab = page.locator('[data-testid="do-streams-shell-tab"][data-scope-id="global"]');
    await expect(globalTab).toBeVisible();
    await globalTab.click();
    await page.waitForURL(/[?&]scope=global/);

    const upcomingAfter = page.locator('[data-testid="upcoming-events-results"]');
    await expect(upcomingAfter).toBeVisible();
    const titlesAfter = await upcomingAfter.locator('.event-card__title').allInnerTexts();
    expect(
      titlesAfter.some((t) => /Thunder Editorial Workshop/i.test(t)),
      'Under ?scope=global, Upcoming widens to include Thunder Editorial Workshop, a non-member group\'s event.',
    ).toBe(true);
  });

  test('both iCal links render with correct hrefs', async ({ page }) => {
    await login(page, ELENA_USER, ELENA_PASS);
    await page.goto(MY_EVENTS_PATH);

    const siteIcal = page.locator('[data-testid="ical-link-site"]');
    await expect(siteIcal).toBeVisible();
    await expect(siteIcal).toHaveAttribute('href', '/upcoming-events/ical');

    const userIcal = page.locator('[data-testid="ical-link-user"]');
    await expect(userIcal).toBeVisible();
    const href = await userIcal.getAttribute('href');
    expect(href, 'The user iCal link points at /user/<uid>/events/ical for the CURRENT viewing user (elena), not a hardcoded uid.').toMatch(
      /^\/user\/\d+\/events\/ical$/,
    );
  });
});
