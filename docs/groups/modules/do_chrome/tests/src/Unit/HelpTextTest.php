<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Unit;

use Drupal\do_chrome\HelpText;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the centralized tooltip copy source.
 *
 * CH-F1 (#79). HelpText is the single, editable copy source every tooltip
 * surface (#88-#92) reads from. This pins the foundation contract:
 *  - the foundation demo key resolves to non-empty plain text,
 *  - unknown keys resolve to '' (so a mis-keyed surface renders no tooltip,
 *    never a PHP warning),
 *  - all() returns a string=>string map.
 *
 * @coversDefaultClass \Drupal\do_chrome\HelpText
 */
final class HelpTextTest extends TestCase {

  /**
   * @covers ::get
   */
  public function testFoundationDemoCopyIsPresent(): void {
    $copy = HelpText::get('demo.foundation');
    $this->assertNotSame('', $copy, 'The foundation demo tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
  }

  /**
   * @covers ::get
   */
  public function testUnknownKeyReturnsEmptyString(): void {
    $this->assertSame('', HelpText::get('no.such.surface'));
  }

  /**
   * The #90 multi-group audience copy is present and plain text.
   *
   * @covers ::get
   */
  public function testAudienceCopyIsPresent(): void {
    $copy = HelpText::get('audience.fieldset');
    $this->assertNotSame('', $copy, 'The #90 audience tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    $this->assertStringContainsString('more than one group', $copy, 'Copy must match the #81 deck (section D).');
  }

  /**
   * The #88/#121 per-option visibility copy exists, is plain text, and is
   * honest about NOW-LIVE enforcement.
   *
   * #121 SC-2 makes join-policy enforcement real: Open (already live via
   * #95), Moderated (request-to-join + organizer approval, now live), and
   * Invite Only (visible but closed to direct joining, now live) are ALL
   * enforced. The old "Not yet enforced" wording for Moderated/Invite Only
   * is now FALSE and must not appear anywhere in the visibility.* copy
   * (AC-6). The corrected Invite Only copy MUST contain the word "visible"
   * (AC-7) — guarding against a faithful-but-wrong edit that drops "not yet
   * enforced" but keeps the old (wrong) "hidden" framing; hidden is Private
   * (#134), not Invite Only. The corrected Moderated copy must describe
   * request + approval as LIVE behavior. The field-level intro
   * (visibility.field) must retain a phrase that separates *viewing* from
   * *joining* (A-W3 guard rail).
   *
   * @covers ::get
   */
  public function testVisibilityCopyIsPresentPlainTextAndHonest(): void {
    foreach (['visibility.field', 'visibility.open', 'visibility.moderated', 'visibility.invite_only'] as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Tooltip copy for "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    }

    // Open is wired by #95 — present it as live/joinable. Unchanged by #121.
    $this->assertMatchesRegularExpression('/\bjoin\b/i', HelpText::get('visibility.open'), 'Open copy must describe joining.');

    // AC-6 (sweeping): NO visibility.* key may still contain "Not yet
    // enforced" — #121 makes all three states real, enforced behavior.
    foreach (HelpText::all() as $key => $copy) {
      if (str_starts_with($key, 'visibility.')) {
        $this->assertStringNotContainsString('Not yet enforced', $copy, sprintf('"%s" copy must not claim to be unenforced (AC-6) — #121 makes join-policy enforcement live.', $key));
      }
    }

    // Moderated copy must describe request-to-join AND organizer approval
    // as LIVE (brief-response §4 / AC-7).
    $moderated_copy = HelpText::get('visibility.moderated');
    $this->assertMatchesRegularExpression('/\brequest/i', $moderated_copy, 'Moderated copy must describe requesting to join.');
    $this->assertMatchesRegularExpression('/\bapprov/i', $moderated_copy, 'Moderated copy must describe organizer approval.');

    // Invite Only copy MUST contain "visible" (AC-7) — Invite Only is
    // visible-but-closed-to-joining, NOT hidden (hidden is Private, #134) —
    // and must NOT contain the misleading word "hidden".
    $invite_only_copy = HelpText::get('visibility.invite_only');
    $this->assertMatchesRegularExpression('/\bvisible\b/i', $invite_only_copy, 'Invite Only copy must contain the word "visible" (AC-7).');
    $this->assertStringNotContainsString('hidden', $invite_only_copy, 'Invite Only copy must NOT describe the group as "hidden" — that is Private (#134), not Invite Only.');

    // A-W3: the field-level intro must retain a phrase that separates
    // *viewing* from *joining*, so the invite_only correction reads
    // consistently with the field-level framing.
    $field_copy = HelpText::get('visibility.field');
    $this->assertTrue(
      (bool) preg_match('/\bjoin\b/i', $field_copy) || (bool) preg_match('/\bview\b/i', $field_copy),
      'visibility.field copy must retain wording that separates viewing from joining (A-W3).',
    );
  }

  /**
   * The #92 archive/pin/promote/follow copy exists and is plain text.
   *
   * Only WIRED controls ship copy (verified in the #81 spike): archive badge,
   * pin badge, promote (`promote_homepage` -> Promoted Content listing), follow
   * (`follow_content` -> notifications).
   *
   * @covers ::get
   */
  public function testArchivePinControlCopyIsPresentAndPlainText(): void {
    foreach (['archive.badge', 'pin.badge', 'promote.control', 'follow.control'] as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Tooltip copy for "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    }
  }

  /**
   * No "flag/report" control copy ships — no such moderation target exists.
   *
   * The #81 copy deck listed a "Flag" (report-to-admins) control marked
   * verify-before-ship; the demo has no report/abuse flag, so its copy is
   * intentionally omitted rather than describing unwired behavior.
   *
   * @covers ::get
   */
  public function testReportFlagControlCopyIsOmitted(): void {
    $all = HelpText::all();
    foreach (array_keys($all) as $key) {
      $this->assertStringNotContainsString('report', $key, 'No report/moderation flag tooltip should ship.');
    }
    $this->assertSame('', HelpText::get('flag.control'));
    $this->assertSame('', HelpText::get('report.control'));
  }

  /**
   * #89 (CH-B2): the Group Type field ⓘ names every seeded group type.
   *
   * @covers ::get
   */
  public function testGroupTypeFieldCopyNamesAllTypes(): void {
    $copy = HelpText::get('group_type.field');
    $this->assertNotSame('', $copy, 'The group_type.field tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    foreach (['Geographical', 'Working group', 'Distribution', 'Event planning', 'Archive'] as $type) {
      $this->assertStringContainsString($type, $copy, "Group Type help must name the '$type' type.");
    }
  }

  /**
   * #89 (CH-B2): the content-type field ⓘ names every group content type.
   *
   * @covers ::get
   */
  public function testContentTypeFieldCopyNamesAllTypes(): void {
    $copy = HelpText::get('content_type.field');
    $this->assertNotSame('', $copy, 'The content_type.field tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    foreach (['Forum', 'Documentation', 'Event', 'Post', 'Page'] as $type) {
      $this->assertStringContainsString($type, $copy, "Content-type help must name the '$type' type.");
    }
  }

  /**
   * The #91 permission-matrix panel copy exists and is plain text.
   *
   * @covers ::get
   */
  public function testPermissionMatrixPanelCopyIsPresent(): void {
    foreach (['permissions.panel.intro', 'permissions.panel.footnote'] as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Panel copy for "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    }
  }

  /**
   * #133 (SD-6 capstone, honesty sweep): the permission-matrix panel copy
   * must call the admin actor "Organizer" (the MVP-correct, user-visible
   * role name — brief.md scope item 3's "Group-module-foundation honesty":
   * "personas are Anonymous/Member/Organizer/Groups-Moderate"), NOT the
   * stale "Group admin" / "group admin" phrasing the #91 copy deck
   * originally shipped.
   *
   * RED reason: `permissions.panel.intro` / `permissions.panel.footnote`
   * still read "A group admin holds every management capability." etc. at
   * RED time — F has not yet applied the 13-item audit work-list (#3).
   *
   * @covers ::get
   */
  public function testPermissionMatrixPanelCopyNamesOrganizerNotGroupAdmin(): void {
    foreach (['permissions.panel.intro', 'permissions.panel.footnote'] as $key) {
      $copy = HelpText::get($key);
      $this->assertStringNotContainsString('Group admin', $copy, sprintf('"%s" must not use the stale "Group admin" phrasing — the user-visible role is "Organizer" (#133 honesty sweep).', $key));
      $this->assertStringNotContainsString('group admin', $copy, sprintf('"%s" must not use the stale "group admin" phrasing (any case) — the user-visible role is "Organizer" (#133 honesty sweep).', $key));
    }

    // The footnote is the one that actually names the admin actor's
    // capability in prose — it must say "Organizer" explicitly.
    $footnote = HelpText::get('permissions.panel.footnote');
    $this->assertStringContainsString('Organizer', $footnote, 'permissions.panel.footnote must name the admin actor as "Organizer" (#133 honesty sweep).');
  }

  /**
   * #122 (SC-3): the group-type-homepage tooltip copy names all three
   * lead-section variants (events / discussion / documentation) truthfully.
   *
   * `HelpText::all()` is a fixed literal array — the suite does NOT auto-cover
   * a freshly-appended key (unlike e.g. a config-driven copy source), so this
   * is a TARGETED assertion for the new `group_type.homepage_adapts` key,
   * mirroring the existing per-surface test pattern in this file (e.g.
   * `testGroupTypeFieldCopyNamesAllTypes`). RED reason: F has not yet
   * appended this key to `HelpText::all()`, so `HelpText::get()` returns the
   * unknown-key default `''` and every assertion below fails.
   *
   * @covers ::get
   */
  public function testGroupTypeHomepageAdaptsCopyIsPresentAndNamesVariants(): void {
    $copy = HelpText::get('group_type.homepage_adapts');
    $this->assertNotSame('', $copy, 'The group_type.homepage_adapts tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    // Wireframe §3: the copy must name the three concrete lead-section
    // variants so a first-time reader understands what "adapts" means.
    foreach (['events', 'discussion', 'documentation'] as $variant) {
      $this->assertStringContainsString($variant, $copy, "Copy must name the '$variant' variant.");
    }
    // The key must actually be present in the literal array (not merely
    // resolvable via some other fallback path).
    $this->assertArrayHasKey('group_type.homepage_adapts', HelpText::all());
  }

  /**
   * #127 (SD-2): the 5 new card.* keys (directory + stream card element
   * tooltips) exist, are plain text, and are non-empty.
   *
   * `HelpText::all()` is a fixed literal array — a freshly-appended key is
   * not auto-covered by any other test in this file, so this is a TARGETED
   * assertion, mirroring the existing per-surface pattern (e.g.
   * `testGroupTypeHomepageAdaptsCopyIsPresentAndNamesVariants`). RED reason:
   * F has not yet appended these keys to `HelpText::all()`, so every
   * `HelpText::get()` call below resolves to the unknown-key default `''`
   * and every non-empty assertion fails.
   *
   * Card scope (brief.md): `card.directory.type`, `card.directory.members`
   * are new; the visibility badge REUSES the existing `visibility.*` keys
   * (covered by testVisibilityCopyIsPresentPlainTextAndHonest above) — no
   * new key for visibility. `card.stream.byline`, `card.stream.type`,
   * `card.stream.comments` are new for the stream card.
   *
   * @covers ::get
   */
  public function testCardTooltipCopyIsPresentAndPlainText(): void {
    $keys = [
      'card.directory.type',
      'card.directory.members',
      'card.stream.byline',
      'card.stream.type',
      'card.stream.comments',
    ];
    $all = HelpText::all();
    foreach ($keys as $key) {
      $this->assertArrayHasKey($key, $all, sprintf('"%s" must be a literal key in HelpText::all() (append-only contract).', $key));
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Tooltip copy for "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    }

    // card.directory.type names the group-type taxonomy honestly, matching
    // the reused group_type.field vocabulary (brief.md copy block).
    $directory_type_copy = HelpText::get('card.directory.type');
    foreach (['Geographical', 'Working group', 'Distribution', 'Event planning', 'Archive'] as $type) {
      $this->assertStringContainsString($type, $directory_type_copy, "card.directory.type copy must name the '$type' type.");
    }

    // card.directory.members is about the member count, not something else.
    $this->assertMatchesRegularExpression('/\bpeople\b|\bmember/i', HelpText::get('card.directory.members'), 'card.directory.members copy must describe membership count.');

    // card.stream.byline names both the "who posted" and "which group"
    // halves of the byline row (brief.md copy block).
    $byline_copy = HelpText::get('card.stream.byline');
    $this->assertMatchesRegularExpression('/\bposted\b/i', $byline_copy, 'card.stream.byline copy must describe who posted.');
    $this->assertMatchesRegularExpression('/\bgroup\b/i', $byline_copy, 'card.stream.byline copy must describe which group.');

    // card.stream.type names the content-type taxonomy honestly, matching
    // the reused content_type.field vocabulary (brief.md copy block).
    $stream_type_copy = HelpText::get('card.stream.type');
    foreach (['Forum', 'Documentation', 'Event', 'Post', 'Page'] as $type) {
      $this->assertStringContainsString($type, $stream_type_copy, "card.stream.type copy must name the '$type' type.");
    }

    // card.stream.comments is about replies/comments, not something else.
    $this->assertMatchesRegularExpression('/\breplies\b|\bcomment/i', HelpText::get('card.stream.comments'), 'card.stream.comments copy must describe the comment/reply count.');
  }

  /**
   * #134 SC-7 — the four privacy-axis tooltip keys exist, are non-empty
   * plain text of at least 40 chars each (wireframe §3's copy deck: all four
   * strings run well past a trivial placeholder length — 152-191 chars per
   * the wireframe's own char counts), and the teaching key
   * (`privacy.vs_invite_only`) explicitly contrasts Private vs Invite Only so
   * a reader does not conflate the view axis with the join axis.
   *
   * RED reason: F has not yet appended these keys to `HelpText::all()`, so
   * `HelpText::get()` returns the unknown-key default `''` and every
   * assertion below fails (`assertNotSame('', ...)` and the length check).
   *
   * @covers ::get
   */
  public function testPrivacyKeys(): void {
    foreach (['privacy.public', 'privacy.unlisted', 'privacy.private', 'privacy.vs_invite_only'] as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Tooltip copy for "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
      $this->assertGreaterThanOrEqual(40, strlen($copy), sprintf('"%s" copy must be a real sentence (>= 40 chars), not a placeholder.', $key));
    }

    // AC-8's teaching key must explicitly name BOTH axes it contrasts.
    $teaching_copy = HelpText::get('privacy.vs_invite_only');
    $this->assertStringContainsString('Private', $teaching_copy, 'The teaching key must name "Private".');
    $this->assertMatchesRegularExpression('/Invite Only/i', $teaching_copy, 'The teaching key must name "Invite Only".');

    // Honesty guard (wireframe §3 notes): privacy.unlisted must NOT claim
    // directory-hiding is enforced — only `private` is enforced this story.
    $unlisted_copy = HelpText::get('privacy.unlisted');
    $this->assertMatchesRegularExpression('/not.*enforced|isn.t enforced/i', $unlisted_copy, 'privacy.unlisted must honestly flag that it is not enforced by this build.');
  }

  /**
   * #133 (SD-6 capstone, honesty sweep — work-list #1): `visibility.field`
   * must name BOTH axes explicitly (privacy/visibility AND join policy), not
   * just the vaguer "who can join" framing the original #88 copy shipped.
   * Brief scope item 3 ("two-axis-model honesty"): visibility
   * (public/unlisted/private) and join policy (open/moderated/invite_only)
   * must read as two distinct axes everywhere they appear, including this
   * field-level intro.
   *
   * RED reason: `visibility.field` currently reads "Sets who can join this
   * group and how..." — it does not name the "Privacy"/"privacy" axis
   * word at all, so the assertion below fails until F rewrites it (13-item
   * work-list #1).
   *
   * @covers ::get
   */
  public function testVisibilityFieldNamesBothAxesExplicitly(): void {
    $field_copy = HelpText::get('visibility.field');
    $this->assertMatchesRegularExpression('/\bprivacy\b/i', $field_copy, 'visibility.field must explicitly name the "Privacy" axis (#133 two-axis honesty).');
    $this->assertMatchesRegularExpression('/\bjoin\b/i', $field_copy, 'visibility.field must explicitly name the "join" axis (#133 two-axis honesty).');
  }

  /**
   * #133 (SD-6 capstone, honesty sweep — work-list #2): `visibility.invite_only`
   * must keep the existing AC-7 "visible" guarantee (regression-guarded by
   * `testVisibilityCopyIsPresentPlainTextAndHonest` above) AND add wording
   * that clarifies visibility is controlled SEPARATELY from this join-policy
   * value — otherwise a reader can still conflate "Invite Only" (a join-policy
   * value) with a visibility/privacy setting, exactly the confusion #134's
   * two-axis model exists to prevent (brief.md scope item 3).
   *
   * RED reason: the current copy ("the group stays visible to everyone, but
   * only an organizer can add members...") never says visibility is a
   * SEPARATE setting/axis — it implies visibility as a side-effect of THIS
   * value rather than naming it as independently controlled. Fails until F's
   * rewrite (13-item work-list #2).
   *
   * @covers ::get
   */
  public function testVisibilityInviteOnlyClarifiesVisibilityIsSeparatelyControlled(): void {
    $copy = HelpText::get('visibility.invite_only');
    $this->assertMatchesRegularExpression('/\bvisible\b/i', $copy, 'visibility.invite_only must still contain "visible" (AC-7 regression guard).');
    $this->assertStringNotContainsString('hidden', $copy, 'visibility.invite_only must still NOT contain "hidden" (AC-7 regression guard).');
    $this->assertMatchesRegularExpression('/privacy setting|separately|separate (?:axis|setting|control)/i', $copy, 'visibility.invite_only must clarify that visibility is controlled by a SEPARATE privacy setting, not this join-policy value (#133 two-axis honesty).');
  }

  /**
   * #133 (SD-6 capstone) audit recommendation #15: a `page.showcase`
   * HelpText key + PageHelp route-map entry, so the /showcase tour page
   * itself carries the same SD-1 page-level ⓘ pattern every other primary
   * page does (this spec's sibling, help-coverage.spec.ts, walks /showcase
   * as part of its anon walk and expects a help affordance there).
   *
   * This is the OPTIONAL item in the audit work-list — included here as a
   * targeted assertion so F has a concrete contract to implement against if
   * the recommendation is adopted. If `page.showcase` is intentionally NOT
   * adopted, T must delete this test at GREEN rather than leave it
   * permanently red (see handoff-T-red.md).
   *
   * @covers ::get
   */
  public function testPageShowcaseKeyExistsAndIsNonEmpty(): void {
    $copy = HelpText::get('page.showcase');
    $this->assertNotSame('', $copy, 'The page.showcase tooltip copy must exist (audit rec #15) if the recommendation is adopted.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
  }

  /**
   * @covers ::all
   */
  public function testAllReturnsStringMap(): void {
    $all = HelpText::all();
    $this->assertArrayHasKey('demo.foundation', $all);
    foreach ($all as $key => $value) {
      $this->assertIsString($key);
      $this->assertIsString($value);
    }
  }

}
