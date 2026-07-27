<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Email;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\Url;
use Drupal\Core\Utility\Token;
use Drupal\message\MessageInterface;
use Drupal\user\UserInterface;

/**
 * Renders a `do_activity` Message entity into a two-part email payload.
 *
 * #232 (N-4): turns one of the 6 `activity_*` Message templates (do_activity,
 * #116) into `['subject' => ..., 'body_text' => ..., 'body_html' => ...]`,
 * suitable for handoff to any mailer implementation landing in N-1. Actually
 * sending mail (hook_mail, MailManager, SMTP/SendGrid/Mailhog) is explicitly
 * out of scope here — see the brief's "Non-goals".
 *
 * Rendering pipeline (locked, brief v2 "Rendering pipeline", per A block #2):
 * this class delegates body-line token substitution to the SAME `token`
 * service Message's own view builder uses
 * ({@see \Drupal\message\Entity\Message::processTokens()}), rather than
 * re-implementing or bypassing Message's token contract. Every `activity_*`
 * template ships with `settings.token options.token replace: false` (see
 * do_activity's `message.template.activity_*.yml`), so
 * `Message::getText()`'s own (disabled) token pass never fires — this class
 * reads the template's RAW stored text via
 * {@see \Drupal\message\MessageTemplateInterface::getRawText()} and performs
 * the token replacement itself, once, here.
 *
 * `(removed)` semantics (brief v2 §2): `field_referenced_entity_type` /
 * `field_referenced_entity_id` are plain string/integer Message fields (NOT
 * entity_reference — see do_activity's
 * `field.storage.message.field_referenced_entity_id.yml`), so
 * `[message:field_referenced_entity_id]` tokens replace to the raw stored
 * ID/type strings regardless of whether the referenced entity still exists;
 * Token::replace() alone can never surface a deletion here. This class
 * therefore defensively loads the referenced entity itself and, when the
 * Message carries both fields but the load fails, overwrites the residual
 * type/ID slot in the token-replaced text with `(removed)` before handing
 * the string to Twig — Twig performs no token substitution of its own
 * (brief v2 §3).
 *
 * DUAL RENDER PATH (HTML vs. text) — NOT a brief deviation, a Drupal core
 * engine constraint discovered while implementing: Drupal's theme engine
 * hardcodes the `.html.twig` extension for every `#theme`-driven render
 * ({@see \Drupal\Core\Template\TwigThemeEngine::EXTENSION} is unconditionally
 * appended in `renderTemplate()`), so `hook_theme()` + `RendererInterface::
 * renderInIsolation()` can NEVER load a `.txt.twig` file — only Twig's own
 * loader (extension-agnostic) can. So:
 *   - HTML shell/partials render via the normal `#theme` + renderInIsolation
 *     pipeline (loads `email-*.html.twig` through the theme registry, as
 *     {@see \Drupal\do_notifications\Hook\DoNotificationsEmailHooks::theme()}
 *     registers them).
 *   - Text shell/partials are loaded directly through the `twig` environment
 *     service (bypassing the theme registry/engine entirely), with
 *     `{% autoescape false %}` inside each `.txt.twig` file itself, since
 *     `TwigEnvironment` forces HTML autoescaping site-wide by default and a
 *     plain-text email body must not be HTML-entity-encoded.
 * The 14 theme hooks are still all registered (brief AC), including the 6
 * text-format ones, for registry completeness/documentation — EmailRenderer
 * simply never calls `theme()`/`render()` for the text-format hook names.
 *
 * #236 (N-8): {@see self::renderEventFragments()} exposes the per-event body
 * pair (`text_line` + `html_body`, both UNWRAPPED by either shell) so
 * {@see \Drupal\do_notifications\Email\DigestRenderer} can embed N per-event
 * fragments into one combined digest payload without re-implementing the
 * token/`(removed)`-placeholder machinery documented above. `render()` is now
 * an extract-method refactor on top of this same helper (see its own
 * docblock) — the DUAL RENDER PATH note above still governs: `html_body` is
 * produced via the `#theme` + renderInIsolation pipeline (theme registry) and
 * comes back as safe {@see \Drupal\Core\Render\Markup}, cast to `string` for
 * the return array; `text_line` is the same plain string
 * {@see self::renderBodyLine()} always produced. No `.txt.twig` load happens
 * inside `renderEventFragments()` itself, only in the shell-wrapping methods
 * that consume its output.
 */
class EmailRenderer {

