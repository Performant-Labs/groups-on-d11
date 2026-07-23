## Implementation Review (Round 2

### BLOCK finding responses

[B-1] ACCEPTED  
The diff replaces every literal “/persona-switch/” with Url::fromRoute() calls and a sentinel-based prefix derivation in PersonaSwitcher::build(), and the banner’s “switch back” link in DoShowcaseHooks now also uses Url::fromRoute(). This eliminates all hard-coded paths, honors subdirectory/language prefixes or aliases, and is covered by the updated PersonaBannerTest and other functional tests.

[B-2] ACCEPTED  
redirectBack() now parses the Referer header with PHP’s parse_url, compares scheme, host, and effective port against the current request (normalize default ports), and only issues a RedirectResponse($referer) when it truly matches origin. Any malformed or off-site Referer falls back to a front-page redirect. This fully closes the open-redirect vector.

[B-3] ACCEPTED  
DoShowcaseHooks now constructor-injects ShowcaseCatalog alongside PersonaSwitcher, the in-class “new ShowcaseCatalog()” has been removed, and do_showcase.services.yml has been updated with the needed class-name alias and service arguments. There is now a single, shared ShowcaseCatalog instance across the module.

### Verdict

PASS — all BLOCK findings have been satisfactorily addressed; testing may proceed.
