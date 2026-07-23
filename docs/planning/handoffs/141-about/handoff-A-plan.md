# Handoff-A: Phase 3 — #141 MC-2 About section  (up-front plan review)

**Date:** 2026-07-23
**Branch:** 141-about   **Worktree:** ~/Projects/_worktrees/groups-about
**Brief reviewed:** docs/planning/handoffs/141-about/brief.md
**Reuse map:** docs/planning/handoffs/141-about/survey.md §"Reuse & Analogous-Feature map"
**Wireframe:** N/A (D skipped per decisions.md; convention-bound render, mirrors #140)
**Base:** origin/main @ 49fe585 (post #140 MC-1 Links merge)
**Verdict:** PASS (with 3 warns to close during T RED authoring + 1 brief-copyedit ask for O)

## Summary

The plan is architecturally sound on every dimension I audit. The **NEW-field decision** for
`field_group_about` is the right call — reusing `field_group_description` would either force the
one-line teaser to become long-form (breaking its listing/teaser role) or leave About semantically
identical to description with no visible difference; both violate the spec's "richer content
**beyond** the one-line description." The **module placement** (extending `do_group_extras`
rather than a new module) matches the reuse rule and the module's charter — its
`preprocessGroup` hook already runs on the community_group Full display for the identical #140
Links attach, so the About library attach lands on the exact seam it belongs on. The **display
coordination scheme** correctly consumes the weight-10 slot #140 explicitly reserved (line 50 of
the shipped display yml) and follows #140's marker convention. The **empty-state strategy**
(field's own `label: above` = "About") applies **identically** to `text_long` + `text_default`
as it did to `link` — core's field render pipeline suppresses the whole field element (including
the label) on `FieldItemList::isEmpty()` regardless of field type. The **test-tier split**
(kernel for shape/render/empty, E2E for seeded-visible, no functional, no unit) mirrors #140
and matches the surface. The **anti-duplication scan** is clean: no other module (`do_streams`,
`do_showcase`, `do_chrome`, `do_group_membership`, `do_tests`) owns any part of the group Full
display surface. **Forward-compat** is clean: none of #142–#145 conflicts with weight 10 or the
About surface.

