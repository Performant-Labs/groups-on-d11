import { test, expect, Locator, Page } from '@playwright/test';

/**
 * E2E for card- and element-level ⓘ tooltips — story #127 SD-2.
 *
 * Asserts that the established `do_chrome/tooltips` mechanism
 * (data-do-tooltip + tabindex="0" + role="note" + aria-label, per
 * GroupTypeContentHelp::infoTrigger() / #89 / #122 / #126) is wired onto:
 *   - directory-card fields on /all-groups: type badge, visibility badge,
 *     member-count stat;
 *   - stream-card elements on /stream: byline row, content-type badge,
 *     comments footer.
 *
 * RED reason (Phase 4, before F implements): neither
 * views-view-fields--all-groups.html.twig nor node--stream-card.html.twig
 * currently emits any `[data-do-tooltip]` trigger element inside
 * `.gc-directory-card` / `.gc-stream-card` — every locator below resolves to
 * zero elements, so assertions fail on a missing/invisible locator (toggle
 * count / visibility), never on a tooltip-copy mismatch. Once F wires the
 * three preprocess extensions + six inline `<span>` triggers, these same
 * assertions become the GREEN contract (T-GREEN re-runs verbatim).
 *
 * Copy single-sourcing (AC-4 in brief.md): the visibility ⓘ's
 * `data-do-tooltip` value must equal `HelpText::get('visibility.' + value)`
 * (the #88/#121 REUSED key) — hardcoded here from
 * docs/groups/modules/do_chrome/src/HelpText.php (append-only, so this
 * string is stable) rather than invoking drush, to keep the spec fast and
 * dependency-free.
 *
 * Directory-card locator note (T CI-fix, Phase 6b): the type badge is
 * conditionally rendered by views-view-fields--all-groups.html.twig
 * (`{% if gc_directory.type_label %}`), and
 * groups_chrome_preprocess_views_view_fields__all_groups() does not fall
 * back to a bundle label when `field_group_type` is empty on a group. So
 * not every seeded card carries a type badge / type tooltip trigger.
 * Tests that need all 3 triggers present must filter to a card that
 * actually has all 3 elements, rather than assuming `.first()` does.
 */

/** Verbatim from HelpText::all()['visibility.open'] (do_chrome/src/HelpText.php). */
const VISIBILITY_OPEN_COPY =
  'Open: anyone signed in can join instantly, no approval needed. This is live on the demo — logged-in visitors can join Open groups now.';

/**
 * Asserts a ⓘ trigger element carries the full do_chrome tooltip contract:
 * non-empty data-do-tooltip, tabindex="0", role="note", non-empty aria-label.
 */
async function expectTooltipTrigger(trigger: Locator): Promise<void> {
  await expect(trigger).toHaveAttribute('tabindex', '0');
  await expect(trigger).toHaveAttribute('role', 'note');
  const tooltipCopy = await trigger.getAttribute('data-do-tooltip');
  expect(tooltipCopy, 'data-do-tooltip must be present and non-empty').toBeTruthy();
  const ariaLabel = await trigger.getAttribute('aria-label');
  expect(ariaLabel, 'aria-label must be present and non-empty').toBeTruthy();
}

/** Hovers a trigger and asserts a tippy.js tooltip becomes visible. */
async function expectTippyOnHover(page: Page, trigger: Locator): Promise<void> {
  await trigger.hover();
  const tippyPopup = page.locator('.tippy-box, [data-tippy-root]').first();
  await expect(tippyPopup).toBeVisible();
}

/**
 * Returns the first `.gc-directory-card` on the current page that carries
 * all 3 badge/stat elements (type, visibility, member-count) — i.e. a card
 * where `field_group_type` is populated, so the type badge (and its
 * tooltip trigger) actually render. Not every seeded card qualifies (see
 * module doc comment above), so `.first()` alone is not safe for
 * assertions that require all 3 triggers.
 */
function fullDirectoryCard(page: Page): Locator {
  return page
    .locator('.gc-directory-card')
    .filter({ has: page.locator('.gc-directory-card__type') })
    .filter({ has: page.locator('.gc-directory-card__visibility') })
    .filter({ has: page.locator('.gc-directory-card__stat--members') })
    .first();
}

