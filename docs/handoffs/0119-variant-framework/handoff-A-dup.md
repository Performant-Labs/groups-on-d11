# Handoff-A-dup: Phase 7 - SC-F1 Variant framework (anti-duplication gate)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Diff base:** 37e85826712457135de96d767e74babfdf7a115e (origin/main)
**Diff head:** 38e9d3cf53a686457d9e5a887d833dd6ddf03e3e
**Reuse map:** docs/handoffs/0119-variant-framework/survey.md (§Reuse & Analogous-Feature map)
**Verdict:** PASS

## Summary
F extended every object the Reuse map named to extend and built no parallel path. `HelpText` was
appended (not forked) and the net diff after F4's round-4 correction is exactly one live key
(`showcase.switcher.directory.layout`); the dead `showcase.ribbon` key + unused tooltip wiring the
diff-gate flagged was cleanly removed rather than patched into a second copy mechanism. The switcher
tooltip goes through `do_chrome/tooltips` exclusively — no second tippy/popper vendoring anywhere in
`do_showcase`. `/showcase` follows the `do_notifications`/`do_discovery` `ControllerBase` +
`.routing.yml` convention exactly. The ribbon's `page_top` hook is the same single-global-attach-point
shape as `DoChromeHooks::pageAttachments()`, using the correct hook for visible markup rather than
misusing `page_attachments`. `VariantSwitcher` as a plain service (not a `Plugin/Block`) is a
documented, justified divergence from the repo's only existing embeddable-render-surface precedent
(`GroupMissionBlock`/`ContributionStatsBlock`), not an unplanned parallel object — no `Plugin/Block`
directory exists under `do_showcase/src`.

## Findings

No block-level findings. All six anti-duplication checks pass with direct evidence below.

