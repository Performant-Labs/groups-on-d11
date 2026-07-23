# Survey — #132 SD-5 Showcase help

## Scope decoded (from issue #132)
Help COPY + rendering for the meta-comparison devices themselves, without duplicating SC-F1's per-switcher tooltip (`showcase.switcher.*`, already shipped in #119).

Six sub-surfaces named in issue:
1. **Persona device (SC-1)** — help on the "Browse as" dropdown *and* the "You're browsing as X" banner.
2. **Join + visibility at moment of interacting (SC-2)** — pending/gated states.
3. **Group-type-adaptive homepage note (SC-3)** — verify existing key covers all 3 layouts.
4. **Map view (SC-6)** — orientation note (map is `coming`, not built).
5. **List-vs-cards (SC-5) + three-way discovery tabs (SC-4)** — audit-only; add copy only where SC-F1 switcher tooltip is not enough.
6. **Two-axis visibility × join policy (#134 + #121)** — consistency check on existing keys.

Scope guardrail per prompt: ONLY help/tour copy + rendering. NO new routes, NO new fields, NO showcase-framework changes.

## Current state of each surface (audit)

### 1. Persona device (SC-1)
- **Dropdown**: `PersonaSwitcher::build()` **ALREADY** renders `<span class="do-showcase-info" tabindex="0" role="note" aria-label="..." data-do-tooltip="...">ⓘ</span>` next to the "Browse as" label (`docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php:162`). Copy = per-persona `persona.*` HelpText keys, combined at wrapper level. **COVERED — no work needed.**
- **Banner**: `DoShowcaseHooks::personaBanner()` renders `<aside role="status" ...>▶ You're browsing as {label} — <a>switch back</a></aside>` (`.../src/Hook/DoShowcaseHooks.php:238`). **NO ⓘ trigger.** GAP — issue names this explicitly.

### 2. Join + visibility (SC-2)
- `visibility.field/open/moderated/invite_only` keys exist in HelpText (added #121). Rendered on the group add/edit FORM per `VisibilityTooltip.php`.
- The "lived" (persona-encounters) surface would be at moments like the pending-membership state screen or the invite-only 403. **Those surfaces do not currently carry a `data-do-tooltip`.** Grep for pending / request submitted:
  - `JoinPolicyEnforcementTest` (`.../do_group_membership/tests/src/Functional/`) exercises join enforcement — no help text asserted there.
  - The request-to-join success message is inside `do_group_membership` (bespoke #121). Adding a `data-do-tooltip` to a Drupal messenger flash message is fragile.
- **Realistic scope**: append HelpText copy under `showcase_help.join.*` for future consumers and cross-link from the tour page; do NOT edit `do_group_membership` (scope guardrail: no framework changes). Audit note in PR body.

### 3. Group-type-adaptive homepage (SC-3)
- `group_type.homepage_adapts` already exists (#122): "This page adapts to the group's type — it leads with events, discussion, or documentation depending on how the group is categorised."
- The three layouts are events / discussion / documentation. Copy **already names all three**. **AUDIT-PASS — no gap. Note in PR.**

### 4. Map view (SC-6)
- Map is a **`coming` catalog entry** in `ShowcaseCatalog::entries()`. The stub switcher on `/showcase` includes a `Map (unavailable)` option, but no map markup exists.
- No `data-do-tooltip` target exists. **Add HelpText copy under `showcase_help.map.*`** so a future consumer (real map widget) can attach it. Also allow the tour page to surface it near the disabled `Map` option (opt-in, non-invasive).

### 5. List-vs-cards (SC-5) + discovery tabs (SC-4)
- SC-5 (directory) = `directory-presentation` catalog entry, status `coming` — the switcher is a stub. `showcase.switcher.directory.layout` already covers the SWITCH — but the issue asks for the *around-the-switcher* orientation ("what changes when you flip").
- SC-4 (discovery) = `discovery-ranking` catalog entry, status `live` — deep-links exist. Discovery tabs (Recent / Hot / Promoted) — `do_discovery` module. Grep it later; likely no per-tab tooltip.
- Both: append HelpText copy under `showcase_help.directory.*` and `showcase_help.discovery.*` for the *around-the-switcher* orientation. Consumer: the tour page can inline them under each catalog entry (append-only, no framework change).

### 6. Two-axis (visibility × join policy)
- `visibility.*` keys post-#121 are already two-axis-consistent ("Every group stays readable"). Copy consistency audit: PASS. **No key edits.** Optional: append a `showcase_help.two_axis` summary key rendered near the `membership-models` catalog entry.

## Reuse & Analogous-Feature map (extend by default)

| Concern | Existing analogue | Extend / New | Rationale |
|---------|-------------------|-------------|-----------|
| Copy store | `\Drupal\do_chrome\HelpText::all()` | **Extend** (append `showcase_help.*` block) | The append-only HelpText contract is explicit in `HelpText.php` docblock. |
| ⓘ trigger markup (span + `do-chrome-info` + tabindex/role/aria-label/data-do-tooltip) | `GroupTypeContentHelp::infoTrigger()` + `PersonaSwitcher::build()` (both use identical pattern) | **Reuse pattern verbatim** in `DoShowcaseHooks::personaBanner()` | Third instance of the same 3-line snippet — pin to the pattern, do not create a helper (both existing uses are inline; adding a helper is a parallel path for one more caller). |
| Tour page catalog rendering | `ShowcaseController::page()` — iterates `$catalog->entries()` and renders per-entry blocks | **Extend** the per-entry block to render an optional `showcase_help.<entry_id>` `data-do-tooltip` span when the key exists in HelpText | Small addition inside the existing loop; no new controller, no new template. |
| E2E test | `tests/e2e/showcase.spec.ts` (existing SC-F1 spec) + `persona-switcher.spec.ts` | **New spec `showcase-help.spec.ts`** (issue's own "Owns" clause requires it) | Fresh spec keeps concerns disjoint; asserts persona banner ⓘ + tour-page around-the-switcher ⓘ + map-orientation copy. |
| PHPUnit copy test | `do_chrome/tests/src/Unit/HelpTextTest.php` | **Extend** — add cases for the new `showcase_help.*` keys | Same append-only pattern; keeps the "every key present + non-empty" invariant one test. |
| Persona banner render | `DoShowcaseHooks::personaBanner()` — the `<aside>` is hand-assembled via `Markup::create` | **Extend** — append a `<span data-do-tooltip=...>ⓘ</span>` **inside** the pre-rendered children | Existing helpers (`renderInIsolation()` on a `$children` render array) already produce string HTML; append one more child before rendering. Reuses the SAME `<aside>` wrapper. |

**No new object created.** Every artifact extends an existing one; the only "new" file is the test spec (issue-mandated).

## Forward-compat check
- **#126 / #127** (page-help + card tooltips) use `page.*` and per-element keys — **no namespace collision** with `showcase_help.*`.
- #121 owns `visibility.*` — I do NOT touch those keys.
- #120 owns `persona.*` — I do NOT touch those keys.
- New namespace: **`showcase_help.*`** (matches issue's own scope framing).

## Failure-mode notes
- `HelpText::get()` returns `''` for unknown keys. Rendering an empty `data-do-tooltip=""` = an empty tippy popover on hover. **Guard**: only render the tour-page ⓘ span when `HelpText::get('showcase_help.<id>') !== ''`.
- `<aside>` in `personaBanner` is built by `Markup::create` around a pre-rendered children string — the new ⓘ MUST live in the `$children` render array before `renderInIsolation()`, otherwise the raw `<span>` in a hand-assembled string gets double-escaped or breaks the safe-string chain.

## Test surfaces (RED targets)
1. `HelpTextTest` (or a new `ShowcaseHelpTextTest`): assert each new `showcase_help.*` key returns non-empty copy.
2. `PersonaBannerTest` (existing, extend): assert the banner `<aside>` contains a `data-do-tooltip` node with the persona-appropriate copy (or a single generic "around-the-switcher" key).
3. `ShowcaseController` Kernel/Functional (existing `ShowcaseControllerTest` if present, otherwise Unit-only): assert catalog entries that have a matching `showcase_help.<id>` key render a ⓘ trigger; entries that don't do NOT.
4. `tests/e2e/showcase-help.spec.ts` (NEW): (a) persona banner ⓘ visible + tooltip content non-empty when persona active; (b) tour page shows around-the-switcher ⓘ on at least the discovery-ranking + directory-presentation + map entries; (c) map orientation copy asserted.
