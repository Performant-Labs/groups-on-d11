# Brief — #132 SD-5 Showcase help

**Review rigor:** none (matches issue).
**Design phase (D):** SKIPPED — trivial visual (append a `<span class="do-showcase-info" tabindex="0" role="note" aria-label data-do-tooltip>ⓘ</span>` inside an existing `<aside>`, verbatim reuse of `PersonaSwitcher::build()` + `GroupTypeContentHelp::infoTrigger()` pattern). No new layout, no new color, no new component.
**A-dup phase:** SKIPPED per lean POC pipeline.
**Brief-gate o4-mini:** SKIPPED per lean POC pipeline.

## Objective
Add help copy + rendering for the showcase's meta-comparison devices — persona banner ⓘ, tour-page around-the-switcher orientation notes for the six catalog entries, and map-view orientation copy — without duplicating SC-F1's per-switcher tooltip (`showcase.switcher.*`) and without touching #120/#121/#122 owned keys.

## Reuse map (default: extend)
See `survey.md` §"Reuse & Analogous-Feature map". Summary:
1. **HelpText copy store** — extend `\Drupal\do_chrome\HelpText::all()`, append a new `showcase_help.*` block. NEVER edit existing keys.
2. **ⓘ trigger markup** — reuse the verbatim `<span class="do-showcase-info" tabindex="0" role="note" aria-label="…" data-do-tooltip="…">ⓘ</span>` pattern already used by `PersonaSwitcher::build()` (dropdown) and `GroupTypeContentHelp::infoTrigger()` (group form).
3. **Persona banner** — extend `DoShowcaseHooks::personaBanner()`: append one more `$children['tooltip_trigger']` render-array node BEFORE the `renderInIsolation()` call. Do not restructure the `<aside>`.
4. **Tour page** — extend `ShowcaseController::page()`: inside the per-entry loop, when `HelpText::get('showcase_help.'.$entry['id']) !== ''`, render an ⓘ trigger next to the entry title. Guard on non-empty.
5. **PHPUnit copy test** — extend `do_chrome/tests/src/Unit/HelpTextTest.php` (or create a lean `do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php` mirroring the existing `ShowcaseHelpTextTest` pattern already present for #119) to cover the new keys.
6. **E2E** — NEW `tests/e2e/showcase-help.spec.ts` (issue "Owns" requires it).

## New keys to add (namespace `showcase_help.*`)

Copy is authored below. Keep values plain text — do_chrome tooltips.js has `allowHTML: false`.

- `showcase_help.persona_banner` — banner-level ⓘ:
  `"This banner shows which persona you're browsing as — switch back at any time via the 'Browse as' dropdown at the top of the page. Groups-Moderate actions really change demo state until the next reseed."`
- `showcase_help.discovery-ranking` — tour-entry orientation:
  `"Three orderings on the same underlying groups: Recent (newest first), Hot (most active), Promoted (editorial). Switch to see how ordering changes what a visitor meets first."`
- `showcase_help.directory-presentation` — tour-entry orientation:
  `"Compact list packs many groups per screen for fast scanning; Cards trade density for per-group detail. The switch is around information density, not content."`
- `showcase_help.membership-models` — tour-entry orientation (two-axis teaching point):
  `"Two axes, kept distinct: visibility (who sees the group) and join policy (how you get in). Open joins instantly; Moderated needs organizer approval; Invite Only is add-by-organizer. Every group here is visible — Private (member-only visibility) is a separate axis."`
- `showcase_help.group-type-homepages` — tour-entry orientation:
  `"The group homepage adapts to the group's type — Events lead with the event calendar, Discussion leads with the stream, Documentation leads with the reference index. Same page contract, different lead section."`
- `showcase_help.stream-model` — tour-entry orientation:
  `"One combined activity stream vs. separate streams per content type. The decision is one feed to scan vs. filtered feeds a member picks."`
- `showcase_help.private-group-reveal` — tour-entry orientation:
  `"Switch personas and watch a private group appear: it is hidden from the anonymous directory and reveals itself only to a member of that group."`
- `showcase_help.persona-switcher` — tour-entry orientation:
  `"Four public personas — Anonymous, Elena (Member), Maria (Organizer), Groups-Moderate. Each meets a different slice of the demo."`
- `showcase_help.map` — map-view orientation (attached to the disabled `Map` switcher option on the tour page's stub switcher AND authored for a future real map widget):
  `"Map view plots groups with a geographic home. Only Geographical groups appear; pan and zoom to explore. Each marker's hover shows the group's name and type."`

Nine new keys total. Namespace prefix `showcase_help.*` — disjoint from `showcase.*` (SC-F1), `persona.*` (#120), `visibility.*` (#121), `group_type.*` (#122), `page.*` (#126).

## Consumer wiring (rendering)

### Persona banner ⓘ (`DoShowcaseHooks::personaBanner()`)
Insert a new `$children` node **before** the existing `'glyph'` entry (or wherever visually sensible — the ⓘ should trail the "switch back" link so it does not visually crowd the leading `▶`):

```php
$children['help'] = [
  '#type' => 'html_tag',
  '#tag' => 'span',
  '#value' => 'ⓘ',
  '#attributes' => [
    'class' => ['do-showcase-info'],
    'tabindex' => '0',
    'role' => 'note',
    'aria-label' => \Drupal\do_chrome\HelpText::get('showcase_help.persona_banner'),
    'data-do-tooltip' => \Drupal\do_chrome\HelpText::get('showcase_help.persona_banner'),
  ],
];
```

Attach `do_chrome/tooltips` library (already attached via `do_showcase/persona-switcher` chain? verify; if not, add to `#attached['library']` in this hook). F: verify library dependency chain and attach if missing.

### Tour page around-the-switcher ⓘ (`ShowcaseController::page()`)
Inside `foreach ($this->catalog->entries() as $entry)`, alongside `title`/`badge`/`decision`, append:

```php
$help_copy = \Drupal\do_chrome\HelpText::get('showcase_help.' . $entry['id']);
if ($help_copy !== '') {
  $item['help'] = [
    '#type' => 'html_tag',
    '#tag' => 'span',
    '#value' => 'ⓘ',
    '#attributes' => [
      'class' => ['do-showcase-info', 'do-showcase-entry-help'],
      'tabindex' => '0',
      'role' => 'note',
      'aria-label' => $help_copy,
      'data-do-tooltip' => $help_copy,
    ],
  ];
}
```

### Map option orientation (same tour page)
The stub switcher includes `['id' => 'map', 'label' => 'Map', 'available' => FALSE]`. Adding a per-option ⓘ requires touching `VariantSwitcher::build()` (a framework change, forbidden by scope guardrail). **Alternative approved**: render a single `showcase_help.map` ⓘ trigger **adjacent to the switcher** in `ShowcaseController::page()`, before the entries loop:

```php
$map_copy = \Drupal\do_chrome\HelpText::get('showcase_help.map');
if ($map_copy !== '') {
  $build['switcher_map_help'] = [
    '#type' => 'html_tag',
    '#tag' => 'span',
    '#value' => 'ⓘ Map view',
    '#attributes' => [
      'class' => ['do-showcase-info', 'do-showcase-map-help'],
      'tabindex' => '0',
      'role' => 'note',
      'aria-label' => $map_copy,
      'data-do-tooltip' => $map_copy,
    ],
  ];
}
```

## Acceptance criteria (issue-derived)
- [ ] Nine `showcase_help.*` keys appended to `\Drupal\do_chrome\HelpText::all()`, each returning non-empty copy.
- [ ] Persona banner (`DoShowcaseHooks::personaBanner()`) renders a `data-do-tooltip` ⓘ trigger when a persona is active; anonymous session renders no banner (unchanged behavior).
- [ ] Tour page (`/showcase`) renders a `data-do-tooltip` ⓘ trigger next to each catalog entry that has a matching `showcase_help.<id>` key, and does NOT render one for entries without a key.
- [ ] Tour page renders the `showcase_help.map` ⓘ adjacent to the stub switcher.
- [ ] No `showcase.*` (SC-F1), `persona.*` (#120), `visibility.*` (#121), `group_type.*` (#122), or `page.*` (#126) key is edited or removed.
- [ ] Playwright spec `tests/e2e/showcase-help.spec.ts` covers: persona banner tooltip trigger (Elena or Groups-Moderate); one tour-page catalog-entry tooltip; the map orientation tooltip.
- [ ] Existing suite stays green (kernel + functional + existing E2E).
- [ ] WCAG 2.2 AA: every new ⓘ is keyboard-reachable (`tabindex=0`), has a non-empty `aria-label`, uses the existing `.do-showcase-info` / `.do-chrome-info` class contrast baseline (verified by #120/#122 predecessors).

## Handoff paths
- Survey: `docs/planning/handoffs/132-showcase-help/survey.md`
- Brief: `docs/planning/handoffs/132-showcase-help/brief.md`
- Decisions journal: `docs/planning/handoffs/132-showcase-help/decisions.md`
- Branch: `132-showcase-help`
- Worktree: `~/Projects/_worktrees/groups-showcase-help`
