<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\group\Functional\GroupBrowserTestBase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * D2 (#44) — the community_group add form renders its creation fields.
 *
 * Bug: there was no `core.entity_form_display.group.community_group.default`
 * anywhere, so `/group/add/community_group` rendered only Title even though the
 * bundle carries `field_group_description`, `field_group_visibility`, and
 * `field_group_image`. This functional test drives the real request stack: it
 * reconstructs the community_group type, installs the three field storages +
 * instances (mirroring the assembled `config/sync` shape), imports the *actual*
 * authored form-display YAML from `docs/groups/config/`, then loads the add
 * form and asserts the intended fields render.
 *
 * `field_group_type` and `field_group_language` are deliberately kept OFF the
 * create form (auto-set / not user-picked at creation), so the test also
 * asserts the type field does not render.
 *
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class GroupAddFormFieldsTest extends GroupBrowserTestBase {

  /**
   * The group type id under test (mirrors the assembled community_group).
   */
  protected const GROUP_TYPE_ID = 'community_group';

  /**
   * The group type label.
   */
  protected const GROUP_TYPE_LABEL = 'Community Group';

  /**
   * The authored form-display config file, relative to the repo root.
   */
  protected const FORM_DISPLAY_YAML = 'docs/groups/config/core.entity_form_display.group.community_group.default.yml';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'field', 'text', 'options', 'image'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Grants the global permissions the group creator needs.
   *
   * @return string[]
   *   The site-level permissions.
   */
  protected function getGlobalPermissions() {
    return ['administer group'] + parent::getGlobalPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Reconstruct the community_group type.
    $this->createGroupType([
      'id' => self::GROUP_TYPE_ID,
      'label' => self::GROUP_TYPE_LABEL,
      'creator_membership' => TRUE,
    ]);

    // Install the three creation field storages + instances (mirrors the
    // assembled config/sync shape the runbook installs).
    $this->createField('field_group_description', 'text_long', 'Description', TRUE);
    $this->createField('field_group_visibility', 'list_string', 'Visibility', TRUE, [
      'allowed_values' => ['open' => 'Open', 'closed' => 'Closed'],
    ]);
    $this->createField('field_group_image', 'image', 'Group Image', FALSE);

    // Import the ACTUAL authored form-display YAML — this is the artifact under
    // test. If the file is missing or omits a field, this test fails.
    $this->importFormDisplay();

    // Log in a user and grant its role(s) the per-type create permission.
    $this->setUpAccount();
    foreach ($this->groupCreator->getRoles(TRUE) as $role_id) {
      $role = $this->entityTypeManager()->getStorage('user_role')->load($role_id);
      $this->assertInstanceOf(RoleInterface::class, $role);
      $role->grantPermission('create ' . self::GROUP_TYPE_ID . ' group')->save();
    }
  }

  /**
   * Creates a field storage + instance on the community_group bundle.
   *
   * @param string $field_name
   *   The field machine name.
   * @param string $type
   *   The field type plugin id.
   * @param string $label
   *   The field label.
   * @param bool $required
   *   Whether the instance is required.
   * @param array $storage_settings
   *   (optional) Storage-level settings (e.g. allowed_values).
   */
  protected function createField(string $field_name, string $type, string $label, bool $required, array $storage_settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'group',
      'type' => $type,
      'settings' => $storage_settings,
    ])->save();

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'group',
      'bundle' => self::GROUP_TYPE_ID,
      'label' => $label,
      'required' => $required,
    ])->save();
  }

  /**
   * Imports the authored form-display YAML as the group add-form display.
   *
   * Reads `docs/groups/config/core.entity_form_display.group.community_group
   * .default.yml`, strips the config-import-only keys, and creates the
   * EntityFormDisplay from it so the add form uses the real artifact.
   */
  protected function importFormDisplay(): void {
    $file = $this->locateFormDisplayYaml();
    $this->assertNotNull($file, 'The authored community_group add-form display YAML was found.');

    $data = Yaml::parse((string) file_get_contents($file));
    // Drop only the config-import bookkeeping keys. Keep `status: true` — the
    // runtime form-display resolver (EntityFormDisplay::collectRenderDisplay)
    // filters candidates on `status`, so an unpublished display would be
    // silently ignored in favour of an empty default (all fields hidden).
    unset($data['uuid'], $data['_core'], $data['dependencies']);

    EntityFormDisplay::create($data)->save();
  }

  /**
   * Locates the authored form-display YAML across supported repo layouts.
   *
   * The module is authored under `docs/groups/modules/do_tests` but the runbook
   * copies `do_*` into `web/modules/custom/`. In both layouts the repo root
   * (containing `docs/groups/config/`) sits some levels above this file, and
   * the whole repo is mounted into the test container. Walk up from here until
   * the canonical YAML is found.
   *
   * @return string|null
   *   The absolute path to the YAML, or NULL if it could not be found.
   */
  protected function locateFormDisplayYaml(): ?string {
    $dir = __DIR__;
    // Ascend at most a handful of levels to the repo root.
    for ($i = 0; $i < 10; $i++) {
      $candidate = $dir . '/' . self::FORM_DISPLAY_YAML;
      if (is_file($candidate)) {
        return $candidate;
      }
      $parent = dirname($dir);
      if ($parent === $dir) {
        break;
      }
      $dir = $parent;
    }
    return NULL;
  }

  /**
   * The add form renders description, visibility, and image (and Title).
   *
   * Regression assertion for #44: before the form display was authored, only
   * Title rendered. Now the three intended creation fields must render, and the
   * deliberately-hidden type field must not.
   */
  public function testAddFormRendersCreationFields(): void {
    $this->drupalGet('group/add/' . self::GROUP_TYPE_ID);
    $this->assertSession()->statusCodeEquals(200);

    $assert = $this->assertSession();

    // Title (the group label base field) still renders.
    $assert->fieldExists('label[0][value]');

    // Description — text_textarea widget.
    $assert->fieldExists('field_group_description[0][value]');

    // Visibility — options_buttons widget renders radio inputs named by field.
    $assert->fieldExists('field_group_visibility');

    // Image — image_image widget renders a file upload input.
    $assert->fieldExists('files[field_group_image_0]');

    // Type and language are deliberately OFF the create form.
    $assert->fieldNotExists('field_group_type');
    $assert->fieldNotExists('field_group_language[0][value]');
  }

}
