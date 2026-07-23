<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Functional;

use Drupal\Tests\group\Functional\GroupBrowserTestBase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * #144 MC-6 — RED (Phase 4, authored by T before F implements).
 *
 * The NEW `GroupCreatedPreviewController` + route
 * (`do_group_membership.group_created_preview`, `/group/{group}/created`)
 * do not exist yet at RED time. This suite uses a real request stack
 * (`GroupBrowserTestBase`) so the access callback and the rendered markup
 * (headings, links) can be asserted against actual response HTML — a
 * content-only controller has no form to submit, so BrowserTestBase (not a
 * Kernel render-array assertion) is the cheapest sufficient tier that still
 * proves the real route resolves and renders through the full access-check
 * + render pipeline.
 *
 * Per the approved wireframe (`wireframe.md`): DOM order is
 * h1 -> p -> h2 -> ul>li>a x3. Link text repeats the group name and the
 * destination/action (never bare "click here").
 *
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class GroupCreatedPreviewControllerTest extends GroupBrowserTestBase {

  /**
   * The group type id under test (mirrors the assembled community_group).
   */
  protected const GROUP_TYPE_ID = 'community_group';

  /**
   * {@inheritdoc}
   *
   * T-green fix: this suite hits the NEW
   * do_group_membership.group_created_preview route/controller directly, so
   * the module providing it must be enabled — GroupBrowserTestBase's own
   * $modules is only ['group'], which is why the route genuinely 404s
   * without this override (confirmed by F via a throwaway probe; F did not
   * edit this file — see handoff-F.md). This test constructs its OWN
   * minimal community_group-alike type via createGroupType()/
   * createRoleForGroupType() (not the real assembled config), so no
   * additional field-type modules (image/taxonomy/node) are needed here,
   * unlike CreateGroupWizardOrganizerTest's real-config-import suite.
   */
  protected static $modules = ['group', 'do_group_membership'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function getGlobalPermissions() {
    return ['administer group'] + parent::getGlobalPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createGroupType([
      'id' => self::GROUP_TYPE_ID,
      'label' => 'Community Group',
      'creator_membership' => TRUE,
    ]);
    $this->createRoleForGroupType(self::GROUP_TYPE_ID, 'community_group-organizer', [
      'edit group',
      'administer members',
    ]);

    $this->setUpAccount();
    foreach ($this->groupCreator->getRoles(TRUE) as $role_id) {
      $role = $this->entityTypeManager()->getStorage('user_role')->load($role_id);
      $this->assertInstanceOf(RoleInterface::class, $role);
      $role->grantPermission('create ' . self::GROUP_TYPE_ID . ' group')->save();
    }
  }

  /**
   * Creates an individual-scope group role for the group type under test.
   *
   * Small local helper (not present on the base test class) so this suite
   * can construct an Organizer-equivalent role without depending on
   * `do_group_membership`'s assembled config being importable in a
   * Functional context.
   */
  protected function createRoleForGroupType(string $group_type_id, string $role_id, array $permissions): void {
    $this->entityTypeManager()->getStorage('group_role')->create([
      'id' => $group_type_id . '-' . str_replace($group_type_id . '-', '', $role_id),
      'label' => $role_id,
      'group_type' => $group_type_id,
      'scope' => 'individual',
      'permissions' => $permissions,
    ])->save();
  }

  /**
   * AC-4/AC-5 (functional half): the route resolves and renders successfully
   * (200) for the group's owner/creator, with the wireframe's exact DOM
   * order (h1 -> p -> h2 -> ul>li>a x3) and self-descriptive link text.
   */
  public function testPreviewPageRendersForOwner(): void {
    $group = $this->createGroup([
      'type' => self::GROUP_TYPE_ID,
      'label' => 'Acme Owners Circle',
      'uid' => $this->groupCreator->id(),
    ]);
    $this->addGroupAdminMember($group, $this->groupCreator);

    $this->drupalLogin($this->groupCreator);
    $this->drupalGet('/group/' . $group->id() . '/created');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage();

    // Exactly one h1, naming the group.
    $h1_elements = $page->findAll('css', 'h1');
    $this->assertCount(1, $h1_elements, 'Exactly one h1 on the preview page.');
    $this->assertStringContainsString('Acme Owners Circle', $h1_elements[0]->getText(), 'The h1 names the group.');

    // A paragraph mentioning "Organizer".
    $this->assertSession()->elementExists('css', 'p');
    $paragraphs = $page->findAll('css', 'p');
    $found_organizer_paragraph = FALSE;
    foreach ($paragraphs as $paragraph) {
      if (str_contains($paragraph->getText(), 'Organizer')) {
        $found_organizer_paragraph = TRUE;
      }
    }
    $this->assertTrue($found_organizer_paragraph, 'A <p> mentions "Organizer".');

    // h2 present ("What's next?" per wireframe), no heading level skipped
    // (h1 then h2, no h3+).
    $h2_elements = $page->findAll('css', 'h2');
    $this->assertNotEmpty($h2_elements, 'An h2 is present.');
    $this->assertEmpty($page->findAll('css', 'h3'), 'No h3 or deeper heading is used (one-section page).');
    $this->assertEmpty($page->findAll('css', 'h4'));

    // Three CTA links inside a <ul>, DOM order h1 -> p -> h2 -> ul>li>a x3.
    $list_links = $page->findAll('css', 'ul a');
    $this->assertCount(3, $list_links, 'Exactly three CTA links in the next-steps list.');

    $link_texts = array_map(static fn ($link) => $link->getText(), $list_links);
    $combined = implode(' | ', $link_texts);
    $this->assertStringContainsString('Acme Owners Circle', $combined, 'Link text repeats the group name.');
    foreach ($link_texts as $text) {
      $this->assertNotEquals('click here', strtolower(trim($text)), 'No bare "click here" link text.');
    }

    // DOM order check: h1 appears before the organizer paragraph, which
    // appears before h2, which appears before the ul.
    //
    // Diff-gate W-2 (Phase 5b): locates the paragraph via its own known copy
    // substring ("You're the Organizer") rather than a bare `<p` tag search
    // — `<p` would also match a `<p>` emitted by the active theme's page
    // shell/wrapper (e.g. a footer region), which is not part of the
    // sequence under test and would make this assertion imprecise.
    $html = $this->getSession()->getPage()->getContent();
    $h1_pos = strpos($html, '<h1');
    $p_pos = strpos($html, "You're the Organizer");
    $h2_pos = strpos($html, '<h2');
    $ul_pos = strpos($html, '<ul');
    $this->assertNotFalse($h1_pos);
    $this->assertNotFalse($p_pos, 'The organizer paragraph\'s copy is present in the response.');
    $this->assertNotFalse($h2_pos);
    $this->assertNotFalse($ul_pos);
    $this->assertLessThan($p_pos, $h1_pos, 'h1 precedes the organizer paragraph in DOM order.');
    $this->assertLessThan($h2_pos, $p_pos, 'The organizer paragraph precedes h2 in DOM order.');
    $this->assertLessThan($h2_pos, $h1_pos, 'h1 precedes h2 in DOM order.');
    $this->assertLessThan($ul_pos, $h2_pos, 'h2 precedes the CTA <ul> in DOM order.');
  }

  /**
   * Access denied (403) for an unrelated authenticated user who is not a
   * member/organizer/admin of the group.
   */
  public function testPreviewPageIsForbiddenForUnrelatedUser(): void {
    $group = $this->createGroup([
      'type' => self::GROUP_TYPE_ID,
      'label' => 'Private Circle',
      'uid' => $this->groupCreator->id(),
    ]);
    $this->addGroupAdminMember($group, $this->groupCreator);

    $stranger = $this->drupalCreateUser();
    $this->drupalLogin($stranger);
    $this->drupalGet('/group/' . $group->id() . '/created');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Adds an active `group_membership` relationship without going through the
   * (multi-step, per `creator_wizard: true`) real add-form — this suite is
   * only about the PREVIEW route, so the group + membership are constructed
   * directly, matching the same shape used by
   * `CreateGroupOrganizerHookTest` at the Kernel tier.
   */
  protected function addGroupAdminMember($group, $account): void {
    $group->addMember($account);
  }

}
