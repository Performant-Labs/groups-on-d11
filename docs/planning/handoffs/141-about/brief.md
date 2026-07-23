# Brief — #141 MC-2 About section

**Story:** [#141 MC-2: About section](https://github.com/Performant-Labs/groups-on-d11/issues/141) — Epic #137 MVP conformance
**Branch:** `141-about`  **Worktree:** `~/Projects/_worktrees/groups-about`
**Base:** `origin/main` @ 49fe585 (post-#140 MC-1 Links)
**Review rigor:** none (per issue "Review rigor: none"; overnight POC mode also skips brief-gate o4-mini)
**Survey:** `docs/planning/handoffs/141-about/survey.md`
**Reuse map:** survey §"Reuse & Analogous-Feature map" — verdict: **NEW field `field_group_about`**
**A plan review:** `docs/planning/handoffs/141-about/handoff-A-plan.md` — PASS with 3 T-warns encoded below

## Objective

Add an "About" section on the group Full display: a formatted-text body field
(`field_group_about`) rendered below the existing description/image and above Links & Resources.
Seed real About prose on a few groups. Empty state renders nothing.

## Design decision recorded

- **NEW field, not reuse of `field_group_description`.** Description is a required one-liner
  (~10–15 words in seed reality); the spec calls for "richer content beyond the one-line
  description." Overloading description would break its listing/teaser role. New field cost is
  minimal (one YAML pair + display append + seed setter) and lives in `do_group_extras` (no
  module sprawl). See survey §"Decision". **A confirmed this call — Finding #1 PASS.**

## Scope (Owns — disjoint files)

**New files:**
- `docs/groups/config/field.storage.group.field_group_about.yml` — text_long storage, cardinality 1
- `docs/groups/config/field.field.group.community_group.field_group_about.yml` — instance, label "About", not required, translatable
- `docs/groups/modules/do_group_extras/css/group-about.css` — subtheme CSS for the About section
- `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupAboutFieldTest.php` — mirrors GroupLinksFieldTest
- `tests/e2e/group-about.spec.ts` — anonymous visitor sees About prose; empty state renders no heading

**Edited files (append-only in the reserved slot):**
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml` — replace `# (weight 10 reserved for #141 About)` placeholder with a `# --- Section: About (weight 10) ---` marker + `field_group_about` component (`label: above`, formatter `text_default`, weight 10); add dep alphabetically.
- `docs/groups/config/core.entity_form_display.group.community_group.default.yml` — append `field_group_about` widget `text_textarea` (formatted); add dep alphabetically.
- `docs/groups/modules/do_group_extras/do_group_extras.libraries.yml` — add `group-about:` library entry mirroring `group-links`.
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — extend `preprocessGroup` to conditionally attach `do_group_extras/group-about`. **F code-shape hint (A warn #6):** nest the About attach INSIDE the same `bundle === 'community_group' && view_mode === 'default'` outer conditional the links attach already uses — two inner field-existence guards, one outer bundle/view-mode check.
- `docs/groups/scripts/step_700_demo_data.php` — append-only block: set About prose on 2–3 seeded groups with idempotent empty-guard.

**Do NOT edit `do_group_extras.info.yml`** — core `text` is universally available; the field.storage YAML declares its own `module: text` dependency. (A note in handoff-A-plan.md.)

**Must NOT touch:**
- `web/modules/custom/**` or `config/sync/**` (gitignored build artifacts)
- Any file `do_streams`, `do_showcase`, `do_chrome`, `do_group_membership`, or `do_tests` owns
- `field_group_description`, `field_group_image`, `field_group_links`, `field_group_visibility`, `field_group_type`
- The `hidden:` block in the view display
- Section-marker comments for OTHER sections in the display files
- `do_chrome/src/HelpText.php` (About is inline content, not a tooltip — A finding #8)

## Acceptance criteria (each must be backed by a test T authors)

- [ ] **AC-1** `field_group_about` storage exists as `text_long`, cardinality 1, translatable — **kernel**
- [ ] **AC-2** Instanced on `community_group` bundle with label "About", not required, translatable — **kernel**
- [ ] **AC-3** Group Full display exposes `field_group_about` at weight 10 with `label: above` and formatter `text_default` — **kernel**
- [ ] **AC-4** Group form display exposes `field_group_about` with widget `text_textarea` (non-hidden) — **kernel**
- [ ] **AC-5** Rendering a group with formatted About body (e.g. `<p><strong>bold</strong></p>` in `basic_html`) produces the sanitized rich HTML inside the rendered field wrapper — **kernel, asserted against observable HTML per A warn #5**
- [ ] **AC-6** Empty state: a group with NO About body set renders NEITHER "About" text label NOR bare wrapper — **kernel**, cover BOTH shapes per A warn #4: (a) field never set, (b) explicit `[value=>'', format=>'basic_html']` tuple
- [ ] **AC-7** E2E: anonymous visitor on a seeded group's page sees an "About" heading + prose — **playwright**
- [ ] **AC-8** WCAG 2.2 AA: heading structure (`<h2>` About renders after description, before Links); readable contrast; no empty landmarks — **U walkthrough (axe)**
- [ ] **AC-9** Existing kernel + functional + E2E suites remain green — **T green pass, CI**
- [ ] **AC-10** Source-only commits (no `web/modules/custom/` or `config/sync/`) — **O verifies pre-PR**

## Test-first outline (T authors RED before F) — with A warns encoded

**Kernel `GroupAboutFieldTest.php`** (mirrors `GroupLinksFieldTest`):

1. `testStorageExists` — text_long, cardinality 1, translatable
2. `testInstanceExists` — label "About", not required, translatable
3. `testFullDisplayShowsField` — component on `group.community_group.default` view display, `type=text_default`, `label=above`, `weight=10`
4. `testFormDisplayShowsField` — widget `text_textarea`
5. `testRendersFormattedBody` (A warn #5) — set `[value=>'<p><strong>Hello</strong> world.</p>', format=>'basic_html']`; render; assert `<strong>Hello</strong>` present in HTML. **T must materialize a minimal `basic_html` FilterFormat in `setUp()`** (allowed_html covering `<p>`, `<strong>`) — kernel does not install site FilterFormat config. Alternative: use `plain_text` and adjust the fixture to a plain-text body + `<p>` wrapper assertion; document the choice in the test docblock.
6. `testEmptyStateRendersNothing` (A warn #4) — cover **two** empty shapes:
   - (a) group created without setting the field → render → no "About" heading, no `<h2>`/`<label>` wrapper
   - (b) group created with `field_group_about = [value=>'', format=>'basic_html']` → render → same

**(Optional) library-attach kernel assertion (A warn #6)** — group with prose set → `#attached['library']` contains `do_group_extras/group-about`; group with no About → does NOT. E2E covers observable behavior; skip if it's not cheap.

**Kernel `$modules` array** (mirror `GroupLinksFieldTest`): `group`, `gnode`, `options`, `node`, `field`, `text`, `user`, `filter`, `do_group_extras`. (Add `filter` explicitly — needed for the FilterFormat creation in setUp.)

**E2E `group-about.spec.ts`** (mirrors `group-links.spec.ts`):
1. Anonymous visits a seeded group with About prose; asserts About heading + a distinctive phrase from the seeded body
2. Anonymous visits a seeded group with NO About; asserts no About heading

## Coordination with #140 (already merged)

- Display config file is shared: replace the `# (weight 10 reserved for #141 About)` placeholder on line 50 with a `# --- Section: About (weight 10) ---` marker + `field_group_about` component.
- Do NOT reorder other components. Do NOT edit the `hidden:` block.
- **Dependencies block (view display):** insert `field.field.group.community_group.field_group_about` as the **first entry** in `dependencies.config` — it sorts alphabetically above `field_group_description` (currently the first entry). Same rule applies to the form display.

## Input documents

- SPEC: `gh issue view 141 --repo Performant-Labs/groups-on-d11`
- Wave handoff: `docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md`
- Project context: `docs/workflow/PROJECT_CONTEXT.md`
- Direct plan analogue: `docs/planning/handoffs/140-links/handoff-A-plan.md`
- Direct test analogue: `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php`
- A plan review this story: `docs/planning/handoffs/141-about/handoff-A-plan.md`

## Handoff locations

`docs/planning/handoffs/141-about/`:
- `survey.md`, `brief.md`, `decisions.md`, `handoff-A-plan.md` (done)
- `handoff-T-red.md`, `handoff-F.md`, `handoff-T-green.md`, `handoff-U.md`, `handoff-S.md` (pending)

## Namespacing

- Branch: `141-about`
- Worktree: `~/Projects/_worktrees/groups-about`
- Containers (if F/T/U spin any): `gm141-*` — NEVER `docker rm` a sibling's container
