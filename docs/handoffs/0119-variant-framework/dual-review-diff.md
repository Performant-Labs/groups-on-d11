## Implementation Review (Round 1)

### BLOCK findings

**[B-1]** docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php:27  
— The ribbon hook injects only a container with text, link and dismiss button; it never renders an ⓘ tooltip trigger (e.g. a `<span class="do-showcase-info" data-do-tooltip="…">ⓘ</span>`), so the appended `HelpText::get('showcase.ribbon')` copy is never consumed by `do_chrome/tooltips`.  
— Blocks: The brief requires a ribbon tooltip surface (append-only HelpText key `showcase.ribbon`) and reuse of the tippy.js engine; without a tooltip trigger element the ribbon tooltip cannot appear.  
— Remediation: Add a tooltip trigger element into the ribbon render array (with the correct class, `data-do-tooltip` attribute set from `drupalSettings.doShowcase.ribbonTooltip`, tabindex/role attributes) so `do_chrome/tooltips` can initialize it.

**[B-2]** docs/groups/modules/do_showcase/js/do_showcase.switcher.js (and ribbon.js):1  
— Uses `window.localStorage` to persist variant choice and ribbon dismissal.  
— Blocks: The brief specifies “per session” persistence; `localStorage` endures across browser restarts, not just the current session. This violates the per-session requirement and cannot be undone by closing the tab.  
— Remediation: Use `sessionStorage` or a session-scoped cookie (no expires/max-age attribute) so the data clears on browser close, fulfilling the “per session” contract.

**[B-3]** tests/e2e/showcase.spec.ts: lines ~360  
— No end-to-end assertion that live entries render the required “View this comparison” deep-link. The spec only checks that coming entries have no dead link, but never verifies that a live entry (e.g. “Discovery ranking”) actually includes the link element.  
— Blocks: Acceptance criterion requires that live comparisons include a deep-link. Without a test for its presence, an implementation could omit or mis-render this link unnoticed.  
— Remediation: Add a Playwright test that locates the “Discovery ranking” entry on `/showcase` and asserts the presence (and correct `href`) of its “View this comparison” link.

**[B-4]** Unverified runtime claim: reuse of `do_chrome/tooltips` for both switcher and ribbon tooltips.  
— Blocks: The code attaches the `do_chrome/tooltips` library but never inspects or documents how `do_chrome.tooltips.js` bootstraps itself (e.g. on `Drupal.behaviors`, on `DOMContentLoaded`, how it re-scans for `[data-do-tooltip]`). Without verifying the tooltip engine’s initialization behavior, it is unknown whether it will pick up the newly rendered switcher or ribbon triggers.  
— Remediation: Review the actual `do_chrome/tooltips.js` initialization code or official docs to confirm it will attach to server-rendered elements and any AJAX inserts. Add a simple functional check that hovering/focusing the ⓘ trigger shows a tooltip.

**[B-5]** Unverified runtime claim: attribute-based hook discovery for `#[Hook('page_top')]`.  
— Blocks: The module relies on Drupal’s attribute-driven `hook_implementations` infrastructure to invoke `DoShowcaseHooks::pageTop()`. Unless you verify that the `hook_implementations` service tag and the `Hook` attribute support `page_top` hooks in the current core version, there is no guarantee the ribbon injection method runs.  
— Remediation: Confirm in core’s `HookServiceProvider` (or equivalent) that `#[Hook('page_top')]` on a service-tagged class is discovered and invoked. Add a unit or minimal functional test asserting that markup from `pageTop()` appears in `$page_top`.

### WARN findings

**[W-1]** docs/groups/modules/do_showcase/js/do_showcase.switcher.js: anchor role=radio  
Recommendation: Using `<a role="radio">` mixes navigation semantics with a widget role. Consider replacing anchors with `<button role="radio">` or non-semantic elements (`<div>` or `<span>`) made focusable to match ARIA practice and avoid confusing screen readers that expect an anchor to navigate.

**[W-2]** docs/groups/modules/do_showcase/css/do_showcase.css: use of `:focus-visible`  
Recommendation: Not all browsers support `:focus-visible`. Add a fallback style for `:focus` or include a small polyfill to ensure visible focus rings in all supported browsers.

**[W-3]** docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php: use of global `t()`  
Recommendation: Class methods outside of `StringTranslationTrait` get warnings from phpcs. Either inject the translation service via trait or call `\Drupal::translation()->translate()` explicitly to satisfy the coding standard.

### NIT findings

**[NIT-1]** docs/groups/modules/do_showcase/css/do_showcase.css: mixed indentation (2 vs. 4 spaces) — standardize.  
**[NIT-2]** docs/groups/modules/do_showcase/libraries.yml: trailing comma in dependencies list — remove for consistency.  
**[NIT-3]** docs/groups/modules/do_showcase/templates/do-showcase-variant-switcher.html.twig: uses `create_attribute(wrapper_attributes)` but the variable naming is inconsistent (`#attributes` vs. `#wrapper_attributes`) — consider unifying.  
**[NIT-4]** docs/groups/modules/do_showcase/js/do_showcase.switcher.js: missing file-level header comment matching Drupal’s JS standards (e.g. `@file` block).

### Verdict

BLOCK — critical gaps in ribbon tooltip surface, persistence scope, and missing deep-link test must be resolved before proceeding to full testing.
