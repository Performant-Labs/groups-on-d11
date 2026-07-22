<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * AC-4 — `field_membership_status` config-shape (list_string, active/pending/
 * blocked) on the `community_group-group_membership` relationship bundle.
 *
 * No new "joined date" field is expected per [B-4] — "joined date" reuses the
 * relationship entity's own base `created` field, so this test also asserts
 * NO `field_joined_date` storage config was added (would be scope creep
 * beyond the locked brief).
 *
 * Pure-PHP YAML read, same technique as GroupRoleConfigShapeTest.
 *
 * @group do_group_membership
 */
final class MembershipStatusFieldConfigShapeTest extends UnitTestCase {

  /**
   * Locates a file under the repo root by walking up from this test file.
   */
  protected function locate(string $relative): ?string {
    $dir = __DIR__;
    for ($i = 0; $i < 10; $i++) {
      $candidate = $dir . '/' . $relative;
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
   * AC-4: field storage is list_string with exactly active/pending/blocked.
   */
  public function testFieldStorageShape(): void {
    $file = $this->locate('docs/groups/config/field.storage.group_relationship.field_membership_status.yml');
    $this->assertNotNull($file, 'field.storage.group_relationship.field_membership_status.yml exists.');
    $data = Yaml::parse((string) file_get_contents((string) $file));

    $this->assertSame('field_membership_status', $data['field_name'] ?? NULL);
    $this->assertSame('group_relationship', $data['entity_type'] ?? NULL);
    $this->assertSame('list_string', $data['type'] ?? NULL);

    $values = array_column($data['settings']['allowed_values'] ?? [], 'value');
    sort($values);
    $this->assertSame(['active', 'blocked', 'pending'], $values, 'Exactly the three allowed values from [B-3].');
  }

  /**
   * AC-4: the field instance is attached to the community_group-group_membership
   * bundle specifically (not some other relationship-type bundle).
   */
  public function testFieldInstanceAttachedToMembershipBundle(): void {
    $file = $this->locate('docs/groups/config/field.field.group_relationship.community_group-group_membership.field_membership_status.yml');
    $this->assertNotNull($file, 'The field instance config exists on the community_group-group_membership bundle.');
    $data = Yaml::parse((string) file_get_contents((string) $file));

    $this->assertSame('field_membership_status', $data['field_name'] ?? NULL);
    $this->assertSame('group_relationship', $data['entity_type'] ?? NULL);
    $this->assertSame('community_group-group_membership', $data['bundle'] ?? NULL);
    $this->assertSame('list_string', $data['field_type'] ?? NULL);
  }

  /**
   * [B-4]: no new joined-date field was added — "joined date" reuses the
   * relationship entity's existing base `created` field. A
   * `field_joined_date` storage config appearing here would be scope creep
   * beyond what the brief locked.
   */
  public function testNoNewJoinedDateFieldAdded(): void {
    $file = $this->locate('docs/groups/config/field.storage.group_relationship.field_joined_date.yml');
    $this->assertNull($file, 'No field_joined_date storage was added — joined date reuses the base `created` field per [B-4].');
  }

}
