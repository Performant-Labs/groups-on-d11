# O's response to dual-review-brief.md (round 1)

All 9 BLOCK findings resolved by amending `brief.md` with a new "Precise specs" section
(§Precise specs, keyed [B-1]..[B-9]) and a "Guardrails from WARN findings" section (keyed
[W-1]..[W-4]). Point-by-point:

- **[B-1] last-activity metric** — defined as `GREATEST(changed, last_comment_timestamp-or-changed-if-none)`.
  See brief §Precise specs [B-1].
- **[B-2] ranking UI mechanism** — defined as a parameter the shell's control posts, read by
  `viewsQueryAlter()`; explicitly NOT a Views exposed-sort plugin (rejected — can't express
  hot/pinned-first joins). See [B-2].
- **[B-3] shell contract** — template name, theme hook, preprocess variable schema
  (`scope_tabs`/`ranking_control`/`results`/`empty`), and the demo view's machine name/display/
  argument now specified. See [B-3].
- **[B-4] follow_term field** — resolved by reading `config/sync/`: the correct field is
  `field_group_tags` (present on every group_node bundle), not `field_tags` (only on `article`,
  not a group bundle). See [B-4].
- **[B-5] test ownership** — restated the pipeline's standing T-owns-tests rule with the exact
  file path and naming convention; not a new rule, but now explicit in the brief so it's not
  ambiguous to a reader of the brief alone. See [B-5].
- **[B-6] GROUP BY columns** — enumerated exactly which columns are grouped (node's own selected
  columns) vs aggregated (relationship id via MIN, pin_sort/hot score via MAX), mirroring
  do_group_pin's existing fix precisely. See [B-6].
- **[B-7] test view + E2E environment** — split into (a) a Kernel-only fixture YAML (not shipped)
  matching the do_group_pin precedent, and (b) a shipped-but-inert demo view + demo route for U's
  Playwright pass, plus the concrete isolated-DDEV-instance commands O/U will run. See [B-7].
- **[B-8] "Trending" tab** — defined as Global scope (no filter) + hot ranking default; not a new
  scope plugin, a documented parameter combination. See [B-8].
- **[B-9] membership-scope join chain** — gave the exact EXISTS-subquery reference semantics (two
  `group_relationship_field_data` rows: one for the node's group_node relationship, one for the
  current user's group_membership relationship, joined on `gid`). See [B-9].

WARN items [W-1]..[W-4] folded in as non-blocking guardrails F must observe (LEFT JOIN for hot
score, scope-aware cache tags, EXISTS-preferred for scope OR-ing, pagination re-check after
GROUP BY).

NIT items accepted for T/D to apply lightly (view machine name now in the brief per NIT-1;
uninstall test already in survey.md's testing approach #6 per NIT-2; terminology aligned in the
brief — "membership scope" is the plugin id, "My Feed" is the tab label, both now used
consistently per NIT-3; NIT-4's namespace grouping (`DoStreams\Hook\*`) is already the convention
— `DoStreamsHooks.php` under `src/Hook/` per the do_group_pin precedent, so NIT-4 is already
satisfied by the existing plan).
