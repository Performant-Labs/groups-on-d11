# groups-on-d11

Drupal 11 demonstration of drupal.org-style Groups: activity streams,
group membership + roles, moderation, and a `/showcase` tour. Runs live
at <https://groups.performantlabs.com>.

## Repo layout

- `web/` — Drupal 11 docroot.
- `docs/groups/modules/do_*` — custom Drupal modules for the demo
  (`do_activity`, `do_groups`, `do_streams`, `do_showcase`,
  `do_group_membership`, `do_ops`, …).
- `recipes/` — Drupal recipes used by the installer.
- `deploy/` — container entrypoint + Coolify-side glue.
- `docs/` — planning notes, playbook, ops docs.
- `tests/` — Playwright + PHPUnit suites.

For contributor onboarding, module-level READMEs, and the coding
pipeline see `docs/playbook/`.

## For operators

Everything needed to run, observe, and recover the live demo lives in
[`docs/ops/`](docs/ops/):

- [`deploy.md`](docs/ops/deploy.md) — build + deploy topology; how a
  `main` merge reaches Coolify; three redeploy paths (UI, API, SSH).
- [`rollback.md`](docs/ops/rollback.md) — image rollback by digest; DB
  restore; nuclear-option reseed.
- [`health-checks.md`](docs/ops/health-checks.md) — signals to confirm
  the site is healthy, layered from external HTTP down to container
  logs.
- [`secrets.md`](docs/ops/secrets.md) — inventory + rotation
  procedures for every secret in the deploy path.
- [`sla.md`](docs/ops/sla.md) — response-time + uptime targets;
  alert-worthy thresholds.
- [`on-call.md`](docs/ops/on-call.md) — runbook for the four incident
  patterns observed during the POC (Uranus disk full, runner fleet
  offline, main goes red post-merge, secret expired).
- [`triage.md`](docs/ops/triage.md) — how new bug reports flow through
  GitHub Issues; labels, priorities, ownership, SLAs.

### Health endpoint

The site exposes a public JSON health probe at
[`/healthz`](https://groups.performantlabs.com/healthz):

```json
{
  "status": "up",
  "checks": { "db": "up", "cache": "up", "modules_checksum": "..." },
  "timestamp": "..."
}
```

Details, alert thresholds, and the Grafana dashboard that consumes it
are in [`docs/ops/sla.md`](docs/ops/sla.md).

## Contributing

Development pipeline, coding standards, and story workflow are documented
under [`docs/playbook/`](docs/playbook/). Bug reports and feature
requests: open a GitHub issue — the triage flow is in
[`docs/ops/triage.md`](docs/ops/triage.md).
