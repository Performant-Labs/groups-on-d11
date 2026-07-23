<?php

declare(strict_types=1);

namespace Drupal\do_showcase\Persona;

use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\do_chrome\HelpText;
use Drupal\do_showcase\ShowcaseCatalog;

/**
 * Builds the "Browse as" persona-switcher header widget render array (#120).
 *
 * A plain, no-DI-container-lookup service (`do_showcase.persona_switcher`) —
 * same shape as `\Drupal\do_showcase\VariantSwitcher`
 * (StringTranslationTrait, pure render-array construction, explicit
 * constructor-injected collaborators, `autowire: false` in
 * `do_showcase.services.yml`).
 *
 * wireframe.md §1 (APPROVED): native `<select id="persona-switcher-select"
 * name="persona">` inside a one-control `<form method="post">`, auto-
 * submitting on `change` via progressive enhancement — a real
 * `<button type="submit">Go</button>` (NEVER `#type => submit`, which
 * Drupal renders as `<input type="submit">` — the PROJECT_CONTEXT.md /
 * WAVE-EXECUTION-HANDOFF.md §6.9 gotcha) covers the no-JS case. Each
 * `<option>` carries a native `title=` attribute (the closest per-option
 * hover mechanism a real `<select>` supports — wireframe.md §1 "Open
 * questions" #1, resolved: accepted as the correct engineering
 * interpretation of "each option carries a tooltip"); the wrapper also
 * carries exactly ONE combined ⓘ do_chrome tooltip trigger (the same
 * one-tooltip-per-widget convention `VariantSwitcher` established).
 *
 * Route contract (`do_showcase.persona_switch` at
 * `/persona-switch/{persona}`, brief-amendments.md Amendment 4/10): the
 * form's `action` is rendered pointing at the CURRENTLY-SELECTED persona's
 * own switch path (a safe, always-valid, self-targeting default — never a
 * placeholder path). A minimal inline `onchange` handler rewrites `action`
 * to the newly-picked option's own `/persona-switch/<id>` path and submits
 * immediately — this needs no external JS file to accomplish the "auto-
 * submit on change" progressive enhancement (wireframe.md §1), so it works
 * in every JS-enabled browser (the overwhelming majority) without a network
 * round-trip for a settings/behavior file first. In the (rare) fully-JS-
 * disabled case, the visible `<button type="submit">Go</button>` still
 * submits the form to its last-rendered `action` — a real, focusable,
 * Enter-activatable `<button>`, never `#type => submit`.
 *
 * Current selection is resolved from REAL session state (never a hardcoded
 * default): if the current user is authenticated and their account name
 * matches one of the 4 personas() `uname` values, that option is selected;
 * otherwise (anonymous, or an authenticated account that is not one of the 3
 * persona accounts) the `anonymous` option is selected. Declares
 * `#cache['contexts'] => ['user']` (brief-amendments.md Amendment 6) so a
 * cached render is never served across a session/persona switch.
 *
 * The assembled markup string is wrapped in {@see Markup::create()} — every
 * dynamic piece is escaped with `htmlspecialchars()` before assembly, so the
 * whole string is already safe. Without this, the renderer's
 * `ensureMarkupIsSafe()` would XSS-filter `#markup` against
 * `Xss::getAdminTagList()`, which does not include `form`/`select`/`option`/
 * `button`, and would silently strip every one of those tags, leaving only
 * the bare visible text (the exact same gotcha
 * `RestoreGroupForm::preRenderAsButtonTag()` already documents and works
 * around for its own real `<button>`).
 */
final class PersonaSwitcher {

  use StringTranslationTrait;

