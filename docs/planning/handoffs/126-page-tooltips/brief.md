# Brief — #126 SD-1 Page-level ⓘ tooltips

## Objective
Add page-level "what am I looking at" ⓘ tooltips after the H1 on 5 covered pages, and pre-register 5 W2 page-help entries as inert. Every entry keyed by route id.

## Scope (from issue #126)

**Covered now (must render live):**
| Route id | Path | HelpText key |
|---|---|---|
| `view.activity_stream.page_1` | `/stream` | `page.stream` |
| `view.all_groups.page_1` | `/all-groups` | `page.all_groups` |
| `view.group_content_stream.page_1` | `group/{group}/stream` | `page.group.stream` |
| `view.group_events.page_1` | `group/{group}/events` | `page.group.events` |
| `view.group_members.page_1` | `group/{group}/members` | `page.group.members` |

**Pre-registered W2 (map entry present, inert until route exists):**
- `page.my_feed` — `/my-feed`
- `page.following` — `/following`
- `page.trending` — `/trending`
- `page.my_feed_events` — `/my-feed/events`
- `page.profile_stream` — `user/{user}/stream` (profile stream)

Entries whose route does not resolve at request time render nothing (no ⓘ, no error).

## Design (skip-D justification)
- Identical ⓘ affordance to #89 `GroupTypeContentHelp::infoTrigger()` — same DOM: `<span class="do-chrome-info page-help-info" tabindex="0" role="note" aria-label="{copy}" data-do-tooltip="{copy}">ⓘ</span>`
- Injection: `hook_preprocess_page` (route-driven), adds a `#page_help` variable, printed by a small template override or by injecting into the existing `page.html.twig` title suffix region. **Simpler, no template touch:** we build a render array and attach it as an addition to `$variables['page']['content']` prepended, OR — cleanest — implement `hook_preprocess_page_title` and append the ⓘ to `$variables['title_suffix']` (a standard Drupal render slot printed adjacent to the H1). Using `page_title` preprocess keeps zero template edits and lands the ⓘ literally after the H1 span in `page-title.html.twig` core output.

**Decision:** use `hook_preprocess_page_title` — hooks into `page_title.html.twig`'s `title_suffix` slot, which core already prints. No template override. Route match via injected `RouteMatchInterface`.

## Reuse & Analogous-Feature map
- **Analogous feature (extend pattern, not copy):** `docs/groups/modules/do_chrome/src/Hook/GroupTypeContentHelp.php` — provides `infoTrigger()` render-array shape. **We DO NOT copy this method** — extract it to a shared `TooltipTrigger` helper OR (lower-risk, matches existing pattern) duplicate the tiny private method with a `page-help-info` extra class. Given the extant B-stories (#88/#89/#90) each ship their own trivial `infoTrigger`, we follow that established convention — a shared helper would be a cross-cutting refactor outside this story's scope.
- **HelpText:** append-only (per file docblock line 38+ contract). Add `page.*` keys section after the persona.* block.
- **Library:** already attached globally by `DoChromeHooks::pageAttachments()`. No new library.
- **CSS:** existing `.do-chrome-info` class in `do_chrome.css` already styles the ⓘ. Add `.page-help-info` only if visual differentiation needed (probably none for POC).

## Files
- **NEW:** `docs/groups/modules/do_chrome/src/Hook/PageHelp.php` (sole owner, all logic here)
- **APPEND-ONLY:** `docs/groups/modules/do_chrome/src/HelpText.php` (new `page.*` section at end)
- **NEW:** `tests/e2e/page-help.spec.ts` (Playwright: anon on 2 pages + Elena on 1)
- **NEW (T-authored):** kernel or unit test for the route→key map + inert W2 keys

## Acceptance criteria
1. Anonymous visitor on `/stream`, `/all-groups`, group Stream tab, group Events tab, group Members tab: ⓘ renders after the H1, opens a tippy tooltip with the correct copy.
2. All 5 W2 keys are present in the map; navigating to a nonexistent W2 route (e.g. `/my-feed`) returns 404 — the point is the map is complete so W2 stories don't need to edit `do_chrome`.
3. Keyboard: Tab to ⓘ → focus visible → Enter/Space opens tooltip (tippy default behaviour, inherited).
4. `aria-label` non-empty, ⓘ contrast ≥ AA (baseline established by #122: 5.36:1).
5. Existing suite green. New Playwright spec asserts ⓘ present + tooltip visible on 2 anon pages + 1 authed.

## Copy (author here, plain visitor-facing, no jargon)
- `page.stream` → "The site-wide activity stream: recent posts, replies, and events from every public group. This is what a signed-out visitor sees to get a sense of the community."
- `page.all_groups` → "Every community group on the site, listed together. Filter by name to find one, or browse to see what topics have working groups. Any signed-in visitor can join an Open group instantly."
- `page.group.stream` → "This group's activity: posts, replies, and events from members, newest first. This is the default landing view for the group."
- `page.group.events` → "Upcoming and past events organised by this group. Members can add events from the Add content menu."
- `page.group.members` → "Everyone who has joined this group. Organizers manage the roster; joining rules depend on the group's visibility (Open, Moderated, or Invite Only)."
- `page.my_feed` → "A personalised feed of activity from the groups you belong to." *(inert until W2)*
- `page.following` → "Posts and threads you've chosen to follow." *(inert until W2)*
- `page.trending` → "Posts drawing the most engagement across the site right now." *(inert until W2)*
- `page.my_feed_events` → "Upcoming events from the groups you belong to." *(inert until W2)*
- `page.profile_stream` → "This person's public activity across all their groups." *(inert until W2)*

## Review rigor
none (per issue)

## Model discipline
D skipped (patterned). T, F, U spawn with `model: sonnet`. A, S inherit Opus.
