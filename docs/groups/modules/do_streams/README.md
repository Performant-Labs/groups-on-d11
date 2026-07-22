# DO Streams

Foundation module for the Streams epic (#108). Provides two Views scope
plugins, ranking wiring for an existing Views query, and a shared,
parameter-driven stream shell (scope tabs + Recent/Hot ranking control).
Ships **inert** — no user-facing routes of its own beyond a fixture-grade
demo view; Phase-1 stories (#110-#115) attach their own routes/displays to
the contract below.

## Engine contract

Every do_streams-exercising view is described by:

```
(scope, scope_arg?, sources[], ranking, presentation, page_size)
```

- **scope** — which rows are eligible. Two Views FILTER plugins ship here:
  - `do_streams_membership_scope` — nodes with a `group_relationship` row
    (`plugin_id LIKE 'group_node:%'`) in any group the CURRENT viewing user
    belongs to (a `group_membership` relationship). Per-user, not
    per-(single)-group.
  - `do_streams_following_scope` — nodes matching ANY of `follow_content`
    (the node itself flagged), `follow_user` (the node's author flagged),
    `follow_term` (a term on the node's `field_group_tags` flagged), OR'd
    and deduped.
  - **Global** scope is simply the ABSENCE of either filter (no new plugin —
    per [B-8]).
- **scope_arg** — reserved for a future by-single-group or by-single-author
  scope (not built here; #114 owns a by-author scope plugin as its own
  story). Not used by either shipped plugin.
- **sources[]** — this scaffold's plugins operate on `node_field_data` only
  (the `node` source). A non-node source (#112's events+RSVPs) is future
  work; the Views plugin extension point is open-by-construction for it.
- **ranking** — a Views CONTEXTUAL ARGUMENT (`$view->args[0]`, per [A-W3]),
  read by `DoStreamsHooks::viewsQueryAlter()` and branched into the query's
  ORDER BY:
  - `recent` — `node_field_data.created` DESC (the view's own registered
    sort; no query alteration).
  - `last_activity` — `GREATEST(changed, COALESCE(NULLIF(
    comment_entity_statistics.last_comment_timestamp, 0), changed))` DESC.
  - `hot` — `COALESCE(do_discovery_hot_score.score, 0)` DESC via a LEFT
    JOIN (do_streams only consumes do_discovery's score; it never
    recomputes it).
  - `pinned` — the `pin_in_group` flag (the SAME flag do_group_pin reads)
    LEFT JOINed and made the PRIMARY sort key (front of `$query->orderby`,
    mirroring do_group_pin's #52 fix), with the created-DESC sort as the
    secondary ordering.
  - **Trending** (a shell tab, not a ranking value) = Global scope + ranking
    forced/defaulted to `hot` — a shell-level tab-to-parameter mapping only,
    per [A-W4]; no third scope plugin.
- **presentation** — the shared shell (`#theme => do_streams_shell`), built
  on the existing `stream_card` node view mode. See "Shell contract" below.
- **page_size** — standard Views pager options; unchanged by this module.

## Dedupe / cache-tag pattern (mirrors do_group_pin, per [A-W1]/[A-W2])

Any ranking branch that LEFT JOINs a relationship-shaped table
(`comment_entity_statistics`, `do_discovery_hot_score`, `flagging`) is
collapsed back to one row per node on the COMPILED query
(`query_views_do_streams_demo_alter`), by discovering join-side SELECT
columns GENERICALLY by table-name membership (not a hardcoded alias
string, per [A-W1] — do_streams' join set differs per active ranking
branch, unlike do_group_pin's single fixed relationship).

Cache tags are per-VIEWING-USER (`do_streams:user_stream:<uid>`, per
[A-W2]) — NOT `do_group_pin:...` (which is per-group). Membership/following
scope is inherently per-user, so its invalidation scope must be too.

## Shell contract ([B-3])

Theme hook `do_streams_shell`
(`templates/do-streams-shell.html.twig`), preprocessed by
`DoStreamsHooks::preprocessDoStreamsShell()`:

- `scope_tabs` — array of `{id, label, url_or_param, active}` for
  `global` / `my_feed` / `following` / `trending`. `url_or_param` is a plain
  query-PARAMETER-mapping string derived from the tab's own `id` (e.g.
  `'?scope=following'`), NEVER a hardcoded route path — downstream stories
  (#110-#115) wire their own routes and read `?scope=` off the query string.
- `ranking_control` — array of `{id, label, active}` for `recent` / `hot`.
  Never carries a `disabled` key, even under the `trending` scope (D-gate
  resolution 1 — ranking is orthogonal to scope).
- `results` — a pre-rendered results render array; the shell does not know
  how the results were queried.
- `empty` — bool, true when `results` is empty.
- `empty_copy` — one of 4 DISTINCT, scope-truthful empty-state strings
  (D-gate resolution 2). Global's copy never contains a follow-oriented CTA.

No hardcoded routes: every tab/pill is a plain, non-linking element (the
template surfaces each tab's `url_or_param` as a `data-url-or-param`
attribute, not an `<a href>`). Wiring real navigation off `url_or_param` is
each Phase-1 story's own job, attaching to these same preprocess variables.

## Shipped demo view

`config/install/views.view.do_streams_demo.yml` ships the SAME view
definition the Kernel test fixture proves against
(`tests/fixtures/config/views.view.do_streams_demo.yml`), so it renders for
real in a live site while remaining inert (no menu link; ST-1/2/4/6 attach
their OWN routes/displays, not this one).