  public function __construct(
    private readonly ShowcaseCatalog $showcaseCatalog,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Builds the persona-switcher render array.
   *
   * @return array
   *   A render array for the labeled `<select>` + no-JS `<button>` fallback
   *   widget, cache-context-tagged `['user']`.
   */
  public function build(): array {
    $personas = $this->showcaseCatalog->personas();
    $selected_id = $this->resolveCurrentPersonaId($personas);

    $options_markup = '';
    foreach ($personas as $persona) {
      $option_label = $this->optionLabel($persona);
      $tooltip_text = HelpText::get($persona['tooltip_key']);
      $selected_attr = ($persona['id'] === $selected_id) ? ' selected' : '';
      $options_markup .= sprintf(
        '<option value="%s" title="%s"%s>%s</option>',
        htmlspecialchars($persona['id'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($tooltip_text, ENT_QUOTES, 'UTF-8'),
        $selected_attr,
        htmlspecialchars($option_label, ENT_QUOTES, 'UTF-8')
      );
    }

    // The wrapper-level combined ⓘ tooltip: one short line naming all four
    // personas, sourced from the same 4 persona.* HelpText keys (wireframe.md
    // §4: "kept in sync by reading from the same HelpText::get('persona.*')
    // values at render time, not hand-duplicated strings").
    $wrapper_tooltip = implode(' ', array_map(
      static fn (array $p): string => HelpText::get($p['tooltip_key']),
      $personas
    ));
    $wrapper_tooltip_attr = htmlspecialchars($wrapper_tooltip, ENT_QUOTES, 'UTF-8');

    // The form action always starts pointing at the CURRENTLY-selected
    // persona's own switch path — a safe, self-consistent default (never a
    // dead/placeholder path) that the inline onchange handler below
    // rewrites the moment a different option is chosen.
    $initial_action = '/persona-switch/' . rawurlencode($selected_id);

    $markup = '<form method="post" action="' . htmlspecialchars($initial_action, ENT_QUOTES, 'UTF-8') . '" class="do-showcase-persona-switcher-form">'
      . '<label for="persona-switcher-select">' . htmlspecialchars((string) $this->t('Browse as'), ENT_QUOTES, 'UTF-8') . '</label> '
      . '<span class="do-showcase-info" tabindex="0" role="note" aria-label="' . $wrapper_tooltip_attr . '" data-do-tooltip="' . $wrapper_tooltip_attr . '">ⓘ</span> '
      . '<select id="persona-switcher-select" name="persona" onchange="this.form.action=\'/persona-switch/\'+encodeURIComponent(this.value);this.form.submit();">'
      . $options_markup
      . '</select> '
      . '<button type="submit" class="do-showcase-persona-switcher-go">' . htmlspecialchars((string) $this->t('Go'), ENT_QUOTES, 'UTF-8') . '</button>'
      . '</form>';

    return [
      '#markup' => Markup::create($markup),
      '#attached' => [
        'library' => [
          'do_chrome/tooltips',
          'do_showcase/persona-switcher',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * Resolves which persona id should be marked selected.
   *
   * Derives the id from real session state (never a hardcoded default).
   *
   * @param array<int, array{id: string, name: string, description: \Drupal\Core\StringTranslation\TranslatableMarkup, uname: string|null, tooltip_key: string}> $personas
   *   The persona list, as returned by ShowcaseCatalog::personas().
   *
   * @return string
   *   The id of the persona whose `uname` matches the current authenticated
   *   user's account name, or 'anonymous' if the session is anonymous or
   *   does not match any persona account.
   */
  private function resolveCurrentPersonaId(array $personas): string {
    if ($this->currentUser->isAnonymous()) {
      return 'anonymous';
    }

    $current_account_name = $this->currentUser->getAccountName();
    foreach ($personas as $persona) {
      if ($persona['uname'] !== NULL && $persona['uname'] === $current_account_name) {
        return $persona['id'];
      }
    }

    return 'anonymous';
  }

  /**
   * Builds the visible `<option>` label for a persona.
   *
   * Wireframe.md §1 expanded-state table: "Elena Garcia — Member",
   * "Maria Chen — Organizer", "Groups-Moderate" (no role suffix), and plain
   * "Anonymous".
   *
   * @param array{id: string, name: string, description: \Drupal\Core\StringTranslation\TranslatableMarkup, uname: string|null, tooltip_key: string} $persona
   *   One persona entry.
   *
   * @return string
   *   The visible option label.
   */
  private function optionLabel(array $persona): string {
    return match ($persona['id']) {
      'elena-garcia' => (string) $this->t('Elena Garcia — Member'),
      'maria-chen' => (string) $this->t('Maria Chen — Organizer'),
      'moderator' => (string) $this->t('Groups-Moderate'),
      default => (string) $this->t('Anonymous'),
    };
  }

}
