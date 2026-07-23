# Handoff-A: Phase 3 — #110 ST-1 My Feed (up-front plan review)

**Date:** 2026-07-23
**Branch:** 110-stream-110
**Brief reviewed:** `docs/planning/handoffs/110-stream-110/brief.md`
**Reuse map:** `docs/planning/handoffs/110-stream-110/survey.md`
**Wireframe:** `docs/planning/handoffs/110-stream-110/wireframe.html` (approved D handoff)
**Verdict:** PASS (with advisories)

## Summary

The plan extends the objects the survey's Reuse map named — the `do_streams_shell` theme hook
(new optional `empty_cta` variable), `MembershipScope` filter (used as-is), the `activity_stream`
YAML shape (copied then diffed), `step_780_nav_menu.php` (append-only 5th entry), and
`HelpText::get()` (append-only new key). The one *new* object — a custom controller wiring
`views_embed_view` → `#theme => do_streams_shell` — is the correct placement: there is no existing
"shell-wrapping" utility in the codebase to extend, and the shell theme hook's own docblock
already declares that ST-1/2/4/6 controllers are the intended callers (`Hook/DoStreamsHooks.php:23-25`,
`:378-394`, `:420-473`). Route + placement is Drupal-idiomatic (`do_streams.routing.yml`,
`src/Controller/MyFeedController.php`, `do_streams.libraries.yml`, `css/my-feed.css` all live in
the module root and are picked up wholesale by the assemble script — no exclude-list collision).

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | soft | Nav-link auth visibility relying solely on Drupal's default menu-link access filter for a `menu_link_content` entity | cross-cutting concerns (auth composition) | The brief and decisions.md name the fallback ("`hook_menu_local_tasks_alter` or block-level visibility") but do not pre-decide the assertion in T's spec. In Drupal, `menu_link_content` entities have their per-user visibility filtered by `\Drupal\Core\Menu\DefaultMenuLinkTreeManipulators::checkAccess`, which DOES call the target route's access check — so a route with `_role: authenticated` will hide the link from anonymous users **in the standard core main-menu block**. This codebase places `groups_chrome_main_menu` (custom block) as the renderer; if that block bypasses `menuLinkTreeManipulators.checkAccess`, the link would leak to anon. Recommend T's e2e asserts anon-DOM-absence (already in AC-8 / handoff-D.md) so a regression here fails loudly rather than silently. Advisory only — nothing to change in the plan; keep the fallback documented in decisions.md as F may need it. | Keep AC-8's anon-absence assertion (already planned). If it fails during T-red, F falls back to `hook_menu_links_discovered_alter` toggling `access_arguments` or a per-link `access_callback`. |
| 2 | soft | View YAML `use_ajax` | pattern consistency | `activity_stream.yml:100` has `use_ajax: true`. The survey says "remove if it complicates the shell wrap; verify." Recommend F sets `use_ajax: false` from the outset for `my_feed`: (a) the shell's `.shell-results` region is not an AJAX target and the shell CSS/JS ships no AJAX wrapper; (b) core Views AJAX rewires the pager to replace `.view-content`, which does not exist inside the shell markup — pager clicks would silently break the shell chrome. Since `activity_stream` still owns `/stream` (unchanged), that view keeps AJAX pager; `my_feed` should not. | F: set `use_ajax: false` on the `my_feed` default display. |
| 3 | soft | View `access` config | pattern consistency (belt-and-suspenders) | Plan proposes route `_role: authenticated` AND view `access: role authenticated`. This is **correct** — the two do NOT mask each other and provide defense in depth: `views_embed_view()` does invoke the view's own access plugin (returning empty when denied), and the route requirement short-circuits anon at routing (403 or login redirect per `authentication.provider` chain). Neither is redundant given the view is potentially reusable as a block in future stories (#111-#115). Anonymous behavior: Drupal core translates a denied `_role` requirement to 403; if `system.site.login` redirect is configured, it becomes a 302. AC-1's "403 or login redirect" wording accommodates both. | No change. Advisory: T should assert `response.status ∈ {403, 302}` OR follow-redirect to `/user/login`. |
| 4 | soft | Cache metadata propagation through `views_embed_view` into shell | cache correctness | `views_embed_view()` returns a render array whose `#cache` merges the view's own metadata (including the `do_streams:user_stream:<uid>` tag added by `DoStreamsHooks::viewsPostRender`). However, that hook (`Hook/DoStreamsHooks.php:287-297`) only fires for `$view->id() === DEMO_VIEW_ID` (`do_streams_demo`) — it will **not** tag `my_feed`. Two consequences: (a) `my_feed`'s render will not carry the per-user stream cache tag → future pin/unpin toggles won't invalidate the /my-feed page render. For POC-scope this is tolerable (`user.roles` + `user` cache contexts still keep per-user separation) but should be captured as a follow-up so #110-#115 don't inherit stale-cache surprises. (b) Even without the tag, the render must at minimum bubble `user` cache context up through the shell — F must call `\Drupal::service('renderer')->renderInIsolation()` or explicitly merge `#cache => ['contexts' => ['user']]` on the outer shell render array, since the shell theme hook itself declares no cache metadata (`Hook/DoStreamsHooks.php:378-394`). | F: (a) either widen `viewsPostRender`'s allowlist to include `my_feed` in this story, OR file a follow-up and add the tag in the controller for now (`$build['#cache']['tags'][] = DoStreamsHooks::userStreamCacheTag($uid)`). (b) Add `#cache => ['contexts' => ['user', 'user.roles']]` on the controller's outer shell render array. |
| 5 | soft | `empty_cta` shell extension shape | forward-compat | Adding an optional `empty_cta` render-array variable to the `do_streams_shell` theme hook is a legitimate extension — #111-#115 will all need scope-specific CTAs. Keep the default `[]` (not `NULL`) so Twig `{% if empty_cta %}` behavior is boolean-clean. Do NOT hardcode `/all-groups` in the shell preprocess or template — the CTA render array must come from the caller (controller), preserving the shell's "no hardcoded routes" contract already documented at `templates/do-streams-shell.html.twig:14-19`. | F: template renders `{{ empty_cta }}` inside the `.gc-empty` block only when non-empty; controller builds the render array (link render element or `#type => link`). |
| 6 | soft | Kernel/functional test module install order | Drupal idiom | `do_streams` depends on `do_group_pin` and `do_discovery` (`do_streams.info.yml:14-15`). A functional test creating a user in a group must enable the full chain. Existing `StreamsInstallTest` / `StreamsShellTest` under `tests/src/Kernel/` are the pattern to mirror. | T: reuse the setUp pattern from `StreamsScopeTest.php` (already tests the MembershipScope filter). |
| 7 | soft | New `do_streams.libraries.yml` and `css/my-feed.css` | pattern consistency | Module currently ships zero libraries file. Attaching CSS via `#attached['library']` from the controller is fine; alternatively `hook_page_attachments_alter` scoped to the route. Prefer the controller-attach path (locality). Confirm CSS is minimal (spec says "can be minimal") — the shell chrome CSS was inlined in the #109 wireframe but the module ships no shell CSS file yet, meaning `/my-feed` will render as unstyled shell chrome unless CSS is provided somewhere. Verify with U whether the shell chrome CSS made it into some `do_chrome`/`groups_chrome_theme` asset. If not, this story either ships shell CSS (scope creep) or U will legitimately fail on visual parity. | Advisory: F/D check whether shell CSS was actually shipped by #109 or lives only in the wireframe HTML. If not shipped, either scope-limit this story to structural CSS + accept a visual U note, or add the shell CSS here and flag as inherited-from-#109 concern in decisions.md. |
| 8 | soft | Nav-link weight `1.5` (float) | pattern consistency | `menu_link_content.weight` is an integer field in Drupal core (`schema/menu_link_content.schema.yml`). A weight of `1.5` will be silently coerced to `1` or `2` (depending on cast). Since existing weights are 0/1/2/3 and story requires ordering between Activity(1) and My Groups(2), F must re-weight existing links (e.g. Activity=1, My Feed=2, My Groups=3, Create Group=4) OR pick weight `2` and bump My Groups/Create Group by one. The brief's own "APPEND-ONLY" instruction is at tension with this — the append must include an idempotent re-weight of existing entries by their stable `description` keys (still surgical, no title/uri changes). | F: in `step_780_nav_menu.php`, either (a) shift weights of the existing three later links by +1 (keyed by stable `description`), inserting My Feed at weight 2, or (b) use integer weights 0/1/2/3/4 with My Feed=2 and existing links 2/3 renumbered. Keep re-weight strictly surgical (weight only, no other fields touched); update decisions.md. |

