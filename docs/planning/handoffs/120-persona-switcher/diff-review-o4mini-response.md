# Orchestrator's Response to Round-1 Diff Review

All 3 BLOCK findings were routed to Feature Implementor (F) and fixed in commit `eb5b595`
(2026-07-23). Verified locally: Kernel+Unit 50/50, Functional 17/17, full custom-module Kernel
regression 123/123, lint 0/0 on all 3 touched files. All 3 WARNs deferred (POC scope + pre-existing
class-convention consistency — see notes below).

## [B-1] Hardcoded `/persona-switch/` URLs → RESOLVED
**File:** `docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php`

`PersonaSwitcher::build()` no longer hardcodes any `/persona-switch/` string. Both the initial
`<form action>` and the JS-usable base path are generated via `Url::fromRoute('do_showcase.persona_switch', [...])`:

- Initial form action = `Url::fromRoute('do_showcase.persona_switch', ['persona' => $current_id])->toString()`.
- JS base prefix is derived by generating the same route with a sentinel persona id
  (`__PERSONA_ID_SENTINEL__`) then stripping the sentinel back out — so JS can concatenate
  `encodeURIComponent(this.value)` after the prefix and always produce a routed URL that respects
  base_url / subdirectory / language prefix.

The banner's switch-back link (`personaBanner()` in `DoShowcaseHooks.php`) also now uses
`Url::fromRoute('do_showcase.persona_switch', ['persona' => 'anonymous'])->toString()` instead of
the previous literal `/persona-switch/anonymous`.

## [B-2] Open-redirect via unvalidated `Referer` → RESOLVED
**File:** `docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php`

`redirectBack()` now validates the `Referer` before use via a new `isSameOriginReferer()` helper
that parses both the referer and the current request's URL and compares scheme + host + port with
default-port normalization. A referer whose scheme/host/port do not match, or that fails to parse,
falls back to `Url::fromRoute('<front>')->toString()`. An attacker-supplied off-site Referer
cannot force `RedirectResponse` to bounce the user off-site.

## [B-3] `personaBanner()` bypassing DI → RESOLVED
**Files:** `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` +
`docs/groups/modules/do_showcase/do_showcase.services.yml`

- Constructor now takes `ShowcaseCatalog $catalog` (alongside the existing `PersonaSwitcher $switcher`).
- `personaBanner()` reads `$this->catalog->personas()` — no `new` call anywhere in the class for
  `ShowcaseCatalog`.
- `do_showcase.services.yml` was updated to inject `@do_showcase.showcase_catalog` into
  `do_showcase.hooks`, and a class-name alias `Drupal\do_showcase\ShowcaseCatalog:
  '@do_showcase.showcase_catalog'` was added, matching the existing `PersonaSwitcher` alias pattern
  required for `#[Hook]` attribute autowiring.
- Sanity-checked `personaSwitcherWidget()` sibling method — it already had no `new` call.

## WARN findings — deferred (rationale)

- **W-1** (inline `onchange` JS + CSP): POC scope; no strict CSP declared in this codebase.
  Deferred as a follow-up when a CSP policy lands.
- **W-2** (`user_logout()` procedural): still valid in Drupal 11 — not on the deprecation list;
  matches core's own usage in `user.module`. Deferred.
- **W-3** (`\Drupal::service(...)` statics in `DoShowcaseHooks`): matches the pre-existing
  `pageTop()` method's convention on the same class. Refactoring one method to full DI while leaving
  the sibling on statics would introduce inconsistency. Deferred as a follow-up spanning the whole
  hook class.

## Verification (post-fix)

- Kernel (do_showcase): 19/19 pass.
- Unit (do_showcase): 31/31 pass.
- Functional (do_showcase): 17/17 pass — including `PersonaBannerTest` which exercises the
  changed banner code path.
- Full custom-module Kernel regression: 123/123 pass (zero collateral).
- Lint (`phpcs --standard=Drupal,DrupalPractice`): 0 errors on all 3 touched source files.

## Diff for your review

The complete diff for the 3 fixes (commit `eb5b595`, base `eb5b595~1`) — all inside
`docs/groups/`:

```diff
diff --git a/docs/groups/modules/do_showcase/do_showcase.services.yml b/docs/groups/modules/do_showcase/do_showcase.services.yml
index eee7b90..de166d8 100644
--- a/docs/groups/modules/do_showcase/do_showcase.services.yml
+++ b/docs/groups/modules/do_showcase/do_showcase.services.yml
@@ -40,6 +40,17 @@ services:
   # in core.services.yml).
   Drupal\do_showcase\Persona\PersonaSwitcher: '@do_showcase.persona_switcher'
 
+  # Phase 6.5 (diff-gate B-3 repair): the SAME class-name-alias need as
+  # PersonaSwitcher directly above, now that DoShowcaseHooks also
+  # constructor-injects ShowcaseCatalog (so personaBanner() can read
+  # $this->catalog->personas() instead of `new ShowcaseCatalog()`-ing its
+  # own throwaway instance). Without this alias, autowiring
+  # DoShowcaseHooks's $catalog parameter would throw the identical
+  # "Cannot autowire service ... references class
+  # Drupal\do_showcase\ShowcaseCatalog ... but no such service exists" error
+  # PersonaSwitcher's alias above already documents.
+  Drupal\do_showcase\ShowcaseCatalog: '@do_showcase.showcase_catalog'
+
   # #120 SC-1 (brief-amendments.md Amendment 3/4): route-level access check
   # for do_showcase.persona_switch — the uid-1 guard + persona-allowlist
   # enforcement, checked BEFORE PersonaSwitchController ever runs. Matches
@@ -63,9 +74,15 @@ services:
   # explicit entry is kept for documentation/consistency with
   # do_notifications.hooks' own precedent, though the autowired registration
   # is what the hook system actually uses at runtime.
+  #
+  # Phase 6.5 (diff-gate B-3 repair): a second argument, the
+  # do_showcase.showcase_catalog service, was added so personaBanner() can
+  # read the shared ShowcaseCatalog instance instead of `new`-ing its own
+  # (see the Drupal\do_showcase\ShowcaseCatalog class-name alias above for
+  # why the autowired registration needs it too).
   do_showcase.hooks:
     class: Drupal\do_showcase\Hook\DoShowcaseHooks
     autowire: false
-    arguments: ['@do_showcase.persona_switcher']
+    arguments: ['@do_showcase.persona_switcher', '@do_showcase.showcase_catalog']
     tags:
       - { name: 'hook_implementations' }
diff --git a/docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php b/docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php
index fc47d01..cec4178 100644
--- a/docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php
+++ b/docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php
@@ -43,6 +43,13 @@
  * the personas' access is mostly additive relative to each other, so a
  * destination 403 is the rare case).
  *
+ * Phase 6.5 (diff-gate B-2 repair): the raw Referer header is
+ * attacker-controlled (a request can carry any `Referer:` value it likes),
+ * so redirecting to it unconditionally is an open redirect. `redirectBack()`
+ * now only trusts the Referer when its scheme+host+port matches the CURRENT
+ * request's own scheme+host+port — an external Referer falls back to
+ * `<front>` instead of being followed.
+ *
  * The constructor-injected entity type manager is stored on
  * `$personaEntityTypeManager`, NOT `$entityTypeManager` — `ControllerBase`
  * already declares a NON-readonly protected `$entityTypeManager` property
@@ -127,6 +134,13 @@ public function switch(string $persona): RedirectResponse {
   /**
    * Redirects to the referring page, falling back to `<front>`.
    *
+   * Phase 6.5 (diff-gate B-2 repair): the Referer header is
+   * attacker-controlled, so it is only trusted when it is same-origin with
+   * the current request (see {@see self::isSameOriginReferer()}) — an
+   * off-site (or malformed) Referer falls back to `<front>` rather than
+   * being followed, closing the open-redirect vector a raw
+   * `new RedirectResponse($referer)` would otherwise expose.
+   *
    * @param \Symfony\Component\HttpFoundation\Request|null $request
    *   The current request, or NULL if unavailable.
    *
@@ -135,10 +149,55 @@ public function switch(string $persona): RedirectResponse {
    */
   private function redirectBack(?Request $request): RedirectResponse {
     $referer = $request?->headers->get('referer');
-    if (!empty($referer)) {
+    if ($request !== NULL && !empty($referer) && $this->isSameOriginReferer($referer, $request)) {
       return new RedirectResponse($referer);
     }
     return $this->redirect('<front>');
   }
 
+  /**
+   * Determines whether $referer is same-origin with the current $request.
+   *
+   * Compares scheme, host, and port as separate parsed components against
+   * the current request's own `getScheme()`/`getHost()`/`getPort()` — never
+   * a raw string prefix/substring check, so a Referer that merely happens to
+   * start with the site's domain as a substring (e.g.
+   * `https://example.com.attacker.test/`) is correctly rejected, and default
+   * ports (80 for http, 443 for https) compare equal to an implicit/absent
+   * port on either side.
+   *
+   * @param string $referer
+   *   The raw `Referer` header value.
+   * @param \Symfony\Component\HttpFoundation\Request $request
+   *   The current request.
+   *
+   * @return bool
+   *   TRUE if $referer is same-origin with the current request.
+   */
+  private function isSameOriginReferer(string $referer, Request $request): bool {
+    $referer_scheme = parse_url($referer, PHP_URL_SCHEME);
+    $referer_host = parse_url($referer, PHP_URL_HOST);
+
+    if (!is_string($referer_scheme) || $referer_scheme === '' || !is_string($referer_host) || $referer_host === '') {
+      // No scheme/host at all (e.g. a relative Referer, or an unparsable
+      // value) — never trust it; the caller falls back to `<front>`.
+      return FALSE;
+    }
+
+    if (strtolower($referer_scheme) !== strtolower($request->getScheme())) {
+      return FALSE;
+    }
+
+    if (strtolower($referer_host) !== strtolower($request->getHost())) {
+      return FALSE;
+    }
+
+    $referer_port = parse_url($referer, PHP_URL_PORT);
+    $default_port = ($referer_scheme === 'https') ? 443 : 80;
+    $referer_effective_port = $referer_port ?? $default_port;
+    $request_effective_port = $request->getPort() ?? $default_port;
+
+    return (int) $referer_effective_port === (int) $request_effective_port;
+  }
+
 }
