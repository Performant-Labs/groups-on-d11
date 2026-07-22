## Brief Review (Round 2)

### BLOCK finding responses

[B-1] ACCEPTED — The brief now explicitly defines the “last-activity” expression using GREATEST with a COALESCE fallback (`GREATEST(node.changed, COALESCE(NULLIF(comment_entity_statistics.last_comment_timestamp, 0), node.changed))`), satisfying the requirement.

[B-2] ACCEPTED — The ranking UI mechanism is clearly specified as a shell-supplied parameter (not a Views exposed sort) that `viewsQueryAlter()` reads to branch into the four order-by modes, as required.

[B-3] ACCEPTED — The shell contract is fully spelled out: theme hook (`do_streams_shell`), template path, preprocess variables (`scope_tabs`, `ranking_control`, `results`, `empty`), and the demo view’s machine name (`do_streams_demo`), display ID (`default`), and `uid` contextual argument.

[B-4] ACCEPTED — The brief corrects the follow_term join to use `field_group_tags` (not `field_tags`) on group_node bundles, matching the codebase.

[B-5] ACCEPTED — Test ownership is restated unambiguously: kernel tests under `docs/groups/modules/do_streams/tests/src/Kernel/StreamsEngineTest.php` are T’s responsibility; F writes none.

[B-6] ACCEPTED — GROUP BY and aggregation rules are enumerated exactly, mirroring `do_group_pin`’s pattern (plain node columns in GROUP BY; MIN/MAX on joined/derived columns).

[B-7] ACCEPTED — The test/demo view plan is fully detailed: a kernel-only fixture YAML for StreamsEngineTest, plus a shipped inert demo view (with its own route) for Playwright, and the DDEV setup/teardown commands.

[B-8] ACCEPTED — The “Trending” tab is defined as Global scope (no filter) with the hot ranking defaulted, requiring no new plugin.

[B-9] ACCEPTED — The membership-scope plugin’s semantics are specified via an EXISTS subquery joining two `group_relationship_field_data` tables (one for the node’s group_node relationship, one for the current user’s group_membership), exactly as required.

### Verdict

PASS — all BLOCK findings have been addressed; the implementation may proceed.
