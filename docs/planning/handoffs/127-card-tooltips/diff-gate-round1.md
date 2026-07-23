## Implementation Review (Round 1)

### BLOCK findings  
None.

### WARN findings  
**[W-1] web/themes/custom/groups_chrome/groups_chrome.theme:207**  
The code calls `HelpText::get()` without a fully-qualified class name. Please verify that at the top of this file there is a `use Drupal\do_chrome\HelpText;` statement (or else call `\Drupal\do_chrome\HelpText::get()`) to avoid a PHP fatal error if the class isn’t imported.  

**[W-2] web/themes/custom/groups_chrome/groups_chrome.theme:207**  
The `groups_chrome_preprocess_node()` hook unconditionally populates `$variables['gc_stream']['tooltips']` for every node. Consider scoping that logic to the `node--stream-card` suggestion (e.g. via `groups_chrome_preprocess_node__stream_card()`) so you don’t waste cycles on other node templates.  

**[W-3] web/themes/custom/groups_chrome/templates/content/node--stream-card.html.twig:51**  
The ⓘ trigger uses `role="note"`. A note role is passive; screen readers may not announce it as interactive. You may wish to revisit the ARIA pattern—either use a `role="button"` on the trigger or manage `aria-describedby` from the tooltip container—to align more closely with standard tooltip semantics.  

**[W-4] docs/planning/handoffs/127-card-tooltips/**  
The handoff and planning documents (brief.md, decisions.md, handoff-*.md, survey.md) have been checked into the main repo. If these files aren’t needed at runtime, consider moving them to a separate planning-only branch or an external docs site to avoid cluttering the production codebase.

### NIT findings  
**[NIT-1] web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig:72**  
Indentation of the `{% if gc_directory.tooltips.members %}` block is deeper than its siblings; normalizing to two-space indent will match the surrounding Twig.  

**[NIT-2] tests/e2e/element-tooltips.spec.ts:35**  
The XPath expressions for “following-sibling” are correct but hard to read. A CSS adjacent-sibling selector (e.g. `.gc-directory-card__type + [data-do-tooltip]`) may be more maintainable.  

**[NIT-3] docs/groups/modules/do_chrome/src/HelpText.php:212**  
The new help-text strings are raw English. All entries in `HelpText::all()` are returned verbatim; if you intend to localize these tooltips, wrap each string in Drupal’s `t()` or otherwise integrate with the translation system.  

**[NIT-4] docs/groups/modules/do_chrome/src/HelpText.php:207–241**  
The block comment describing the new `card.*` keys runs well past 80 characters per line. Splitting long lines will match the file’s existing style and improve readability.

### Verdict  
PASS — no BLOCK findings; this implementation is ready for the test phase.
