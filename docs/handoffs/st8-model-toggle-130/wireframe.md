# Wireframe — st8-model-toggle-130 (ST-8: Content view / Activity view switcher on /stream)

Low-fidelity, structure-only. Mode (a) — generated. This wireframe covers ONLY the delta this
story introduces: mounting the SC-F1 `VariantSwitcher` as the `activity_stream:page_1` view's
header, the new `stream.model` instance's options/copy, the wrapper attribute contract, and the
`/showcase` catalog entry flip. It does **not** re-design the switcher control itself — that
low-fi device (radiogroup structure, `●` selection glyph, roving tabindex, ⓘ tooltip trigger,
"(soon)" suffix, no-JS `?variant=` fallback, focus-ring treatment) is already specified in
`docs/handoffs/0119-variant-framework/wireframe.md` Surface 1 and proven again in
`docs/handoffs/0124-directory-toggle/wireframe.md` Surface 1 — reused verbatim here, with the
options list changed to **`Content view (soon)` / `Activity view`** (two options, not three) per
this issue's locked scope. It does NOT depict the Content view's actual rendering (out of scope,
#129) or `/my-feed` (route does not exist in main; noted as a future mount below).

---

## Surface 1 — Switcher mounted as `/stream` (`activity_stream:page_1`) view header

### State: default (no `?variant=` in URL — Activity view selected, the only available option)

```
================================================================
 Stream
================================================================
 Viewing: ┌──────────────────────┬───────────────────────┐  ⓘ
          │ Content view (soon)  │  ● Activity view      │
          └──────────────────────┴───────────────────────┘

 ────────────────────────────────────────────────────────────
   ( existing activity_stream:page_1 rows render here,
     BYTE-IDENTICAL to today — message rows: comments, flags,
     pins, memberships, posts — no change from this story )
 ────────────────────────────────────────────────────────────
 [ « ]  1  2  3  [ » ]
================================================================
```

- The switcher sits above the row list, rendered into the view's `#header` region by
  `ModelToggleHooks::preprocessViewsView()` (mirrors #124's `DoShowcaseHooks::preprocessViewsView()`
  placement exactly) — NOT exported into `views.view.activity_stream.yml`.
- Options, in order, exactly as the brief locks: **Content view** (`available: FALSE`, renders
  "Content view (soon)", `aria-disabled="true"`, `tabindex="-1"`, no `●` glyph) / **Activity view**
  (`available: TRUE`, default-selected, `●` glyph, `tabindex="0"`).
- Instance id: `stream.model`. Wrapper `data-do-showcase-instance="stream.model"` (per
  `VariantSwitcher::build()`'s existing attribute contract — unchanged).
- "Viewing:" label precedes the control (`do-showcase-variant-switcher.html.twig`, unchanged).
- ⓘ trigger renders `HelpText::get('showcase.switcher.stream.model')` — **NEW copy, this story's
  proposal** (see "Tooltip copy proposal" below). One trigger per widget wrapper (house pattern),
  not one per option.
- Row list below the switcher is completely unaffected — same fields, same pager, same empty-region
  copy as `activity_stream:page_1` renders today (#116, unchanged pass-through).

### State: `?variant=content` fallback (Content requested, but unavailable)

```
 GET /stream?variant=content

 Viewing: ┌──────────────────────┬───────────────────────┐  ⓘ
          │ Content view (soon)  │  ● Activity view      │
          └──────────────────────┴───────────────────────┘

 ────────────────────────────────────────────────────────────
   ( same activity_stream:page_1 rows — Content view does not
     exist yet, so the page renders IDENTICALLY to the default
     state above, not a blank/broken content-only view )
 ────────────────────────────────────────────────────────────
```

- `VariantSwitcher::resolveSelection()`'s existing first-available-fallback rule applies: `content`
  names an unavailable option, so `activity` (the only available option) is selected instead —
  same graceful-fallback contract #119/#124 already guarantee, no new logic in this story.
- Wrapper carries `data-do-stream-model="activity"` (see Surface 3) — same as the bare-URL default.
  **No visual difference from the default state** — this is the point: the fallback is silent and
  correct, not a distinct-looking degraded state.
- AC-3 is satisfied by this being indistinguishable from default, not by any special "we ignored
  your request" messaging — consistent with how #124 handles `?variant=map`.

### State: `?variant=activity` deep link (arriving from `/showcase`)

```
 GET /stream?variant=activity

 Viewing: ┌──────────────────────┬───────────────────────┐  ⓘ
          │ Content view (soon)  │  ● Activity view      │
          └──────────────────────┴───────────────────────┘
 ────────────────────────────────────────────────────────────
   ( same rows as default — Activity view was already the
     resolved default, so this deep link changes nothing
     visually; it exists so a /showcase link can be explicit
     about which variant it promises, and so the URL is
     shareable/bookmarkable without relying on the default )
 ────────────────────────────────────────────────────────────
```

- Wrapper carries `data-do-stream-model="activity"`, resolved server-side by
  `ModelToggleHooks::viewsPreRender()` BEFORE the view renders — no reload flash, no JS-only fixup
  (AC-4). Same no-JS-fallback contract as #124's Surface 1 "Fallback behavior" section.
- **Documented explicitly because it looks like a no-op**: with only one available option, every
  reachable URL (`/stream`, `?variant=activity`, `?variant=content`) renders the same page. That is
  correct today and will diverge the moment #129 ships a real Content view and flips its
  `available` flag to `TRUE` — this story's job is to prove the FRAMEWORK plumbing (cache context,
  wrapper attribute, deep-link resolution) is correct now, so #129 is a pure `available: TRUE` flip
  plus the actual Content view markup, not a hook rewrite.

---

## Surface 2 — Wrapper attribute contract

### Contract (mirrors #124 Surface 3 exactly, new attribute name)

```
<div class="view-content" data-do-stream-model="activity">   <-- DEFAULT and every state today
  ...existing activity_stream:page_1 rows, unchanged...
</div>
```

- The attribute is applied to the view's `.views-element-container` wrapper — **the same mirror
  selector #124 proved in live DOM**, not a new selector (per survey.md's "DO NOT invent a
  different selector" guardrail).
- `data-do-stream-model="activity"` is the ONLY value possible in this PR (Content is unavailable),
  so `do_streams/css/model-toggle.css`'s `[data-do-stream-model="content"] { ... }` selector rules
  are a **no-op today** — they exist so #129 has a CSS scoping seam ready, per the brief's own
  framing ("CSS-only visibility scoping ... no-op for now").
- JS mirror wiring is the existing generic `do_showcase.switcher.js` `select()` handler, reading
  `data-do-showcase-mirror-attribute="data-do-stream-model"` +
  `data-do-showcase-mirror-selector=".views-element-container"` off the switcher wrapper — **no new
  JS file**, same as #124.

---

## Surface 3 — Keyboard behavior (documented, not new code)

```
 Tab (from page content, entering the radiogroup):
   -> lands on Activity view (tabindex="0" — the selected, available option)
   -> Content view is tabindex="-1", NOT reached by Tab

 Arrow-Right / Arrow-Left (focus already inside the radiogroup):
   -> moves focus BETWEEN Content view and Activity view (both reachable by
      arrow keys, matching SC-F1's existing behavior: unavailable options ARE
      arrow-reachable so a keyboard user can discover them and read their
      "(soon)" label / tooltip, they are just excluded from the page's Tab
      sequence and cannot be ACTIVATED)
   -> pressing Enter/Space while focus is on Content view is a no-op
      (aria-disabled="true" — the click/keydown handler in
      do_showcase.switcher.js already guards on `available`, unchanged)
```

- **Decision made here**: Arrow keys do NOT skip the disabled Content option — they move onto it,
  same as #124/SC-F1's existing proven behavior with `Map (soon)`. This is a direct precedent
  reuse, not a new call, but is spelled out here per the brief's explicit ask to document it for
  this instance.

---

## Surface 4 — Focus ring (reused, not redesigned)

```
 ╔══════════════════════╗
 ║  ● Activity view      ║   <-- focused + selected: double-border low-fi
 ╚══════════════════════╝        stand-in for the visible focus outline
                                  (SC-F1 CSS token, unchanged — WCAG 2.2 AA
                                  2.4.11 focus-not-obscured / 2.4.7 focus-visible)
```

- Same focus-ring treatment as `docs/handoffs/0119-variant-framework/wireframe.md` Surface 1
  "State: focus" — reused verbatim, no new CSS.

---

## Surface 5 — `/showcase` catalog entry (`stream-model`, flips `coming` → `live`)

### State: catalog list, before this story (current main — for contrast only)

```
 Stream model                                          [ coming ]
 Compares a single combined activity stream vs. per-content-type
 streams — the decision: one feed to scan vs. filtered feeds.
```

### State: catalog list, after this story

```
 Stream model                                            [ live ]
 Compares a node-content model vs. an activity-log model for
 /stream — the decision: a lean feed of raw posts vs. a richer
 feed that also surfaces comments, flags, pins, and membership
 events as their own rows.                            -> /stream
```

- `status` flips to `'live'`, `route` flips to `'view.activity_stream.page_1'` (the canonical Views
  auto-generated route id for `/stream`, same pattern #124 used for `view.all_groups.page_1`).
- **Decision-sentence copy — THIS STORY'S PROPOSAL** (replaces the current, factually-wrong
  sentence, which describes a "combined vs. per-content-type streams" comparison that this story
  does not build):

  > "Compares a node-content model vs. an activity-log model for /stream — the decision: a lean
  > feed of raw posts vs. a richer feed that also surfaces comments, flags, pins, and membership
  > events as their own rows."

  Rationale: matches the issue's own framing ("streams as queries over content vs. streams over an
  activity log") and the tooltip copy below, without overclaiming that Content view exists yet (the
  entry is `live` because the SWITCHER + Activity view are live, same as #124's own `live` entries
  for framework-plus-one-side-only features — Map view landed `coming` for exactly this reason
  under #119, and the same asymmetry applies here: one option ships, one is "(soon)").
- **Note for A/O**: `do_chrome/src/HelpText.php` already has a DIFFERENT, adjacent key —
  `showcase_help.stream-model` — used for the `/showcase` tour page's own per-entry orientation ⓘ
  (`ShowcaseController::page()`'s catalog-entry tooltip, #132/SD-5 namespace). Its current copy
  ("One combined activity stream vs. separate streams per content type...") has the SAME staleness
  bug as the decision_sentence above and should likely be updated in step with it, even though the
  brief's Owns section only names the NEW `showcase.switcher.stream.model` key. Flagging as an open
  question below rather than silently expanding scope.

---

## Tooltip copy proposal — `HelpText::get('showcase.switcher.stream.model')`

**NEW key, appended to `do_chrome/src/HelpText.php`** (append-only, per contract):

```
'showcase.switcher.stream.model' => 'Activity view aggregates everything happening in this
scope — posts, comments, flags, pins, and membership changes — as one chronological feed of
message rows. Content view (coming soon) will show just the posts themselves: a leaner model
with no aggregated activity noise.',
```

- **(a) what content is included in each view**: names Activity view's row types explicitly
  (posts, comments, flags, pins, membership changes) — matches `activity_stream:page_1`'s actual
  `stream_card` rendering (#116) and the issue's own phrasing ("social rows, aggregation, comment
  activity"). Names Content view's shape (bare posts, no aggregation) even though it doesn't exist
  yet — truthful because it is qualified "(coming soon)", same pattern as every other unavailable
  option's tooltip reference in this codebase (e.g. `showcase_help.map`'s honest description of a
  feature that IS live, adapted here for one that ISN'T — see open question below on tense).
- **(b) that the content-only model is leaner**: "a leaner model with no aggregated activity
  noise" — directly answers the issue's "content-only is the leaner model" requirement.
- **(c) that Content view is coming soon**: "(coming soon)" inline, consistent with the option
  label's own "(soon)" suffix so the tooltip and the visible label never contradict each other.
- Length: ~350 chars, in line with the longer existing entries (`permissions.panel.footnote`,
  `group_type.field`) — this codebase's tooltip copy is not length-capped except where a native
  `title=` attribute forces brevity (persona.* only).

---

## A11y contract (confirmed, no new keyboard/focus code)

| Requirement | Source | Confirmed unchanged |
|---|---|---|
| `role="radiogroup"` + roving tabindex | SC-F1 `VariantSwitcher::build()` | Reused verbatim — 2 options instead of 3, same mechanism. |
| Non-color selection cue (`●` glyph) | SC-F1 `VariantSwitcher::build()` | Reused verbatim. |
| `aria-checked` on each option | SC-F1 `VariantSwitcher::build()` | Reused verbatim. |
| `aria-disabled="true"` + `tabindex="-1"` + "(soon)" suffix on Content | SC-F1 `VariantSwitcher::build()` | Reused verbatim — this story supplies `available: FALSE` for `content`, the class does the rest. |
| Visible focus outline | SC-F1 CSS token | Reused verbatim (Surface 4 above). |
| ⓘ tooltip trigger, keyboard-reachable (`tabindex="0"`, `role="note"`) | SC-F1 `VariantSwitcher::build()` | Reused verbatim. |
| Literal-text labels, no color-only meaning | `VariantSwitcher::build()` display_label | Reused verbatim — "Content view (soon)" / "Activity view" are plain text; any decorative color is reinforcement only. |
| Keyboard: Tab enters at selected/available option; Arrow-L/R reaches disabled option too | SC-F1 `do_showcase.switcher.js`, confirmed behavior (Surface 3 above) | Reused verbatim, explicitly documented for this instance per brief's ask. |

No new keyboard code, no new focus-ring CSS, no new ARIA pattern. The only genuinely new surface
is the two-option label/tooltip content and the `data-do-stream-model` wrapper attribute wiring.

---

## Explicitly NOT depicted (out of scope)

- **The Content view's actual rendering** (#129) — this wireframe shows only the switcher and its
  states; if a reader clicks/lands on "Content view" they see nothing different because it does not
  exist yet (Content is `available: FALSE` precisely so no dead/broken render is ever reachable).
- **`/my-feed`** — `views.view.my_feed.yml` does not exist in main (blocked on open PR #110). Not
  mounted, not wireframed. **Future mount**: per survey.md's forward-compat design, the hook's
  view/display target set is `{'activity_stream' => 'page_1'}` today; when #110 merges, adding
  `'my_feed' => 'page_1'` to that same set is intended to be a one-line edit, reusing the identical
  `stream.model` instance id, options, and tooltip copy (no new wireframe needed at that time
  unless `/my-feed`'s layout differs from `/stream`'s in a way that affects header placement).

---

## Open questions for approval

1. **`showcase_help.stream-model` copy (adjacent, pre-existing key)** — should this story also
   correct the `/showcase` tour page's own orientation-tooltip copy for the `stream-model` entry
   (currently the same stale "combined stream vs. per-content-type streams" framing), even though
   the brief's Owns section names only the new `showcase.switcher.stream.model` key? Recommend YES
   (both keys describe the same comparison and both are provably wrong under the new framing), but
   flagging since it's a HelpText edit not explicitly listed in the brief's scope — confirm with
   O/A before F touches it.
2. **Decision-sentence tense for an unavailable Content view** — the proposed decision_sentence
   describes what Content view WILL show ("a leaner feed...") before it exists. Confirm this
   framing (present-tense comparison, `live` status because the switcher+Activity half is real) is
   acceptable, vs. an alternative that explicitly says "(Content view coming soon)" inline in the
   sentence itself, mirroring the tooltip's own "(coming soon)" qualifier more explicitly. Either is
   truthful; D's default is the shorter form shown above since `[ live ]` badge + tooltip already
   carry the "(soon)" qualifier for Content specifically.
3. **Icon/glyph check**: this wireframe uses only Unicode (`●`, `⌐` not used, `ⓘ`, box-drawing
   characters `┌┬┐└┴┘═║╔╗╚╝`) — no hand-authored SVG path data anywhere in this artifact. Rendered
   and visually reviewed as plain text/markdown (no SVG/HTML canvas to open-and-look-at for this
   ASCII-based wireframe) — flagging per the svg-glyphs.md render-and-look step that this is an
   ASCII wireframe, not an SVG, so there is no glyph geometry to malform in the first place.

---

## Existing components/patterns reused

- SC-F1 `VariantSwitcher::build()` + `do-showcase-variant-switcher.html.twig` +
  `do_showcase.switcher.js` — verbatim, new `stream.model` instance id, two options instead of
  three.
- #124's two-hook shape (`viewsPreRender()` + `preprocessViewsView()` + shared id/display guard +
  shared `requestedVariant()` helper) — same structure, new sibling hook class per survey.md's
  "add sibling hooks" recommendation (not a refactor of `DoShowcaseHooks`).
- `.views-element-container` as the JS mirror selector — proven in #124 by live-DOM inspection, not
  reinvented.
- `/showcase` catalog's non-color `[ live ]` / `[ coming ]` bracketed-text badge convention —
  unchanged, this story only flips which bracket `stream-model` shows.
- The existing `activity_stream:page_1` row rendering (#116, `stream_card` view mode), pager, and
  empty-region copy — completely unchanged pass-through in every state above.