diff --git a/docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php b/docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php
index b776f5c..53d5ad1 100644
--- a/docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php
+++ b/docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php
@@ -58,11 +58,22 @@
  * All three hooks assign disjoint keys into the same `$page_top` array
  * (`do_showcase_ribbon` / `do_showcase_persona_switcher` /
  * `do_showcase_persona_banner`), which `page_top` natively supports.
+ *
+ * Phase 6.5 (diff-gate B-3 repair): `personaBanner()` used to `new
+ * ShowcaseCatalog()` its own throwaway instance instead of using the shared
+ * `do_showcase.showcase_catalog` service — bypassing the DI container and
+ * risking divergence from every other consumer of that service (e.g.
+ * `PersonaSwitcher`, which is constructor-injected with the SAME instance).
+ * `ShowcaseCatalog` is now constructor-injected here too (as `$catalog`),
+ * matching how `$personaSwitcher` is already injected, and
+ * `do_showcase.services.yml`'s `do_showcase.hooks` entry now passes
+ * `@do_showcase.showcase_catalog` as a second argument.
  */
 class DoShowcaseHooks {
 
   public function __construct(
     private readonly PersonaSwitcher $personaSwitcher,
+    private readonly ShowcaseCatalog $catalog,
   ) {}
 
   /**
@@ -190,6 +201,14 @@ public function personaSwitcherWidget(array &$page_top): void {
    * (`ShowcaseCatalog::personas()`), consumed identically by the switcher's
    * `<option>` text and this banner.
    *
+   * Phase 6.5 (diff-gate B-3 repair): this method used to `new
+   * ShowcaseCatalog()` its own instance rather than using the shared
+   * `do_showcase.showcase_catalog` service already injected everywhere else
+   * that reads persona data (`PersonaSwitcher`). Now reads
+   * `$this->catalog->personas()` — the constructor-injected instance — so
+   * there is exactly one `ShowcaseCatalog` instance in play across the
+   * request, matching how `PersonaSwitcher` is already injected.
+   *
    * The visible "switch back" text is carried by the real `<a>` link itself
    * (not baked into the preceding text span), so the banner's rendered text
    * concatenates to the exact issue phrasing with no duplicated phrase:
@@ -232,11 +251,10 @@ public function personaBanner(array &$page_top): void {
       return;
     }
 
-    $catalog = new ShowcaseCatalog();
     $account_name = $current_user->getAccountName();
 
     $active_persona = NULL;
-    foreach ($catalog->personas() as $persona) {
+    foreach ($this->catalog->personas() as $persona) {
       if ($persona['uname'] !== NULL && $persona['uname'] === $account_name) {
         $active_persona = $persona;
         break;
diff --git a/docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php b/docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php
index 2297942..c046de8 100644
--- a/docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php
+++ b/docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php
@@ -7,6 +7,7 @@
 use Drupal\Core\Render\Markup;
 use Drupal\Core\Session\AccountProxyInterface;
 use Drupal\Core\StringTranslation\StringTranslationTrait;
+use Drupal\Core\Url;
 use Drupal\do_chrome\HelpText;
 use Drupal\do_showcase\ShowcaseCatalog;
 
@@ -46,6 +47,20 @@
  * submits the form to its last-rendered `action` — a real, focusable,
  * Enter-activatable `<button>`, never `#type => submit`.
  *
+ * Phase 6.5 (diff-gate B-1 repair): both the form's initial `action` and the
+ * `onchange` handler's rewritten `action` are built from
+ * `Url::fromRoute('do_showcase.persona_switch', [...])`, never a hand-
+ * written `/persona-switch/` literal — a multisite/subdirectory/language-
+ * prefix/path-alias install can legitimately generate a different base path,
+ * and a hard-coded literal would silently point at the wrong path there. The
+ * JS-usable "base path the value gets appended to" is derived by generating
+ * the URL for a sentinel persona id (`self::PERSONA_ID_SENTINEL`, a string
+ * that cannot collide with any real allowlisted persona id or with anything
+ * `rawurlencode()` would alter) and stripping that sentinel back off the
+ * generated string — the same URL generator produces both the initial
+ * action and the JS prefix, so there is exactly one source of truth for the
+ * route's path shape.
+ *
  * Current selection is resolved from REAL session state (never a hardcoded
  * default): if the current user is authenticated and their account name
  * matches one of the 4 personas() `uname` values, that option is selected;
@@ -68,6 +83,18 @@ final class PersonaSwitcher {
 
   use StringTranslationTrait;
 
+  /**
+   * A sentinel persona-id value used only to derive the switch-URL prefix.
+   *
+   * Phase 6.5 (diff-gate B-1 repair): all-uppercase + underscores, so it can
+   * never collide with a real persona id (`anonymous`, `elena-garcia`,
+   * `maria-chen`, `moderator` — all lowercase/hyphenated) and contains no
+   * character `rawurlencode()`/the route generator would alter, so it
+   * survives URL generation intact and can be reliably located and stripped
+   * back out of the generated string.
+   */
+  private const PERSONA_ID_SENTINEL = '__PERSONA_ID_SENTINEL__';
+
   public function __construct(
     private readonly ShowcaseCatalog $showcaseCatalog,
     private readonly AccountProxyInterface $currentUser,
@@ -111,13 +138,29 @@ public function build(): array {
     // The form action always starts pointing at the CURRENTLY-selected
     // persona's own switch path — a safe, self-consistent default (never a
     // dead/placeholder path) that the inline onchange handler below
-    // rewrites the moment a different option is chosen.
-    $initial_action = '/persona-switch/' . rawurlencode($selected_id);
+    // rewrites the moment a different option is chosen. Generated via the
+    // route's own URL generator (Phase 6.5 / diff-gate B-1), never a
+    // hand-written `/persona-switch/` literal.
+    $initial_action = Url::fromRoute('do_showcase.persona_switch', ['persona' => $selected_id])->toString();
+
+    // The JS-usable prefix: generate the same route's URL for the sentinel
+    // id, then strip the sentinel back off — whatever base path the URL
+    // generator produced (accounting for a subdirectory install, a language
+    // prefix, or a path alias) is preserved; only the sentinel itself is
+    // removed, leaving a prefix the onchange handler can safely concatenate
+    // `encodeURIComponent(this.value)` onto.
+    $sentinel_action = Url::fromRoute('do_showcase.persona_switch', ['persona' => self::PERSONA_ID_SENTINEL])->toString();
+    $action_prefix = str_replace(self::PERSONA_ID_SENTINEL, '', $sentinel_action);
+    $action_prefix_js = htmlspecialchars(
+      json_encode($action_prefix, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
+      ENT_QUOTES,
+      'UTF-8'
+    );
 
     $markup = '<form method="post" action="' . htmlspecialchars($initial_action, ENT_QUOTES, 'UTF-8') . '" class="do-showcase-persona-switcher-form">'
       . '<label for="persona-switcher-select">' . htmlspecialchars((string) $this->t('Browse as'), ENT_QUOTES, 'UTF-8') . '</label> '
       . '<span class="do-showcase-info" tabindex="0" role="note" aria-label="' . $wrapper_tooltip_attr . '" data-do-tooltip="' . $wrapper_tooltip_attr . '">ⓘ</span> '
-      . '<select id="persona-switcher-select" name="persona" onchange="this.form.action=\'/persona-switch/\'+encodeURIComponent(this.value);this.form.submit();">'
+      . '<select id="persona-switcher-select" name="persona" onchange="this.form.action=' . $action_prefix_js . '+encodeURIComponent(this.value);this.form.submit();">'
       . $options_markup
       . '</select> '
       . '<button type="submit" class="do-showcase-persona-switcher-go">' . htmlspecialchars((string) $this->t('Go'), ENT_QUOTES, 'UTF-8') . '</button>'
```
