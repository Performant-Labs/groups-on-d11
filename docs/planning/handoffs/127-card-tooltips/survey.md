# Survey — #127 SD-2 Card ⓘ tooltips

## Story scope (from issue #127)
Card- and element-level tooltips on directory cards + stream cards. **Theme-owned markup only** in Wave 1 — no `.theme` / `.libraries.yml` edits (that's #122's turf), no new routes, no schema/field changes.

## Files this story owns (disjoint)
- `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig` — directory-card fields on `/all-groups`
- `web/themes/custom/groups_chrome/templates/content/node--stream-card.html.twig` — stream-card row
- `docs/groups/modules/do_chrome/src/HelpText.php` — APPEND-ONLY new keys
- `tests/e2e/element-tooltips.spec.ts` — NEW Playwright spec

**Verified against #126 (in-flight, page-tooltips):** #126 owns `docs/groups/modules/do_chrome/src/Hook/PageHelp.php` (NEW) + `page.*` keys in HelpText. File sets are **disjoint**. Namespace split: `page.*` = #126, `card.*` = #127. Confirmed by reading `~/Projects/_worktrees/groups-page-tooltips/docs/planning/handoffs/126-page-tooltips/brief.md`.

## Reuse & Analogous-Feature map (DEFAULT: extend, not new)

