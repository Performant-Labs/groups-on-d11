<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_extras\Functional;

use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\PermissionScopeInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;

/**
 * #134 SC-7 — Private groups (view-access axis), end-to-end route/directory
 * coverage.
 *
 * RED (Phase 4, authored by T before F implements). Functional
 * (BrowserTestBase, self-provisions) coverage of AC-2/AC-3/AC-5/AC-9 on real
 * HTTP requests/responses — mirrors the established pattern in
 * {@see \Drupal\Tests\do_group_membership\Functional\JoinPolicyEnforcementTest}
 * and {@see \Drupal\Tests\do_group_extras\Functional\GroupRestoreAccessTest}.
 *
 * Uses the stark theme (no groups_chrome theme dependency for the
 * access-control assertions) EXCEPT for the two badge-DOM assertions (AC-9),
 * which install `groups_chrome` so the real `.gc-privacy-badge` markup from
 * the wireframe (`group--full.html.twig`) actually renders — a stark-theme
 * assertion of that selector would be a false negative (theme not installed),
 * not a valid RED for the badge markup itself.
 *
 * Persona naming follows the brief's actual seeded persona (`elena_garcia`),
 * NOT a synthetic user, so this suite's semantics track the real demo
 * scenario the story ships for (AC-5's persona-switcher dependency).
 *
 * #190 Gap-2 (harness, not production): `views.view.all_groups.yml` (the
 * `/all-groups` directory) is NOT shipped in any module's `config/install` —
 * it lives in `docs/groups/config/`, parallel to
 * `views.view.group_content_stream` — so BrowserTestBase's minimal install
 * never provisions it, and every `/all-groups` GET 404s regardless of the
 * privacy-gate production code under test (confirmed: the real shipped
 * `views.view.all_groups.yml` additionally depends on `geofield` +
 * `field_group_location` (a geofield-type field), which this suite does NOT
 * install — see the class-level comment on the fixture copy below for why).
 * Installed here from a MODULE-LOCAL fixture copy
 * (`tests/fixtures/config/`), mirroring
 * {@see \Drupal\Tests\do_tests\Kernel\DirectoryFiltersTest}'s identical
 * `FileStorage` pattern for the same view — never a source-relative
 * `__DIR__/../../../../../config` path, which passes in the source tree but
 * fails in CI's assembled layout (PROJECT_CONTEXT.md "Fixtures & test
 * authorship"). The fixture copy here is REDUCED from the real, shipped
 * `docs/groups/config/views.view.all_groups.yml`: it drops the
 * `field_group_location` (geofield) field/dependency and the
 * `settings.link_to_entity` / `date_format` cosmetic field-formatter keys,
 * none of which affect the query/status/privacy behavior under test in this
 * suite (label, description, created fields; status/label/location/
 * field_group_language filters) — the SAME reduction
 * `DirectoryFiltersTest::setUp()` already documents and uses.
 *
 * @group do_group_extras
 * @group group
 */
class PrivacyDirectoryTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
    'do_group_extras',
    'do_group_membership',
    'views',
    // #140 landed field.storage.group.field_group_links.yml (type: link) into
    // docs/groups/config/, which the assembled config import step of any
    // functional test pulls in during site install. Without link.module in
    // this test module list, the storage config fails to install with
    // PluginNotFoundException("link"), and every test method setUp fails.
    // Depends indirectly on #140 config; scoped to this test.
    'link',
    // #190 Gap-2: the all_groups view's `field_group_description` field
    // (type: text_long) requires the text module for its field type plugin;
    // its `field_group_language` filter (type: language, plugin_id:
    // "language") requires `language` + `do_group_language` — the latter for
    // `hook_field_views_data_alter()`, which rewrites the Views-data
    // `filter.id` for field_group_language's dedicated-table column from the
    // generic `string` to `language` (without it, initHandlers() resolves
    // the language filter to Broken) — mirrors DirectoryFiltersTest's own
    // module list for the identical field/filter. Deliberately does NOT add
    // `geofield`: the reduced fixture view (see class-level comment) drops
    // the geofield-typed `field_group_location` field/dependency entirely,
    // so geofield is never required by this suite.
    'text',
    'language',
    'do_group_language',
    // #190 Gap-2: groups_chrome's own
    // groups_chrome_preprocess_views_view_fields__all_groups() calls
    // HelpText::get() unconditionally (no class_exists() guard, unlike its
    // sibling call sites in the same .theme file) to resolve the directory
    // card's tooltip copy — a fatal "Class Drupal\do_chrome\HelpText not
    // found" otherwise, confirmed empirically (500 on /all-groups, traced via
    // the BrowserTestBase HTML output capture). Mirrors this SAME module's
    // sibling {@see
    // \Drupal\Tests\do_group_extras\Functional\GroupRestoreAccessTest}
    // enabling do_chrome for the identical groups_chrome-theme dependency.
    'do_chrome',
  ];

  /**
   * {@inheritdoc}
   *
   * `groups_chrome` renders the `.gc-privacy-badge` markup under test (AC-9);
   * it is the project's real custom theme, not a fixture invention — see
   * `web/themes/custom/groups_chrome` per the brief's "Files to touch" list.
   */
  protected $defaultTheme = 'groups_chrome';

  /**
   * The community_group-shaped group type.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * The private "Security Team" group.
   *
   * @var \Drupal\group\Entity\GroupInterface
   */
  protected $securityTeam;

  /**
   * A public group, for the "unchanged for anonymous" contrast assertions.
   *
   * @var \Drupal\group\Entity\GroupInterface
   */
  protected $publicGroup;

  /**
   * The member ("elena_garcia" persona) account.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $elena;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-member',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['view group', 'view group_node:post entity'],
    ]);
    // Baseline outsider + anonymous grants mirror the real seeded
    // group.role.community_group-{outsider_view,anon_view}.yml — every group
    // is viewable-by-default; the private-privacy gate must override this
    // default for `private` groups only (AC-2/AC-3).
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-outsider_view',
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => 'authenticated',
      'admin' => FALSE,
      'permissions' => ['view group'],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-anon_view',
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => 'anonymous',
      'admin' => FALSE,
      'permissions' => ['view group'],
    ]);

    FieldStorageConfig::create([
      'field_name' => 'field_group_privacy',
      'entity_type' => 'group',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'public' => 'Public',
          'unlisted' => 'Unlisted',
          'private' => 'Private',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_privacy',
      'entity_type' => 'group',
      'bundle' => $this->groupType->id(),
      'label' => 'Privacy',
      'default_value' => [['value' => 'public']],
    ])->save();

    // #190 Gap-2: field_group_description — the all_groups view's
    // Description column — is a pre-existing baseline field (already shipped
    // in docs/groups/config/, owned by do_group_extras' own group-form
    // story), installed here via direct API calls, NOT a fixture — there is
    // nothing new about this field's shape in this story. `required: false`
    // here (unlike the real shipped instance's `required: true`) is
    // deliberate: this suite's createGroup() calls never set a value for it,
    // and the fixture-installed field_config is authoritative for THIS
    // test's schema, independent of the real shipped config.
    FieldStorageConfig::create([
      'field_name' => 'field_group_description',
      'entity_type' => 'group',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_description',
      'entity_type' => 'group',
      'bundle' => $this->groupType->id(),
      'label' => 'Description',
    ])->save();

    // #190 Gap-2: field_group_language — the all_groups view's exposed
    // language filter's target field — is likewise a pre-existing baseline
    // field (owned by do_group_language), installed via direct API calls the
    // same way {@see \Drupal\Tests\do_tests\Kernel\DirectoryFiltersTest}
    // installs it, NOT a fixture.
    FieldStorageConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'type' => 'language',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'bundle' => $this->groupType->id(),
      'label' => 'Primary language',
    ])->save();

    // Core's LanguageFilter::access() hard-gates on
    // LanguageManager::isMultilingual() (>1 configured language) — without a
    // second language, the view's exposed language filter is unconditionally
    // hidden from initHandlers(), which would otherwise leave the view's
    // filter handlers in an unexpected state on render. Mirrors
    // DirectoryFiltersTest's identical fixture for the same field/filter.
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Install the shipped `all_groups` view + the new location-field config
    // entities from module-local fixtures (mirrors DirectoryFiltersTest's
    // FileStorage pattern for the same view — see class-level comment for
    // the geofield/link_to_entity/date_format reduction rationale).
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager = \Drupal::entityTypeManager();

    foreach ([
      'field_storage_config' => 'field.storage.group.field_group_location_text',
      'field_config' => 'field.field.group.community_group.field_group_location_text',
      'view' => 'views.view.all_groups',
    ] as $storage_id => $config_name) {
      $data = $fixtures->read($config_name);
      $this->assertNotFalse($data, sprintf('Fixture %s exists and is readable.', $config_name));
      $entity_type_manager->getStorage($storage_id)->create($data)->save();
    }

    // The `all_groups` view's page_1 display registers the `/all-groups`
    // route dynamically (Views' own RouteSubscriber, triggered by the view
    // config entity's postSave()). PROJECT_CONTEXT.md's gotcha #4 documents
    // that a NEW route added mid-test-run needs an explicit,
    // same-request `router.builder->rebuild()` — the deferred/lazy rebuild
    // path is not reliably observed by this test's own subsequent
    // drupalGet() calls, which execute as fresh HTTP requests against the
    // already-installed site rather than replaying this process's request
    // lifecycle.
    \Drupal::service('router.builder')->rebuild();

    $this->securityTeam = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Security Team',
      'status' => 1,
    ]);
    // Second save bypasses DoGroupExtrasHooks::entityPresave auto-unpublish
    // (which only fires on new entities). The presave hook flips status to 0
    // whenever the current user lacks 'administer group|s' — during BrowserTestBase
    // setUp() that is uid 0 (anonymous), so the initial createGroup would leave
    // the group unpublished, and every subsequent anonymous/non-admin GET on its
    // canonical route would return 403 regardless of the privacy axis under test.
    $this->securityTeam->set('status', 1);
    $this->securityTeam->set('field_group_privacy', 'private');
    $this->securityTeam->save();

    $this->publicGroup = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Drupal NorCal',
      'status' => 1,
    ]);
    // field_group_privacy defaults to 'public' — left unset intentionally, to
    // pin the AC-1 default-value contract at the functional layer too.
    // Second save bypasses DoGroupExtrasHooks::entityPresave auto-unpublish
    // (which only fires on new entities) so status stays 1 for anonymous GET.
    $this->publicGroup->set('status', 1);
    $this->publicGroup->save();

    $this->elena = $this->drupalCreateUser([], 'elena_garcia');
    $this->securityTeam->addMember($this->elena, ['group_roles' => ['community_group-member']]);
  }

  /**
   * AC-2: anonymous GET on the private group's canonical route returns 403.
   */
  public function testAnonymousGetsAccessDeniedOnPrivateGroupCanonical(): void {
    $this->drupalGet('/group/' . $this->securityTeam->id());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-3: anonymous GET on /all-groups does NOT contain the literal string
   * "Security Team" anywhere in the response body — a literal-string
   * assertion (A advisory #3), not merely a CSS-selector absence, so a
   * leak via an unstyled/alternate render path is still caught.
   */
  public function testAnonymousAllGroupsOmitsSecurityTeamLiterally(): void {
    $this->drupalGet('/all-groups');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('Security Team');
  }

  /**
   * Negative (unchanged-for-anonymous baseline): the PUBLIC group is
   * unaffected by this story — still 200 and still present in /all-groups
   * for anonymous.
   */
  public function testAnonymousStillSeesPublicGroup(): void {
    $this->drupalGet('/group/' . $this->publicGroup->id());
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/all-groups');
    $this->assertSession()->pageTextContains('Drupal NorCal');
  }

  /**
   * AC-5: Elena (member) sees Security Team in /all-groups, and GET on its
   * canonical route returns 200.
   */
  public function testMemberSeesPrivateGroupInDirectoryAndCanonical(): void {
    $this->drupalLogin($this->elena);

    $this->drupalGet('/all-groups');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Security Team');

    $this->drupalGet('/group/' . $this->securityTeam->id());
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * AC-9: Elena's session on the private group's canonical page renders the
   * `.gc-privacy-badge[data-do-tooltip]` DOM element (wireframe §2).
   */
  public function testMemberSeesPrivacyBadgeOnPrivateGroupCanonical(): void {
    $this->drupalLogin($this->elena);

    $this->drupalGet('/group/' . $this->securityTeam->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.gc-privacy-badge[data-do-tooltip]');
  }

  /**
   * AC-9 negative: the SAME session viewing a PUBLIC group renders NO
   * `.gc-privacy-badge` — the badge is silent for non-private groups
   * (wireframe §1/§2 "renders ONLY when field_group_privacy == 'private'").
   */
  public function testMemberSeesNoPrivacyBadgeOnPublicGroupCanonical(): void {
    $this->publicGroup->addMember($this->elena, ['group_roles' => ['community_group-member']]);
    $this->drupalLogin($this->elena);

    $this->drupalGet('/group/' . $this->publicGroup->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', '.gc-privacy-badge');
  }

}