  use StringTranslationTrait;

  /**
   * The placeholder substituted for a deleted/missing referenced entity.
   */
  private const REMOVED_PLACEHOLDER = '(removed)';

  /**
   * Custom timestamp format string, matching core's `medium` date format.
   *
   * Copied verbatim from `core.date_format.medium`'s own stored `pattern`
   * ({@see \Drupal\Core\Datetime\DateFormatter::format()}). `date.formatter`'s
   * named 'medium' type resolves through the `core.date_format.medium`
   * CONFIG ENTITY (shipped by System module's default install config), not a
   * hardcoded string. Kernel tests that boot only `group`/`gnode`/`message`/
   * etc. (this module's own
   * {@see \Drupal\Tests\do_notifications\Kernel\EmailRendererTest}) never
   * install System's default config, so that config entity does not exist
   * in the test's config storage and `dateFormat('medium', ...)` — and even
   * its own 'fallback' rescue lookup — both resolve to NULL, throwing on
   * `->getPattern()`. Passing the 'custom' type with this literal pattern
   * (copied verbatim from `core/modules/system/config/install/
   * core.date_format.medium.yml`) produces byte-identical output on a real
   * site (where the config entity exists) while removing the untestable
   * config-entity dependency for this always-rendered timestamp line.
   */
  private const TIMESTAMP_FORMAT = 'D, j M Y - H:i';

  /**
   * Maps each Message template ID to its per-event theme hook base name.
   *
   * The base name is shared by the `_html` theme hook and by the `.txt.twig`
   * partial file loaded directly (bypassing the theme registry — see class
   * docblock "DUAL RENDER PATH"). Also matches
   * {@see \Drupal\do_notifications\Hook\DoNotificationsEmailHooks::EVENTS}.
   */
  private const EVENT_THEME_KEYS = [
    'activity_post_created' => 'post_created',
    'activity_comment_created' => 'comment_created',
    'activity_membership_created' => 'membership_created',
    'activity_group_created' => 'group_created',
    'activity_flagging_created' => 'flagging_created',
    'activity_pin_toggled' => 'pin_toggled',
  ];