### Tooltip trigger DOM (the ⓘ element)
- **Existing pattern:** `GroupTypeContentHelp::infoTrigger()` (do_chrome, #89) and #126's brief's decision — each B-story ships its own trivial 8-line infoTrigger method with a story-specific extra class. No shared helper. Convention: don't refactor to a shared helper mid-wave.
- **Decision:** Follow the established pattern — inline the ⓘ span directly in the twig templates (Twig, not PHP hook) since we're in the theme layer, not a module. Attribute set (same as #89/#122/#126):
  ```twig
  <span class="do-chrome-info gc-card-info" tabindex="0" role="note"
        aria-label="{{ tooltip_copy }}" data-do-tooltip="{{ tooltip_copy }}">ⓘ</span>
  ```

### Library attachment
- **Existing:** `DoChromeHooks::pageAttachments()` attaches `do_chrome/tooltips` globally on **every** page (verified in `DoChromeHooks.php:46`). No `{{ attach_library() }}` needed in twig.
- **Confirms issue's own note:** "attach any needed library from twig … do NOT touch `groups_chrome.theme` or `groups_chrome.libraries.yml`" — the answer is *no attach needed*; global attach already covers us.

### Copy reuse (HelpText keys)
The issue explicitly says: "Reuse existing visibility/type copy where possible (the #88/#89 keys); add ~8 new keys (appended) only where no copy exists."

**Existing keys we REUSE (no new copy):**
- `visibility.open` / `visibility.moderated` / `visibility.invite_only` — attach to the visibility badge on directory cards, keyed off `gc_directory.visibility` value (already available in preprocess `groups_chrome.theme:779`).

**Existing keys NOT reusable at card level:**
- `group_type.field` — enumerates all 5 terms in ONE string (365 chars). Wrong shape for a per-badge tooltip that names one type. **Add per-type keys** so each badge names its own type honestly.
- `content_type.field` — same problem for stream-card type badge. **Add per-content-type keys.**

### CSS
- Existing `.do-chrome-info` (in `docs/groups/modules/do_chrome/css/do_chrome.css`) styles the ⓘ. If in-card placement needs tweaks, add a scoped `.gc-card-info` rule in `web/themes/custom/groups_chrome/css/directory.css` and `stream.css` (existing theme CSS files, not gitignored). **Prefer zero CSS if the baseline ⓘ inherits reasonably.**

## What's actually in the templates today (what we can wire)

### Directory card (`views-view-fields--all-groups.html.twig`)
Available in `gc_directory`:
- `type_label` (badge text) — WIRE ⓘ
- `visibility_label` + `visibility_variant` (badge) — WIRE ⓘ (reuse `visibility.*` keys)
- `member_count` (stat) — WIRE ⓘ
- `last_activity` — already has native `title=` tooltip; SKIP (no double-tooltip per AC)
- **NO language chip** in current preprocess/twig (issue mentions but data absent) — OMIT (AC guardrail: "No layout changes, no new fields")

### Stream card (`node--stream-card.html.twig`)
Available in `gc_stream`:
- byline (`gc-stream-card__byline`: author + "in" + group badge) — WIRE ⓘ **on the byline row** ("who posted, in which group")
- `type_label` (content type badge, e.g. Forum/Post/Event) — WIRE ⓘ
- `comment_count` (footer link) — WIRE ⓘ (adjacent, not inside the link's aria-label)
- `created` timestamp — already has native `title=`; SKIP
- **NO pinned badge / event-date chip** in current stream-card template (issue mentions, data absent) — OMIT (same guardrail; #92 handles pin in a separate view)

## Proposed new keys (append to HelpText.php in a `card.*` namespace)

Directory-card:
1. `card.directory.type` — "What kind of group this is — e.g., Geographical (local user group), Working group (module/initiative), Distribution (Drupal distro), Event planning, or Archive (read-only)."
2. `card.directory.members` — "How many people have joined this group."

(Visibility ⓘ reuses `visibility.open|moderated|invite_only` — 3 existing keys, keyed off `gc_directory.visibility`.)

Stream-card:
3. `card.stream.byline` — "Who posted this and which group it appears in. Click the person to see their profile; click a group to visit it."
4. `card.stream.type` — "The kind of post — Forum (threaded discussion), Documentation (durable reference), Event (something at a set time), Post (quick update), or Page (standalone info)."
5. `card.stream.comments` — "How many replies this post has. Click to open the post and read the discussion."

**Total: 5 new keys** (issue estimated ~8; we're under because we reused 3 visibility keys and honestly omitted keys for data that doesn't exist yet — language chip, pinned badge, event-date chip).

## Forward-compat check (does this design constrain later stories?)
- Later W2 stories (pin/event-date chip data model) will add fields to the stream-card. When they do, they can APPEND `card.stream.pinned` / `card.stream.event_date` keys under the same `card.*` namespace. Our namespacing decision is forward-compatible.
- SC-2 copy-edit stories may want to edit these values. Append-only contract makes edits safe.

## Testing plan (T authors)
- **E2E** (`tests/e2e/element-tooltips.spec.ts`): anonymous visitor on `/all-groups` — assert one directory-card carries `data-do-tooltip` on type/visibility/members. Then a page with a stream card (`/stream` or a group's stream tab) — assert stream-card byline + type + comments carry `data-do-tooltip`. Assert tabindex=0 + role=note + non-empty aria-label. Assert tippy tooltip becomes visible on hover of one element.
- **No Kernel needed** — no PHP logic added. HelpText is data. Twig-only wiring. (Existing HelpText tests already cover the class shape.)

## A11y
- `tabindex="0"` → keyboard focusable
- `role="note"` + non-empty `aria-label` → accessible name
- Contrast: baseline `.do-chrome-info` styling (established by #122 at 5.36:1 — AA passes)
- No double-tooltip: audit each element and confirm no page-level ⓘ (#126) fires on the same DOM node. Card-scoped triggers are inside `.gc-card`, page-level is after H1 — spatially disjoint.

## Risk / scope guardrails re-checked
- No layout changes (adding inline `<span>` after existing elements — negligible)
- No new routes, no new fields
- No `.theme` / `.libraries.yml` edits (#122's turf)
- No `docs/groups/modules/do_chrome/src/Hook/*` additions (twig-only, do_chrome unchanged except HelpText append)
- No CSS unless baseline is visually broken (defer to F's self-check)

## Open items for A
1. Is inline twig `<span data-do-tooltip>` acceptable, or does A prefer a Twig macro / include for the trigger? (Recommend: inline, matches theme convention — the theme has no macro layer.)
2. Any concern about hardcoding tooltip copy access in twig via a globally exposed HelpText? Currently HelpText is called from PHP hooks and preprocess, not twig. **Decision needed:** either (a) surface the copy via `preprocess_views_view_fields__all_groups` + `preprocess_node__stream_card` into new `$variables['gc_directory']['tooltip_type']` etc., or (b) expose HelpText as a Twig function via a `TwigExtension`. **Recommend (a)** — matches existing preprocess pattern, zero new PHP class, extends preprocess arrays that already exist.