test.describe('SD-2 — Directory card ⓘ tooltips on /all-groups (#127)', () => {
  test('type, visibility, and member-count triggers carry the full tooltip contract', async ({
    page,
  }) => {
    const res = await page.goto('/all-groups');
    expect(res?.status()).toBe(200);

    // Pin to a card that actually carries all 3 badge/stat elements — the
    // type badge is conditionally rendered (see module doc comment), so
    // plain `.first()` risks landing on a card with no type badge/trigger.
    const card = fullDirectoryCard(page);
    await expect(card).toHaveCount(1);

    // Type badge -> adjacent trigger, scoped inside the card (no double-
    // tooltip: card triggers are inside .gc-card, distinct from #126's
    // page-level trigger which lives after the H1, outside any card).
    const typeTrigger = card
      .locator('.gc-directory-card__type')
      .locator('xpath=following-sibling::*[@data-do-tooltip] | following::*[@data-do-tooltip][1]')
      .first();
    await expect(typeTrigger).toHaveCount(1);
    await expectTooltipTrigger(typeTrigger);

    // Visibility badge -> adjacent trigger.
    const visibilityTrigger = card
      .locator('.gc-directory-card__visibility')
      .locator('xpath=following-sibling::*[@data-do-tooltip] | following::*[@data-do-tooltip][1]')
      .first();
    await expect(visibilityTrigger).toHaveCount(1);
    await expectTooltipTrigger(visibilityTrigger);

    // Member-count stat -> adjacent trigger.
    const membersTrigger = card
      .locator('.gc-directory-card__stat--members')
      .locator('xpath=following-sibling::*[@data-do-tooltip] | following::*[@data-do-tooltip][1]')
      .first();
    await expect(membersTrigger).toHaveCount(1);
    await expectTooltipTrigger(membersTrigger);
  });

  test('hovering a directory-card trigger shows a tippy tooltip', async ({ page }) => {
    await page.goto('/all-groups');
    const card = page.locator('.gc-directory-card').first();
    const anyTrigger = card.locator('[data-do-tooltip]').first();
    await expect(anyTrigger).toBeVisible();
    await expectTippyOnHover(page, anyTrigger);
  });

  test('visibility ⓘ copy is single-sourced from the reused visibility.* HelpText key', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    // Find a card whose visibility badge reads "Open" (the seeded demo has
    // groups in every visibility state — #95/#121 — so at least one Open
    // card exists).
    const openCard = page
      .locator('.gc-directory-card')
      .filter({ has: page.locator('.gc-directory-card__visibility', { hasText: /^Open$/i }) })
      .first();
    await expect(openCard).toHaveCount(1);

    const visibilityTrigger = openCard
      .locator('.gc-directory-card__visibility')
      .locator('xpath=following-sibling::*[@data-do-tooltip] | following::*[@data-do-tooltip][1]')
      .first();
    await expect(visibilityTrigger).toHaveAttribute('data-do-tooltip', VISIBILITY_OPEN_COPY);
  });

  test('no double-tooltip: card triggers are scoped inside .gc-card, not duplicated', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    // Pin to a card carrying all 3 elements — this test asserts that WHEN a
    // card has type + visibility + member-count badges, none of them is
    // double-wired with more than one trigger each. A card missing the
    // (conditionally-rendered) type badge would only ever have 2 triggers,
    // which would make `.toBe(3)` fail for the wrong reason (missing badge,
    // not a double-tooltip regression).
    const card = fullDirectoryCard(page);
    await expect(card).toHaveCount(1);
    const triggersInCard = card.locator('[data-do-tooltip]');
    expect(await triggersInCard.count()).toBe(3);
  });
});

test.describe('SD-2 — Stream card ⓘ tooltips on /stream (#127)', () => {
  test('byline, content-type, and comments triggers carry the full tooltip contract', async ({
    page,
  }) => {
    const res = await page.goto('/stream');
    expect(res?.status()).toBe(200);

    const cards = page.locator('.gc-stream-card');
    expect(await cards.count()).toBeGreaterThan(0);
    const card = cards.first();

    // Byline row -> adjacent trigger.
    const bylineTrigger = card
      .locator('.gc-stream-card__byline')
      .locator('xpath=following-sibling::*[@data-do-tooltip] | following::*[@data-do-tooltip][1]')
      .first();
    await expect(bylineTrigger).toHaveCount(1);
    await expectTooltipTrigger(bylineTrigger);

    // Content-type badge -> adjacent trigger.
    const typeTrigger = card
      .locator('.gc-stream-card__type')
      .locator('xpath=following-sibling::*[@data-do-tooltip] | following::*[@data-do-tooltip][1]')
      .first();
    await expect(typeTrigger).toHaveCount(1);
    await expectTooltipTrigger(typeTrigger);

    // Comments footer -> ADJACENT trigger, not merged into the comments
    // anchor's own aria-label (the anchor already carries its own
    // "@count comments" aria-label — the ⓘ must be a sibling element, never
    // nested inside that <a>, so accessible names never merge).
    const commentsAnchorOrSpan = card.locator('.gc-stream-card__comments');
    const commentsTrigger = commentsAnchorOrSpan.locator(
      'xpath=following-sibling::*[@data-do-tooltip] | following::*[@data-do-tooltip][1]',
    ).first();
    await expect(commentsTrigger).toHaveCount(1);
    await expectTooltipTrigger(commentsTrigger);
    // The trigger must NOT be a descendant of the comments anchor/span.
    const triggerInsideComments = await commentsAnchorOrSpan
      .locator('[data-do-tooltip]')
      .count();
    expect(triggerInsideComments).toBe(0);
  });

  test('hovering a stream-card trigger shows a tippy tooltip', async ({ page }) => {
    await page.goto('/stream');
    const card = page.locator('.gc-stream-card').first();
    const anyTrigger = card.locator('[data-do-tooltip]').first();
    await expect(anyTrigger).toBeVisible();
    await expectTippyOnHover(page, anyTrigger);
  });

  test('no double-tooltip: stream-card triggers are scoped inside .gc-card, not duplicated', async ({
    page,
  }) => {
    await page.goto('/stream');
    const card = page.locator('.gc-stream-card').first();
    // Exactly 3 triggers per card (byline, type, comments).
    const triggersInCard = card.locator('[data-do-tooltip]');
    expect(await triggersInCard.count()).toBe(3);
  });
});
