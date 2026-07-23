<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Functional;

use Drupal\node\Entity\NodeType;
use Drupal\Tests\group\Functional\GroupBrowserTestBase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * #144 MC-6 — RED (Phase 4, authored by T before F implements).
 *
 * THE test that exercises the REAL, ASSEMBLED `community_group` group type —
 * including its `creator_wizard: true` setting (`group.type.community_group.yml`
 * line 10) — through the actual multi-step wizard add-form flow, per the
 * brief's "IMPORTANT — creator_wizard: true" section and handoff-A.md finding
 * #1.
 *
 * Deliberately does NOT reconstruct a simplified `createGroupType([...])`
 * that omits `creator_wizard` (that is exactly what the EXISTING
 * `CreatorMembershipFormTest::setUp()` does, and per the brief, that
 * omission means it does NOT exercise the real wizard at all — a known,
 * accepted gap in that pre-existing #36 regression test, which this suite
 * does not modify or duplicate; it exists to close the gap for #144's own
 * acceptance criteria only). This suite imports the REAL assembled
 * `community_group` config (group type, all group roles, relationship
 * types, and their field storage/instance config — see
 * {@see self::importRealCommunityGroupConfig()}), so `creator_wizard: true`
 * and `creator_roles: [community_group-admin]` are both genuinely in effect
 * exactly as they are in production.
 *
 * WIZARD STEP UNCERTAINTY (flagged per T's task instructions, not guessed):
 * the exact number and sequence of wizard steps for `creator_wizard: true`
 * has not been empirically verified in this environment (no vendor access,
 * no running DDEV site available to T at RED-authoring time — see
 * handoff-A.md's Q3, which explicitly defers this to F's empirical
 * verification). This test is structured so that:
 *   - it submits the FIRST step with the group label (the one field every
 *     variant of the Group 4.x creator wizard requires on step 1);
 *   - it then walks forward through however many "Next"/intermediate steps
 *     the wizard actually presents, submitting each with no changes, until
 *     it reaches a page with no further "Next" action and does have a final
 *     save button;
 *   - it submits the final save button and asserts the POST-SAVE outcomes
 *     (AC-1, AC-2, AC-3) rather than hard-coding an assumed fixed step
 *     count.
 * If the wizard's actual step structure differs from what this walk
 * anticipates (e.g. button labels), this test will fail with actionable
 * output (the exact page reached, its buttons) rather than a silent false
 * pass — this is expected, intentional RED behavior per T's task
 * instructions ("fine if this test's exact step-by-step submission needs
 * one round of empirical correction once F/T-green runs it against a real
 * environment").
 *
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class CreateGroupWizardOrganizerTest extends GroupBrowserTestBase {

  /**
   * The group type id under test — the REAL assembled community_group.
   */
  protected const GROUP_TYPE_ID = 'community_group';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * `image` is required because the real `field_group_image` field
   * (`field.storage.group.field_group_image.yml`) uses the `image` field
   * type — importing the real assembled config means every module its
   * config depends on must be enabled, discovered empirically via the
   * `PluginNotFoundException: Unable to determine class for field type
   * 'image'` this suite hit before `image` was added here.
   */
  protected static $modules = ['group', 'gnode', 'options', 'node', 'image', 'taxonomy', 'do_group_membership'];

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

    // The real community_group config's group_node:* relationship types
    // reference specific node type machine names (documentation, event,
    // forum, page, post — mirrors GroupsKernelTestBase::NODE_BUNDLES at the
    // Kernel tier). These node types must exist BEFORE the relationship-type
    // config is imported, or GroupRelationTypeManager throws
    // PluginNotFoundException for the group_node:<bundle> derivative.
    foreach (['documentation', 'event', 'forum', 'page', 'post'] as $node_type) {
      if (!NodeType::load($node_type)) {
        NodeType::create(['type' => $node_type, 'name' => ucfirst($node_type)])->save();
      }
    }

    // Import the REAL assembled community_group config — including
    // creator_wizard: true — rather than reconstructing a simplified
    // fixture, per the brief's explicit instruction that T's new coverage
    // must exercise the actual wizard. The assembled layout
    // (scripts/ci/assemble-config.sh) places the synced config at
    // <repo-root>/config/sync/*.yml — DRUPAL_ROOT is the docroot
    // (<repo-root>/web), so the sync directory is one level up from it.
    $config_path = DRUPAL_ROOT . '/../config/sync';
    if (is_dir($config_path)) {
      $this->importRealCommunityGroupConfig($config_path);
    }

    $this->setUpAccount();
    foreach ($this->groupCreator->getRoles(TRUE) as $role_id) {
      $role = $this->entityTypeManager()->getStorage('user_role')->load($role_id);
      $this->assertInstanceOf(RoleInterface::class, $role);
      $role->grantPermission('create ' . self::GROUP_TYPE_ID . ' group')->save();
    }
  }

  /**
   * Imports the FULL set of `community_group`-related config YAML files from
   * a directory into active config storage.
   *
   * Minimal, self-contained helper (this suite has no shared base class for
   * config import) — reads every `*.yml` file in $dir whose name contains
   * `community_group`, PLUS every `field.storage.group.*` /
   * `field.storage.group_relationship.*` file (field STORAGE config is
   * shared across bundles and does not carry the `community_group` bundle
   * name in its own filename, so it is not caught by the bundle-scoped glob
   * alone — the field INSTANCE config that DOES reference
   * `community_group` in its name depends on the storage config existing
   * first). Writes them via the active config storage, mirroring
   * `drush cim`'s effect for the real assembled `community_group` config.
   *
   * A short hand-picked file list is NOT used here: earlier versions of
   * this helper hit two real gaps in turn, both left as comments for
   * future maintainers:
   *   1. Importing only the group type + two roles threw
   *      `AssertionError: assert($group_relationship_type instanceof
   *      GroupRelationshipTypeInterface)` — the real `community_group`
   *      type is NOT self-contained without its relationship types.
   *   2. Importing bundle-scoped (`*community_group*.yml`) files only threw
   *      `PluginNotFoundException: Unable to determine class for field
   *      type 'image'` — the field STORAGE config (which declares the
   *      field type) lives in separately-named files the bundle-scoped
   *      glob does not match.
   * Importing the union of both globs, plus enabling `image` (see
   * `self::$modules`), resolves both.
   *
   * Falls back silently if the directory does not exist in this checkout
   * (protects against an assembled-vs-source path mismatch failing setUp()
   * itself rather than the test body, which would produce a confusing RED).
   *
   * @param string $dir
   *   The directory containing config YAML files (config/sync).
   */
  protected function importRealCommunityGroupConfig(string $dir): void {
    $storage = \Drupal::service('config.storage');
    $files = array_unique(array_merge(
      glob($dir . '/*community_group*.yml') ?: [],
      glob($dir . '/field.storage.group.*.yml') ?: [],
      glob($dir . '/field.storage.group_relationship.*.yml') ?: [],
    ));
    // Config entities must be written in an order core dependency-resolution
    // would tolerate: field STORAGE first (referenced by field instances),
    // then the group.type.* (referenced by roles/relationship types), then
    // everything else. A simple three-tier sort is sufficient here since
    // this is a flat, non-recursive dependency fan-out for this one
    // bundle's config.
    usort($files, static function (string $a, string $b): int {
      $rank = static function (string $path): int {
        $name = basename($path);
        if (str_starts_with($name, 'field.storage.')) {
          return 0;
        }
        if (str_contains($name, 'group.type.community_group')) {
          return 1;
        }
        return 2;
      };
      return $rank($a) <=> $rank($b);
    });
    foreach ($files as $path) {
      $data = \Drupal\Component\Serialization\Yaml::decode(file_get_contents($path));
      if (!is_array($data)) {
        continue;
      }
      unset($data['uuid'], $data['_core']);
      $name = basename($path, '.yml');
      $storage->write($name, $data);
    }
    \Drupal::service('config.factory')->reset();
    $this->entityTypeManager()->getStorage('group_type')->resetCache();
    $this->entityTypeManager()->getStorage('group_role')->resetCache();
    $this->entityTypeManager()->getStorage('group_relationship_type')->resetCache();
    $this->entityTypeManager()->clearCachedDefinitions();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * AC-1/AC-2/AC-3: submitting the REAL wizard (creator_wizard: true) to
   * completion results in a creator membership carrying BOTH
   * `community_group-admin` AND `community_group-organizer` (AC-1,
   * additive), the creator immediately able to edit the group and reach
   * manage-members (AC-2), and the final submit redirecting to
   * `/group/{group}/created` — NOT the group canonical page (AC-3).
   */
  public function testWizardCreateGrantsOrganizerAndRedirectsToPreview(): void {
    $this->drupalGet('group/add/' . self::GROUP_TYPE_ID);
    $this->assertSession()->statusCodeEquals(200);

    $group_label = 'Wizard-created group ' . $this->randomMachineName(8);

    // Step 1: the label field is present on every variant of the wizard's
    // first step. Fill it and advance with whatever primary action button
    // this step presents (Next, or a direct Save/Create if the wizard turns
    // out to be effectively single-step for this group type).
    $this->assertSession()->fieldExists('label[0][value]');
    $this->getSession()->getPage()->fillField('label[0][value]', $group_label);

    // field_group_description is a REQUIRED plain-text field on the real
    // assembled community_group type (discovered empirically: the initial
    // RED run submitted only the label and got silently re-rendered on the
    // SAME single-step form with no visible error banner in the captured
    // browser_output — inspecting the raw HTML showed
    // field_group_description[0][value] carrying required="required").
    // This form turned out to be effectively single-step for this group
    // type/persona combination (creator_wizard's multi-step UI did not
    // materialize as distinct URLs/pages in this environment) — F/T-green
    // must confirm this holds for the real target environment too.
    if ($this->getSession()->getPage()->hasField('field_group_description[0][value]')) {
      $this->getSession()->getPage()->fillField('field_group_description[0][value]', 'A test group created via the real wizard flow.');
    }

    $this->advanceThroughWizard();

    // POST-SAVE: locate the created group by its distinctive label rather
    // than assuming entity id 1 (a wizard may create intermediate/discarded
    // entities during multi-step flow in some Group 4.x wizard
    // implementations).
    $groups = $this->entityTypeManager()->getStorage('group')->loadByProperties(['label' => $group_label]);
    $group = reset($groups);
    $this->assertNotFalse($group, 'The wizard flow created a group with the submitted label.');

    $this->assertEquals(
      $this->groupCreator->id(),
      $group->getOwnerId(),
      'The logged-in user owns the wizard-created group.',
    );

    // AC-1: the creator's membership carries BOTH Admin (creator_roles) AND
    // Organizer (this story's new grant) — additive, not a replacement.
    $relationships = $group->getRelationshipsByEntity($this->groupCreator, 'group_membership');
    $relationship = reset($relationships);
    $this->assertNotFalse($relationship, 'The wizard flow added the creator as a member.');
    $role_ids = array_column($relationship->get('group_roles')->getValue(), 'target_id');
    $this->assertContains('community_group-admin', $role_ids, 'AC-1: Admin role (creator_roles) is present.');
    $this->assertContains('community_group-organizer', $role_ids, 'AC-1: Organizer role is ALSO present (additive grant this story adds).');

    // AC-2: the creator, as Organizer, can immediately edit the group and
    // reach manage-members.
    $this->assertTrue($group->hasPermission('edit group', $this->groupCreator), 'AC-2: creator can edit the group.');
    $this->assertTrue($group->hasPermission('administer members', $this->groupCreator), 'AC-2: creator can administer members.');

    // AC-3: the final submit redirected to /group/{group}/created — NOT the
    // group canonical page.
    $this->assertSession()->addressEquals('/group/' . $group->id() . '/created');
  }

  /**
   * Walks forward through the wizard's remaining steps (if any), submitting
   * whatever primary action button each intermediate step presents, until
   * reaching and submitting the final save step.
   *
   * Per this file's class docblock: the exact step count/labels are NOT
   * hard-coded, because they were not empirically verifiable at RED-author
   * time (no running site available to T). This walk tries the most likely
   * button labels in priority order and fails with an actionable assertion
   * (the page's actual button set) if none match — surfacing the real
   * wizard shape to F/T-green rather than silently mis-asserting a pass.
   */
  protected function advanceThroughWizard(int $max_steps = 6): void {
    $primary_labels = [
      '/^Create Community Group and become a member$/',
      '/^Create Community Group$/',
      '/^Save and continue$/',
      '/^Next$/',
      '/^Save$/',
    ];

    for ($i = 0; $i < $max_steps; $i++) {
      $page = $this->getSession()->getPage();
      $clicked = FALSE;
      foreach ($primary_labels as $pattern) {
        $buttons = $page->findAll('css', 'input[type="submit"], button');
        foreach ($buttons as $button) {
          $label = $button->getAttribute('value') ?? $button->getText();
          if ($label !== NULL && preg_match($pattern, trim($label))) {
            $button->press();
            $clicked = TRUE;
            break 2;
          }
        }
      }

      if (!$clicked) {
        $available = array_map(
          static fn ($button) => trim((string) ($button->getAttribute('value') ?? $button->getText())),
          $page->findAll('css', 'input[type="submit"], button'),
        );
        $this->fail(sprintf(
          'CreateGroupWizardOrganizerTest::advanceThroughWizard() could not find a recognized primary action button at wizard step %d. Buttons present on this page: [%s]. F/T-green must inspect the actual wizard step sequence and update the $primary_labels patterns (or the wizard-walk approach) accordingly — this is the empirical correction the brief anticipates (handoff-A.md Q3).',
          $i + 1,
          implode(', ', $available),
        ));
      }

      // Stop once we've landed away from the add-group wizard path (either
      // the preview route or the canonical group page) — the final save has
      // happened.
      $current_path = parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH) ?? '';
      if (!str_contains($current_path, '/group/add/')) {
        return;
      }
    }

    $this->fail('CreateGroupWizardOrganizerTest::advanceThroughWizard() exceeded max_steps without leaving the /group/add/ path — the wizard may have more steps than anticipated.');
  }

}
