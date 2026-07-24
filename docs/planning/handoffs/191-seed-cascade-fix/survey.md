# Survey — #191

## Files read

- `docs/groups/scripts/step_640.php` (main, current state, 46 lines)
- `docs/groups/scripts/step_795_activity_feed_e2e_fixture.php` (main, 205 lines)
- `docs/planning/handoffs/139-multilang-rtl/park-note-r2.md` (diagnosis)
- Three fix commits on `origin/139-multilang-rtl`:
  - `8d535ab` — layer 1: install language module
  - `1ba0eab` — layer 2: backfill und/zxx locked entities
  - `838ab6f` — layer 3: ContentLanguageSettings entity API + install
                content_translation
- `.github/workflows/test.yml:480-620` — CI seed sequence, do_activity_feed
  workaround pattern, step_795 invocation

## Key findings

1. **step_640 is NOT wired into main's CI seed sequence.** Only #139's branch
   invokes it (line 535). Consequence: the cascade only surfaces on branches
   that wire step_640 in. Story #191 should both fix the script AND wire it
   into main's CI, so future regressions surface immediately.
2. **step_795 has no intrinsic bug** — its failure was strictly downstream of
   step_640's malformed `language.content_settings` config. If step_640 is
   truly fixed, step_795 needs no change. But a defensive preflight (e.g.
   verifying ContentLanguageSettings for referenced bundles is well-formed)
   would make future cascades fail fast with a clear message rather than a
   fatal in `__construct`.
3. **#139's three fix commits are the reference implementation.** They compile,
   they land the diff shape called out in park-note-r2, and one of them
   (`838ab6f`) never got CI validated only because the branch conflicted mid-
   rework — not because the fix was wrong. Extend/port; don't rewrite.
4. **`do_activity_feed` workaround (`test.yml:488-509`)** uses `pmu` + `en`
   for a similar config-import gap. Different mechanism (this story does
   entity-level backfill, not module reinstall), but the mental model — "if
   the module was already listed in ACTIVE core.extension.yml, its
   config/install/ never lands, so defensively install what you need" — is
   the same. Reference this in step_640 comments; do not duplicate the
   workaround.

## Reuse & analogous-feature map (extend-vs-new)

| Object | Recommendation | Justification |
|---|---|---|
| `docs/groups/scripts/step_640.php` | **Extend** | Direct target of the fix cascade. |
| `docs/groups/scripts/step_795_...php` | **Extend if needed** | Likely no change; add defensive preflight only if F sees a concrete need. |
| `.github/workflows/test.yml` seed sequence | **Extend** | Insert step_640 invocation between step_620c and step_700. |
| Test file (Kernel test) | **NEW** | No existing kernel test covers step_640; T decides exact path (probably in `do_group_language` module). Justification: no analogous test to extend. |
| Shared helper (e.g. `_do_ensure_locked_languages`) | **DO NOT create** | Single call site; inlining in step_640 is clearer than a helper. |

## Forward-compat check

- **Downstream consumer:** #139 (MC-4 multilingual close-out). #139 will
  re-rebase onto main after this ships and drop its own three fix commits
  in favor of what main now has. Nothing else consumes step_640.
- **Contract:** step_640 must produce a fully-formed `language.content_settings`
  for each of forum/documentation/event/post/page. #139's own #191-independent
  work (RTL group indicator, Arabic seed) relies only on languages existing +
  content translation being enabled per bundle — the entity API fix satisfies
  both.
- **Conflict check:** none identified.

## Open assumptions

- No 4th layer will surface. If one does — issue #191 advisory-hold policy
  triggers.
- Kernel test is the right test level (vs Functional/E2E). T decides.
