# Migration guides

This directory holds stand-alone migration playbooks aimed at **other Drupal sites**
that want to follow the paths we've already walked. They are extracted from the internal
migration notes under `docs/groups/`, but they are self-contained — an adopter should
be able to work from just this directory.

## Contents

- **[group-3x-to-4x-playbook.md](./group-3x-to-4x-playbook.md)** — Migrating a Drupal
  10/11 site from `drupal/group:^3` to `drupal/group:^4`. Preflight, composer changes,
  the `content_plugin` → `relation_type` config rename, the Flexible Permissions →
  Access Policy API port, test-fixture updates, uninstall order, and verification.
  Cross-references PRs in this repo where each step was applied as a worked example.

## Related internal docs (not for adopters)

- `docs/groups/GROUP_4X_MIGRATION.md` — this project's own change-log-style reference
  for the 3.x → 4.x deltas. Denser and repo-specific; use the playbook above instead if
  you are migrating your own site.
- `docs/groups/RUNBOOK.md` — the internal phase-gated runbook we followed for this
  project's own migration.
