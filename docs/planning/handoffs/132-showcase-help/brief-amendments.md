# Brief amendments — #132

## Amendment 1 (from A review, PASS with soft findings)

### A1 — Persona banner ⓘ placement
The brief example inserts `$children['help']` *before* the `'glyph'` entry, contradicting the prose ("trail the 'switch back' link"). **Corrected placement**: append `$children['help']` **AFTER** `'switch_back'`, so the final `$children` order is:

```
glyph, text, switch_back, help
```

That matches the visual intent (ⓘ trails the switch-back link) and F must code it that way.

### A2 — Explicit tooltips library attach on persona banner
`personaBanner()` currently attaches only `do_showcase/persona-switcher`. F MUST add `do_chrome/tooltips` explicitly to `#attached['library']` in that same hook — do NOT rely on a transitive dependency. Matches `ShowcaseController::page()` (`$build['#attached']['library'][] = 'do_chrome/tooltips'`) and `PersonaSwitcher::build()` (attaches directly).

Final `#attached` in `personaBanner()`:
```php
'#attached' => [
  'library' => [
    'do_showcase/persona-switcher',
    'do_chrome/tooltips',
  ],
],
```

## Amendment 2 (F wiring note)
F should also `use \Drupal\do_chrome\HelpText;` at the top of `DoShowcaseHooks.php` and `ShowcaseController.php` rather than FQCN inline.