| # | Check | Verdict | Evidence |
|---|---|---|---|
| 1 | HelpText — append-only, no parallel copy store | PASS | `do_chrome/src/HelpText.php` — `do_showcase` calls `\Drupal\do_chrome\HelpText::get()` (`VariantSwitcher.php:102-103`, `$tooltip_key = 'showcase.switcher.' . $instance_id; $tooltip = HelpText::get($tooltip_key);`); no new copy-store class exists in `do_showcase/src`. Net delta after F4: exactly one live appended key, `showcase.switcher.directory.layout` (visible in the tail of `HelpText::all()`'s array). The `showcase.ribbon` key F added in round 1 and removed in round 4 (per decisions.md's F-round-4 entry: "Removed the `showcase.ribbon` key... explicitly authorized by the task brief") leaves zero trace in the current file — confirmed by reading the file's tail: only a code comment records why the key is absent, no orphaned key remains. All pre-existing keys unchanged (F/T's own `HelpTextTest.php` 14/14 non-regression, re-confirmed across every round through T-green4's 42/42). |
| 2 | Tooltip engine — reuse `do_chrome/tooltips`, no second engine | PASS | `find docs/groups/modules/do_showcase -iname "*tippy*" -o -iname "*popper*"` → zero matches (no vendored copy anywhere in the new module). `grep -rn "do_chrome/tooltips" docs/groups/modules/do_showcase/` shows the library is referenced in `do_showcase.module` (doc comment), `do_showcase.libraries.yml` (doc comment), `do_showcase.switcher.js` (doc comment noting it relies on the shared library), `ShowcaseController.php:68` (`$build['#attached']['library'][] = 'do_chrome/tooltips';` — actual attach), and `DoShowcaseHooks.php:65` (doc comment). `do_showcase.switcher.js` and `do_showcase.ribbon.js` contain zero occurrences of "tooltip"/"tippy" as functional code — `do_showcase.ribbon.js` has none at all (the round-4 fix correctly removed the dead `ribbonTooltip` wiring rather than building a second trigger). The switcher's twig template emits `data-do-tooltip="..."` (the exact attribute `do_chrome.tooltips.js`'s `Drupal.behaviors` scans for), not a custom `data-*` tooltip attribute. |
| 3 | Route/controller — `ControllerBase` + `.routing.yml`, not a reinvented pattern | PASS | `do_showcase.routing.yml`'s `do_showcase.showcase` route (`_controller: '\Drupal\do_showcase\Controller\ShowcaseController::page'`, `_permission: 'access content'`) is byte-for-byte the same shape as `do_notifications.notification_settings` (`_controller`, `_permission: 'access content'`) and `do_discovery`'s public-route convention. `ShowcaseController extends ControllerBase`, uses `create(ContainerInterface)` for DI (constructor-promoted `readonly` properties), matching `NotificationSettingsController`'s pattern exactly (confirmed by direct file comparison; F's own handoff and the class doc comment name this precedent explicitly). |
| 4 | Ribbon injection — `page_top`/`page_attachments` consistent with the global-chrome pattern | PASS | `DoShowcaseHooks::pageTop()` uses `#[Hook('page_top')]` — the correct hook for visible markup (a real `<button>`/`<a>`, not attachable via `page_attachments`, which only carries `#attached`). This is a deliberate, documented, correct divergence from `DoChromeHooks::pageAttachments()`'s literal hook name while preserving its "single global attach point" shape — one hook method, invoked once per page, injecting the ribbon exactly once. Both `#[Hook(...)]`-attributed classes are registered identically via `*.services.yml` with the `hook_implementations` tag (`do_showcase.hooks` mirrors `do_chrome`'s own service registration for its hook classes). No second/parallel global-injection mechanism was introduced. |
| 5 | `VariantSwitcher` service — genuinely new capability, not a duplicate of an existing block/embeddable object | PASS | `find docs/groups/modules/do_showcase/src -type d` shows only `Controller/` and `Hook/` — **no `Plugin/Block` directory exists**; F did not create a second block-based render surface alongside or instead of the service. The service-vs-block choice is explicitly justified in `handoff-F.md`'s "Service-vs-Block rationale" section (also reproduced in decisions.md's Phase-5 entry) on the documented distinction that `GroupMissionBlock`/`ContributionStatsBlock` are context-derived from block placement, while `VariantSwitcher::build(string $instance_id, array $options, string $current): array` is always called with explicit caller-supplied parameters — exactly the divergence A's own Phase-3 review (finding #2, warn) asked F to document, and F did so. This was reviewed and accepted at Phase 3 as the correct call, not drift introduced at Phase 5/7. |
| 6 | Other reimplementation of do_chrome/do_discovery/do_notifications or core functionality | PASS | Session persistence: `do_showcase.switcher.js`/`do_showcase.ribbon.js` use `window.sessionStorage` directly (client-side, per Brief-gate B-2 + the diff-gate's B-2 adjudication) — the Reuse map correctly noted no existing `do_*` session-persistence analog exists to extend, so this is new machinery by design, not a duplicate of an unfound pattern; it does not touch `tempstore.private` or any core session API, so there is no core-service duplication either. `ShowcaseCatalog` mirrors `do_chrome\PermissionMatrix`'s exact shape (plain class, `StringTranslationTrait`, no DI) per A's own Phase-3 review — confirmed unchanged through all four F rounds. Cache-context handling (`url.query_args:variant`, added in F round 3) follows Drupal core's own `#cache['contexts']` render-array convention, not a custom caching layer. |

No duplication; extension is clean.

## Notes for F
None required — PASS.

## Additional note: dead-code correction is a clean removal, not residual drift
The diff-gate's B-1 finding (round-1 `do_showcase` shipped an unused `ribbonTooltip` `drupalSettings`
key + a `do_chrome/tooltips` library attach on the ribbon with no consuming `data-do-tooltip` element)
was real dead wiring, not a parallel-path defect — it was an incomplete single mechanism (the reused
`do_chrome/tooltips` engine, correctly, just with a dangling unused entry point), not a second
tooltip system. F4's fix removed the dead wiring outright (per wireframe.md Surface 3, which never
depicts a ribbon tooltip) rather than building a second, ribbon-specific trigger mechanism to match
it — the more conservative and reuse-consistent of the two options the diff-gate offered. Net result
confirmed clean: zero tooltip-related code remains in `do_showcase.ribbon.js`, zero `ribbonTooltip`
string in server-rendered markup (T-green4's live curl/browser re-verification), and the switcher's
own correct tooltip wiring is untouched.
