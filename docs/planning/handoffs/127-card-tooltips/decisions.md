# Decisions — #127 SD-2 Card ⓘ tooltips

Append-only journal. Every phase writes its own entry.

## Phase 1 — O (Orchestrator)
- **Decided:** Skip D per lean POC pipeline — the ⓘ affordance is 100% patterned on #89/#122/#126 (same DOM, same class, same JS). No wireframe adds signal here.
- **Decided:** File set = 2 theme twigs + `groups_chrome.theme` preprocess extension + HelpText append + 1 new e2e spec. Disjoint from #126 (verified by reading `~/Projects/_worktrees/groups-page-tooltips/…/brief.md`).
- **Decided:** Namespace new keys under `card.*` (subnamespaces `card.directory.*` and `card.stream.*`) — clearly disjoint from #126's `page.*` namespace.
- **Decided:** REUSE `visibility.open|moderated|invite_only` for visibility badge ⓘ (issue explicitly requires reuse where copy exists). Add 5 new keys (issue estimated ~8; we're under because we honestly omitted keys for elements whose data doesn't exist yet — language chip, pinned badge, event-date chip).
- **Assumed:** Extending `groups_chrome.theme` preprocess to pass tooltip copy into twig is acceptable — theme file is NOT gitignored, and extending an existing preprocess to add data-passthrough variables is not the same as adding logic. The issue's "twig-template-only in Wave 1" language targets `.libraries.yml` (asset-pipeline coupling with #122), not preprocess data. **Flagged for A confirmation.**
- **Assumed:** Baseline `.do-chrome-info` CSS renders acceptably inside a card without new scoped rules — defer to F's Tier-1 visual check.
- **Evidence:** Read of `HelpText.php`, both twig templates, `groups_chrome.theme` lines 700-800, `DoChromeHooks::pageAttachments()`, #126 brief, WAVE-EXECUTION-HANDOFF §4/§6/§7, PROJECT_CONTEXT.md.

## Phase 3 — A (up-front architecture review)
_(A appends here)_