Three items need pinning down during T's RED pass. One small copyedit in the brief. None
BLOCK-worthy.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | pass | NEW `field_group_about` (text_long) vs reuse of `field_group_description` | reuse / abstraction level | Correct call. Description's storage is text_long too, but its **role** (required one-liner teaser, `label: hidden` at weight 0) is load-bearing for listings/teasers — seed reality confirms one-liners. Overloading it either breaks that role or makes About indistinguishable. New field cost is minimal (one YAML pair + one display append + one form-display append + one seed setter), stays in `do_group_extras` (no module sprawl), and mirrors #140's exact idiom. This is a spec'd extension of the field set, not a parallel path. | — |
| 2 | pass | Extend `do_group_extras` (module) | reuse / layering | Right home. Module already owns the community_group Full display surface (see `#[Hook('preprocess_group')]` on DoGroupExtrasHooks.php:81 — already attaches `group-links` library conditionally). Adding an `about` sibling library attach on the same seam is a same-seam extension, not a parallel path. Module already depends on `drupal:group`, `drupal:link`, `drupal:taxonomy`; core `text` is universally available and `field.storage.*.yml` declares its own `module: text` dependency — no info.yml change strictly required (see warn #4 below). | — |
| 3 | pass | Weight 10 slot + marker convention + dependency-block alphabetization | pattern consistency / merge-safety | Slot is genuinely reserved: shipped `core.entity_view_display.group.community_group.default.yml:50` has `# (weight 10 reserved for #141 About)` placeholder ready to be replaced with a `# --- Section: About (weight 10) ---` marker. Header comment already documents the source-tree convention. Insertion ordering (0=description, 1=visibility, 2=image, 10=about, 20=links) → About renders below image, above Links. That is the description → visibility badge → hero image → About body → Links CTA flow O called out in decisions.md — defensible for the POC. | — |
| 4 | warn | Empty-state mechanism (`label: above` "About" suppresses whole wrapper on `isEmpty()`) — copied from #140 warn #6 | pattern consistency | The mechanism transfers **identically** to `text_long` + `text_default` — core's `EntityViewDisplay::buildMultiple()` calls `FieldItemList::isEmpty()` before rendering and skips the element (label + wrapper) entirely on empty. It is field-type agnostic. HOWEVER `text_long` has a subtle wrinkle `link` does not: a `text_long` item is empty iff its `value` column is empty string / null; a stored `[value => '', format => 'basic_html']` item (which the text_textarea widget can produce on empty submit if not carefully handled) will typically be filtered to nothing on save by the widget, but it is worth pinning with a test. | Have T's kernel `testEmptyStateRendersNothing` cover **two** empty shapes: (a) field never set (no delta), (b) field explicitly set to `[value => '', format => 'basic_html']`. Both must render no "About" heading. If (b) fails, that is F's problem to solve (likely no fix needed — widget presubmit strips it — but proving it is cheap). |
| 5 | warn | Formatter = `text_default`; widget = `text_textarea` | pattern consistency / hedge specificity | Both are right for the intent (formatter renders full sanitized HTML, widget is formatted textarea without summary — matches description's shape). The AC-5 assertion should be against **observable HTML** (`<strong>Hello</strong>` present after rendering a `basic_html` fixture), not against the formatter's plugin ID string, so F is free to satisfy it via `text_default` first and fall back to a preprocess if the formatter output differs from expectation. Same posture as #140 warn #5. Also: `basic_html` filter format must exist in the kernel fixture — description seed already uses it in the assembled site, but kernel tests do not install site config, so T needs to programmatically create a minimal `basic_html` FilterFormat in `setUp()` (or use `plain_text`, which is universally present, and adjust the fixture to a plain body if that is simpler). | T authors AC-5 as HTML-observable (`assertStringContainsString('<strong>Hello</strong>', $html)`). In `setUp()`, either (a) `FilterFormat::create(['format' => 'basic_html', ...])->save()` with allowed_html covering `<strong>`, or (b) simplify the fixture to `plain_text` with an expected `<p>` wrapper. Either works; document the choice in the test docblock. |
| 6 | warn | Preprocess library attach for `group-about` | pattern consistency | Plan says "mirrors the exact links-attach block" — confirmed correct pattern. The guard must be identical shape: `bundle === 'community_group'` && `view_mode === 'default'` && `hasField('field_group_about')` && `!->isEmpty()`. Do NOT collapse into a single library that covers both sections — separate libraries keep the empty-state attach discipline honest (a group with links but no About should not pay About's CSS cost, and vice versa). | T's kernel test should assert the library attach shape: (a) group with About prose set → `#attached['library']` contains `do_group_extras/group-about`; (b) group with no About → does NOT contain it. Mirror the shape #140 uses (if #140 didn't add such an attach-only kernel assertion, this is optional — E2E covers observable behavior). Sanity note: DoGroupExtrasHooks::preprocessGroup already has the `if ($group->bundle() === 'community_group' && $view_mode === 'default')` outer guard for links — the About attach should sit inside the SAME conditional block (one bundle/view-mode check, two inner field-existence guards) to avoid duplicating the check. Minor code-shape hint for F. |
| 7 | pass | Test tiers — kernel (shape/render/empty) + E2E (seeded-visible); no functional; no unit | test-first shape | Right split. Kernel covers config presence, formatter output HTML (with sanitization), and empty-state suppression cheaply against a bare group entity. E2E covers the seeded-through-install path. No functional-test tier needed — form-widget presence is a config assertion (kernel). No unit tests — no service class introduced. Matches #140. | — |
| 8 | pass | Anti-duplication scan across sibling modules | drift risk | `do_streams` operates on stream/views surface; `do_showcase` on tour ribbon; `do_chrome` on tooltip copy (`HelpText.php` is a tooltip registry, not a generic user-facing-surface registry — the "richer content" About body is inline field render, not a tooltip); `do_group_membership` on membership plumbing; `do_tests` on test-base infrastructure. None owns any part of the group Full display's field component list. No parallel path. | Confirm in F's handoff that no HelpText.php entry is added (About is not a tooltip). |
| 9 | pass | Forward-compat with #142/#143/#144/#145 | drift risk | #142 (directory Views filters) touches Views definitions, not the group Full display component list. #143 (archive-restore) touches `field_group_type` handling and restore action, not the display's `content:` block. #144 (auto-organizer) touches membership/role wiring on group create, not display. #145 (WCAG audit) READS the assembled display — About must pass axe (H2 hierarchy, contrast, no empty landmark) — which the `label: above` + non-empty guard combination already satisfies by construction. No slot conflicts. | — |
| 10 | warn (brief copyedit only) | Brief §"Coordination with #140" — dependency-block insertion sentence | naming / doc accuracy | The brief says: *"insert `field.field.group.community_group.field_group_about` alphabetically (between the `- field.field.group.community_group.field_group_about` and `- field.field.group.community_group.field_group_description` lines)"* — that self-references (`field_group_about` appears on both sides of "between"). Alphabetically, `field_group_about` sorts **before** `field_group_description`, so it becomes the **first** entry in `dependencies.config`, above `field_group_description`. Confirmed against shipped file (line 9): current first entry is `- field.field.group.community_group.field_group_description`; About goes above it. | O: patch the brief sentence to *"insert `field.field.group.community_group.field_group_about` as the first entry in `dependencies.config` — it sorts alphabetically above `field_group_description`."* Not blocking (F will figure it out from the alphabet), but the sentence as written is confusing. |

## Notes for O

Verdict is PASS. Pass warns #4, #5, #6 to T as "encode observable behavior in RED tests; do not
lock the mechanism":

- **#4:** cover empty state as BOTH "never set" and "explicitly empty value/format tuple" — cheap
  belt-and-suspenders on the text_long empty semantics.
- **#5:** assert AC-5 against HTML (`<strong>` present after rendering a `basic_html`
  fixture); T must materialize a `basic_html` FilterFormat in `setUp()` (or use `plain_text` and
  adjust the fixture) — kernel tests do not carry site FilterFormat config.
- **#6:** library-attach kernel assertion is optional (E2E covers it); if added, prove both the
  attached-on-non-empty and NOT-attached-on-empty cases. F should nest the About attach inside
  the SAME `bundle === 'community_group' && view_mode === 'default'` outer conditional the links
  attach already uses — not a separate conditional block.

**Housekeeping for T/F:**

- Kernel `$modules` array must include `group`, `gnode`, `options`, `node`, `field`, `text`,
  `user`, `do_group_extras` (mirror `GroupLinksFieldTest`). No new module needed beyond core
  `text` which is already implied via `gnode`/`node` transitively but should be listed explicitly.
- `do_group_extras.info.yml` does NOT need a new dependency line — core `text` is universally
  available, and the field.storage YAML declares `module: text` itself. Skip the info.yml edit
  unless F finds a concrete reason.
- Brief §"Coordination with #140" dependency-block sentence needs the one-line copyedit in
  finding #10 above — do this before T starts RED so T's fixture reference doesn't inherit the
  confusion.

## Patterns referenced

- `docs/groups/config/core.entity_view_display.group.community_group.default.yml` — collision
  surface (post-#140 state; weight 10 reserved on line 50; marker convention header lines 1–3)
- `docs/groups/config/field.storage.group.field_group_description.yml` — text_long storage shape
  analogue
- `docs/groups/config/field.field.group.community_group.field_group_description.yml` — text_long
  instance shape analogue (label, required, translatable, allowed_formats)
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — existing preprocess_group
  seam and the exact links-attach conditional block (lines 81–97)
- `docs/groups/modules/do_group_extras/do_group_extras.libraries.yml` — sibling library entry
  shape (`group-links:` at lines 6–9)
- `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php` — kernel test
  template (setUp fixture, display/form assertions, empty-state assertion, render helper)
- `docs/planning/handoffs/140-links/handoff-A-plan.md` — direct analogue plan review
