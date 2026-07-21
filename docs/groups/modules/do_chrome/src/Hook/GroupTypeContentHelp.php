<?php

declare(strict_types=1);

namespace Drupal\do_chrome\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\do_chrome\HelpText;

/**
 * #89 (CH-B2): field-level ⓘ help for Group Type and content-type choices.
 *
 * A <select> cannot carry a tooltip on each individual <option>, so each of the
 * two surfaces gets ONE field-level "ⓘ" trigger that lists what every choice
 * means. Both triggers are rendered from the approved copy in
 * \Drupal\do_chrome\HelpText (keys 'group_type.field' and 'content_type.field')
 * and initialised by the shared do_chrome/tooltips behaviour (already attached
 * globally by DoChromeHooks::pageAttachments()), so this class attaches no new
 * library.
 *
 * Kept as its own small #[Hook] class — with its own copy keys and its own
 * form targets — so the epic #78 B-story surfaces stay parallel-safe: no two
 * stories edit the same method or file.
 *
 * Honesty note: both surfaces are BACKED by seeded/enforced reality after
 * CH-F4 (#95): the 5 group_type terms are seeded and every demo group is
 * tagged, and all 5 group_node content types (forum, documentation, event,
 * post, page) are real relationships a member can create. Nothing here is
 * `⚠ ASPIRATIONAL`.
 *
 * @see \Drupal\do_chrome\Hook\DoChromeHooks::pageAttachments()
 */
class GroupTypeContentHelp {

  /**
   * The five node types that are wired as group_node content on the demo.
   *
   * Used to scope the content-type ⓘ to node-create forms for group content
   * only (not, e.g., the non-group `article` type). Node add/edit form ids are
   * `node_<type>_form`.
   */
  private const GROUP_NODE_TYPES = [
    'forum',
    'documentation',
    'event',
    'post',
    'page',
  ];

  /**
   * Adds the "ⓘ" tooltip listing all 5 group types to the Group Type field.
   *
   * Targets the community_group add/edit forms
   * (`group_community_group_add_form` / `group_community_group_edit_form`),
   * where `field_group_type` is a taxonomy-reference <select>. Since options
   * cannot each hover, the ⓘ is rendered once as a sibling element weighted to
   * sit just after the field's widget, carrying the combined per-option copy.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!in_array($form_id, ['group_community_group_add_form', 'group_community_group_edit_form'], TRUE)) {
      return;
    }
    if (!isset($form['field_group_type']) || !is_array($form['field_group_type'])) {
      return;
    }
    $copy = HelpText::get('group_type.field');
    if ($copy === '') {
      return;
    }
    // A field-widget wrapper (WidgetBase) does not render `#field_suffix`, so
    // the ⓘ is added as its own sibling element, weighted to follow the widget.
    $weight = $form['field_group_type']['#weight'] ?? 0;
    $form['do_chrome_group_type_help'] = $this->infoTrigger($copy, 'do-chrome-group-type-help');
    $form['do_chrome_group_type_help']['#weight'] = $weight + 0.1;
  }

  /**
   * Adds the "ⓘ" tooltip listing all 5 content types to node-create forms.
   *
   * The node add form fixes ONE content type per form, so there is no
   * content-type <select> to hover — instead this surfaces, on every group
   * content node form, a single field-level ⓘ (beside the title field) that
   * lists what all five group content types are for, so a creator sees the full
   * menu of purposes at the point of creating any one of them.
   *
   * Scoped to the 5 group_node types via the `node_<type>_form` id so it never
   * decorates non-group content forms.
   */
  #[Hook('form_alter')]
  public function nodeFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $matched = FALSE;
    foreach (self::GROUP_NODE_TYPES as $type) {
      if ($form_id === 'node_' . $type . '_form') {
        $matched = TRUE;
        break;
      }
    }
    if (!$matched) {
      return;
    }
    if (!isset($form['title']) || !is_array($form['title'])) {
      return;
    }
    $copy = HelpText::get('content_type.field');
    if ($copy === '') {
      return;
    }
    // Rendered as a sibling weighted to sit just after the title field (present
    // on every node form) so the content-type guidance appears with the first
    // thing the creator fills in. A field-widget wrapper does not render
    // `#field_suffix`, so a sibling element is used instead.
    $weight = $form['title']['#weight'] ?? -5;
    $form['do_chrome_content_type_help'] = $this->infoTrigger($copy, 'do-chrome-content-type-help');
    $form['do_chrome_content_type_help']['#weight'] = $weight + 0.1;
  }

  /**
   * Builds a render array for a hoverable "ⓘ" tooltip trigger.
   *
   * The returned element carries `data-do-tooltip`, which the shared
   * do_chrome/tooltips behaviour binds tippy.js to. `tabindex="0"` and a
   * screen-reader label keeps the trigger keyboard- and AT-reachable even
   * though the tooltip text is repeated in the attribute. It is wrapped in a
   * form-item container so it renders as a sibling form element.
   *
   * @param string $copy
   *   The plain-text tooltip copy (allowHTML is disabled downstream).
   * @param string $class
   *   A surface-specific CSS class for the trigger span.
   *
   * @return array
   *   A render array for an inline "ⓘ" trigger span.
   */
  private function infoTrigger(string $copy, string $class): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      // U+24D8 CIRCLED LATIN SMALL LETTER I — the "ⓘ" info glyph.
      '#value' => 'ⓘ',
      '#attributes' => [
        'class' => ['do-chrome-info', $class],
        'tabindex' => '0',
        'role' => 'note',
        'aria-label' => $copy,
        'data-do-tooltip' => $copy,
      ],
    ];
  }

}
