## Implementation Review (Round 1)

### BLOCK findings

**[B-1] docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php:157**  
Hard-coded form action and inline JS use “/persona-switch/…” rather than Drupal’s URL generator.  
Why it blocks: In a multisite, subdirectory, language‐prefix or path‐alias setup, “/persona-switch/…” may be incorrect and will break routing.  
Remediation: Generate the URL with Url::fromRoute('do_showcase.persona_switch', ['persona' => $id])->toString() (or $this->url()), and pass that into both the form action attribute and the JS, instead of hard-coding “/persona-switch/”.

**[B-2] docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php:137**  
`redirectBack()` takes the raw Referer header and passes it straight to `RedirectResponse`, allowing an attacker to supply an external URL in the Referer and force an open redirect.  
Why it blocks: Open redirect is a security vulnerability and must be prevented.  
Remediation: Validate that the Referer is an internal path (e.g. parse with Url::fromUri('internal:…') or compare against the site’s base path) before redirecting; otherwise fall back to `'<front>'`.

**[B-3] docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php:168**  
`personaBanner()` directly does `new ShowcaseCatalog()` instead of using the shared `do_showcase.showcase_catalog` service injected into the class.  
Why it blocks: Bypasses the DI container, duplicates logic, and risks divergent behavior or losing translation configuration.  
Remediation: Inject the ShowcaseCatalog service into DoShowcaseHooks (via constructor and service definition) and use that instance rather than instantiating a raw object.

### WARN findings

**[W-1] docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php:27**  
Inline `onchange="…this.form.submit()…"` JavaScript will violate strict CSP policies and makes maintenance harder.  
Recommendation: Move the auto‐submit behavior into a Drupal.behaviors JavaScript file in the module library.

**[W-2] docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php:14**  
The use of the procedural `user_logout()` function is deprecated and bypasses newer session management abstractions.  
Recommendation: Use the session or account switcher service (e.g. `\Drupal::currentUser()->logout()` or the account logout handler) for consistency and testability.

**[W-3] docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php:153**  
Static calls to `\Drupal::service('renderer')` and `\Drupal::currentUser()` rather than injecting these services hamper testability and violate DI practices.  
Recommendation: Inject the renderer and current_user services into the hook class.

### NIT findings

**[NIT-1] docs/groups/modules/do_showcase/src/Plugin/Block/PersonaSwitcherBlock.php:45**  
The block’s `getCacheContexts()` method re-declares the “user” context which is already bubbled up from the render array returned by `PersonaSwitcher::build()`. You can omit this override or merge only if you need to document the context explicitly.

### Verdict

BLOCK — 3 blocking findings must be addressed before testing may proceed.