  public function __construct(
    private readonly Token $token,
    private readonly RendererInterface $renderer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TwigEnvironment $twig,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Renders a Message into a two-part (+ subject) email payload.
   *
   * Extract-method refactor (#236, N-8): delegates the per-event body pair
   * to {@see self::renderEventFragments()} so the token/removed-placeholder/
   * theme-key derivation lives in exactly one place. Behavior is unchanged
   * from the pre-#236 implementation — only the internal call shape differs.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The activity_* Message to render.
   * @param \Drupal\user\UserInterface $recipient
   *   The user this email is being rendered for; drives the unsubscribe URL.
   *
   * @return array
   *   An array with keys `subject`, `body_text`, `body_html` — all strings.
   */
  public function render(MessageInterface $message, UserInterface $recipient): array {
    $fragments = $this->renderEventFragments($message);
    $unsubscribe_url = $this->unsubscribeUrl($recipient);
    $timestamp = $this->dateFormatter->format($fragments['created'], 'custom', self::TIMESTAMP_FORMAT);

    return [
      'subject' => $this->renderSubject($message),
      'body_text' => $this->renderTextShell($this->eventThemeKey($message), $fragments['text_line'], $unsubscribe_url, $timestamp),
      'body_html' => $this->renderHtmlShellFromFragment($fragments['html_body'], $unsubscribe_url, $timestamp),
    ];
  }

  /**
   * Renders a Message's per-event body pair, unwrapped by either shell.
   *
   * Introduced for #236 (N-8):
   * {@see \Drupal\do_notifications\Email\DigestRenderer} consumes this to
   * embed N per-event fragments into one combined digest without
   * duplicating the token-replacement / `(removed)`-placeholder logic
   * documented on the class. `render()` also calls this (extract-method) so
   * there is exactly one code path deriving a Message's rendered body
   * content.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The activity_* Message to render fragments for.
   *
   * @return array
   *   An array with keys:
   *   - text_line: the token-replaced (and `(removed)`-substituted) plain
   *     body string, identical to the pre-#236 `renderBodyLine()` output.
   *   - html_body: the rendered per-event HTML partial ONLY (the
   *     `#theme => email_<event>_html` sub-render), isolated — no shell
   *     wrap. Safe HTML, cast to `string`.
   *   - created: the Message's own `created` Unix timestamp, cast to `int`
   *     ({@see \Drupal\message\MessageInterface::getCreatedTime()} — see
   *     the explicit `(int)` cast note below).
   */
  public function renderEventFragments(MessageInterface $message): array {
    $event_key = $this->eventThemeKey($message);
    $text_line = $this->renderBodyLine($message);

    return [
      'text_line' => $text_line,
      'html_body' => $this->renderHtmlPartial($event_key, $text_line),
      // #231 (N-3) discovery: explicitly cast to int. getCreatedTime()
      // reads the field item's raw `->value` (NOT
      // `Timestamp::getCastedValue()`, which does cast), and
      // SqlContentEntityStorage::mapFromStorageRecords() assigns a freshly
      // `->load()`-ed entity's field value directly from the PDO-fetched DB
      // row with no cast of its own — MySQL/MariaDB PDO drivers commonly
      // return an `int`-columned value as a numeric PHP string. Every
      // pre-#231 caller of this method (EmailRendererTest/DigestRendererTest)
      // only ever passes an in-memory Message whose `created` was just set
      // via setCreatedTime()/applyDefaultValue() (a real int, never
      // round-tripped through the DB), so this gap was never exercised until
      // #231's delivery worker — the first caller to
      // `getStorage('message')->load($mid)` a Message the queue only knows
      // by numeric id. Without this cast, DigestRenderer::groupByDay()'s
      // `gmdate('Y-m-d', $fragment['created'])` fatals under
      // strict_types=1 ("Argument #2 ($timestamp) must be of type ?int,
      // string given").
      'created' => (int) $message->getCreatedTime(),
    ];
  }

  /**
   * Derives the email subject line from the Message template's label.
   */
  private function renderSubject(MessageInterface $message): string {
    $template = $message->getTemplate();
    $label = $template ? (string) $template->label() : (string) $message->bundle();
    return (string) $this->t('New activity: @label', ['@label' => $label]);
  }

  /**
   * Token-replaces the Message template's raw stored text into one string.
   *
   * Joins multiple `text[]` partials (a Message template may have more than
   * one) with a single space — none of the 6 `activity_*` templates declare
   * more than one partial today, but joining defensively avoids silently
   * dropping a second partial if one is ever added.
   */
  private function renderBodyLine(MessageInterface $message): string {
    $template = $message->getTemplate();
    if (!$template) {
      return self::REMOVED_PLACEHOLDER;
    }

    $raw_partials = array_map(
      static fn(array $partial): string => (string) ($partial['value'] ?? ''),
      $template->getRawText(),
    );
    $raw_text = trim(implode(' ', array_filter($raw_partials, static fn(string $v): bool => $v !== '')));

    $replaced = (string) $this->token->replace($raw_text, ['message' => $message], ['clear' => TRUE]);

    return $this->applyRemovedPlaceholder($replaced, $message);
  }

  /**
   * Substitutes `(removed)` for a referenced entity that no longer loads.
   *
   * `field_referenced_entity_type` / `field_referenced_entity_id` are plain
   * string/integer fields (never entity_reference — see class docblock), so
   * `clear: TRUE` collapsing an UNSET token never fires for these two: the
   * stored ID/type strings are always still there, even after the entity
   * they point at is deleted. This method is the actual `(removed)`
   * boundary: it defensively loads the referenced entity and, if the load
   * fails, rewrites any literal occurrence of the stored type/ID pair in the
   * already-token-replaced text to the placeholder. It also collapses any
   * leftover `[message:field_referenced_entity_...]` token syntax (e.g. if a
   * future template variant leaves one unreplaced) to the same placeholder,
   * so the plain-text body can never contain raw token remnants.
   */
  private function applyRemovedPlaceholder(string $text, MessageInterface $message): string {
    $has_type_field = $message->hasField('field_referenced_entity_type');
    $has_id_field = $message->hasField('field_referenced_entity_id');

    if ($has_type_field && $has_id_field) {
      $entity_type = $message->get('field_referenced_entity_type')->value ?? NULL;
      $entity_id = $message->get('field_referenced_entity_id')->value ?? NULL;

      if ($entity_type !== NULL && $entity_type !== '' && $entity_id !== NULL && $entity_id !== '') {
        $referenced_exists = $this->referencedEntityExists((string) $entity_type, (string) $entity_id);
        if (!$referenced_exists) {
          // Replace the still-present raw type/ID pair the token substitution
          // left behind (these fields are plain strings/integers, so the
          // token replacement always resolves them to their stored value,
          // deleted entity or not) with the placeholder.
          $needle = trim($entity_type . ' ' . $entity_id);
          if ($needle !== '' && str_contains($text, $needle)) {
            $text = str_replace($needle, self::REMOVED_PLACEHOLDER, $text);
          }
        }
      }
    }

    // Defensive net: collapse any unreplaced `[message:` token syntax that
    // reaches this point (should not happen given `clear: TRUE`, but keeps
    // the "no raw token remnants" contract regardless of cause).
    $text = (string) preg_replace('/\[message:[^\]]*\]/', self::REMOVED_PLACEHOLDER, $text);

    return $text;
  }

  /**
   * Whether the entity a Message's referenced-entity fields point at loads.
   */
  private function referencedEntityExists(string $entity_type_id, string $entity_id): bool {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      // Not a loadable content/config entity type (e.g. a bundle string that
      // is not itself an entity type) — nothing to load, so there is nothing
      // to report as missing either; treat as present.
      return TRUE;
    }
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
      return $entity !== NULL;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Builds the absolute unsubscribe URL for the recipient.
   */
  private function unsubscribeUrl(UserInterface $recipient): string {
    return Url::fromRoute('do_notifications.notification_settings', ['user' => $recipient->id()])
      ->setAbsolute()
      ->toString();
  }

  /**
   * Renders the per-event HTML partial in isolation, via the theme registry.
   *
   * Extracted for #236 (N-8) so {@see self::renderEventFragments()} can hand
   * back the bare per-event fragment (no shell wrap) while
   * {@see self::renderHtmlShellFromFragment()} still produces the exact same
   * shell-wrapped HTML `render()` always has.
   */
  private function renderHtmlPartial(string $event_key, string $body_line): string {
    $partial_build = [
      '#theme' => 'email_' . $event_key . '_html',
      '#body_line' => $body_line,
    ];

    return (string) $this->renderer->renderInIsolation($partial_build);
  }

  /**
   * Wraps an already-rendered HTML fragment in the HTML shell.
   *
   * Consumes the `html_body` fragment {@see self::renderEventFragments()}
   * produces. The fragment is re-marked as safe via
   * {@see \Drupal\Core\Render\Markup} before being placed on the `#markup`
   * render-array key — this is the same "already-safe HTML, do not
   * re-escape/re-filter" contract
   * {@see \Drupal\Core\Render\Renderer::ensureMarkupIsSafe()} uses for any
   * `#markup` value already implementing `MarkupInterface`, so this produces
   * byte-identical shell output to the pre-#236 nested-render-array approach.
   */
  private function renderHtmlShellFromFragment(string $html_body, string $unsubscribe_url, string $timestamp): string {
    $shell_build = [
      '#theme' => 'email_shell_html',
      '#body' => ['#markup' => Markup::create($html_body)],
      '#unsubscribe_url' => $unsubscribe_url,
      '#timestamp' => $timestamp,
    ];

    return (string) $this->renderer->renderInIsolation($shell_build);
  }

  /**
   * Renders the text shell + per-event text partial directly through Twig.
   *
   * Bypasses the theme registry entirely (see class docblock "DUAL RENDER
   * PATH" — Drupal's Twig theme engine hardcodes `.html.twig` and can never
   * load a `.txt.twig` file). Loads `email-<event>.txt.twig` and
   * `email-shell.txt.twig` directly via the `twig` environment service,
   * using a module-root-relative path so Twig's filesystem loader (rooted at
   * the Drupal app root) resolves it the same way theme-registry templates
   * are resolved.
   */
  private function renderTextShell(string $event_key, string $body_line, string $unsubscribe_url, string $timestamp): string {
    $templates_path = $this->moduleExtensionList->getPath('do_notifications') . '/templates/emails';

    $partial_name = str_replace('_', '-', $event_key);
    $body = $this->twig->load($templates_path . '/email-' . $partial_name . '.txt.twig')
      ->render(['body_line' => $body_line]);

    return $this->twig->load($templates_path . '/email-shell.txt.twig')
      ->render([
        'body' => $body,
        'unsubscribe_url' => $unsubscribe_url,
        'timestamp' => $timestamp,
      ]);
  }

  /**
   * Resolves the per-event theme-hook base key for a Message's template.
   */
  private function eventThemeKey(MessageInterface $message): string {
    $template_id = $message->bundle();
    return self::EVENT_THEME_KEYS[$template_id] ?? 'unknown';
  }

}