## Sign-off

**PASS.** The plan extends the analogous objects the survey named, respects module boundaries
(controller in `do_streams`, HelpText in `do_chrome`, nav in `docs/groups/scripts/`), and does
not duplicate any existing utility. Approach (A) — controller + `views_embed_view` + shell theme
render — is the right choice; the alternatives (view page + area handler, `hook_views_pre_render`
swap) were correctly rejected in decisions.md.

### Advisories for T
- Assert `data-testid="do-streams-shell-empty-cta"` presence + `href` in empty state (AC-6).
- Assert nav link ABSENCE from DOM for anon (not just hidden) — see Finding #1.
- AC-1 assertion should accept both 403 and 302→/user/login.
- Cover the pager case (AC-7) with a seeded/fixture user carrying ≥11 in-scope nodes.

### Advisories for F
- `use_ajax: false` on the `my_feed` display (Finding #2).
- Explicit `#cache` contexts on the shell render array (Finding #4).
- Either widen `viewsPostRender`'s view id allowlist OR merge the per-user stream tag in the
  controller (Finding #4a).
- Integer nav-link weight; re-weight existing links surgically to preserve ordering (Finding #8).
- `empty_cta` variable default `[]`, built by controller (not preprocess) — no route hardcoded in
  the shell (Finding #5).
- Confirm shell CSS shipping path with D/U before spending time on `my-feed.css` chrome
  (Finding #7).

## Patterns referenced

- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` — shell theme hook, preprocess,
  `viewsPostRender` cache-tag pattern, `viewsData()` filter registration.
- `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig` — shell contract.
- `docs/groups/modules/do_streams/src/Plugin/views/filter/MembershipScope.php` — filter shape
  matching the plan's `filters:` entry.
- `docs/groups/config/views.view.activity_stream.yml` — YAML template being copied.
- `docs/groups/scripts/step_780_nav_menu.php` — nav-link seeding pattern (integer weight).
- `scripts/ci/assemble-config.sh` — confirmed no exclude-list collision for new module files.
