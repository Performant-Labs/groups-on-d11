# Groups-on-D11 — Demo SLA definitions

REL-4 (#213). Part of the Release Engineering epic (#216).

Defines what "the demo is working" means measurably, so on-call has an
unambiguous escalation trigger and Grafana panels have thresholds to color
against. These are **demo-grade** targets — deliberately non-aggressive.

## 1. Response-time targets

Measured at Uranus' reverse proxy (Coolify → Caddy), forwarded to Grafana via
alloy + Loki + Mimir.

| Surface                         | p95 TTFB | p99 TTFB |
| ------------------------------- | -------- | -------- |
| Anonymous pages (`/`, `/groups`)| < 500 ms | < 1.5 s  |
| Authenticated pages (`/user/*`) | < 1 s    | < 3 s    |
| `/healthz` probe                | < 100 ms | < 300 ms |

Sustained breach of the p95 target for 15 minutes over any 1-hour window is
alert-worthy.

## 2. Uptime target

- **99% availability** measured monthly against `/healthz` returning HTTP 200.
- Planned maintenance windows (documented in `#ops` at least 24 h ahead) are
  excluded from the numerator.
- This is a demo, not a production tenant: 99% (≈ 7.2 h/month allowed downtime)
  is intentionally lenient. Tightening happens when the platform graduates.

## 3. Alert-worthy conditions

An alert fires when **any** of the following holds:

| Condition                                           | Threshold                              | Notify        |
| --------------------------------------------------- | -------------------------------------- | ------------- |
| Fleet down (no healthy container)                   | > 10 minutes                           | `#ops` + page |
| `/healthz` returns non-200                          | > 5 consecutive minutes                | `#ops`        |
| `/healthz` `status` field is `"down"`               | any single probe                       | `#ops`        |
| `/healthz` `modules_checksum` drifts unexpectedly   | any change without a deploy in ±15 min | `#ops`        |
| Main branch red post-merge                          | any CI failure on `main`               | `#ops`        |
| p95 TTFB breach (anon pages)                        | > 500 ms for 15 min in a 1-h window    | `#ops`        |
| Error-log rate (Loki `level=error` count)           | > 20 events/minute for 5 minutes       | `#ops`        |

Advisory (no page):

- `/healthz` `status` field is `"degraded"` — cache backend transient.
- CI-runner busy count on Uranus = max for > 30 minutes (queue starving).

## 4. Where the signals come from

- **Endpoint** — `/healthz` (this repo, `do_ops` module, #213). Public JSON:
  `{ status, checks: { db, cache, modules_checksum }, timestamp }`.
- **Application logs** — Drupal's `syslog` core module writes to stdout in
  production; Coolify captures the container's stdout; alloy on Uranus tails
  and forwards to Loki. See `docs/ops/settings.production.snippet.php` for the
  exact settings.php snippet.
- **HTTP-response timing** — Caddy access logs → Loki.
- **CI-runner state** — GitHub Actions self-hosted runner metrics on Uranus.

## 5. Grafana dashboards on Uranus

The observability stack (grafana, loki, mimir, tempo, alloy) is already
deployed on Uranus. Import `docs/ops/grafana-dashboard.json` (this repo) as
the starting board. Panels:

1. **`/healthz` probe success rate** — blackbox exporter → Mimir; single-stat
   green/amber/red at 100 / 99 / <99% over 1 h.
2. **Page response times** — Caddy access log durations (p50 / p95 / p99),
   split by anon vs authenticated by URL prefix.
3. **CI-runner busy count** — self-hosted runner exporter, gauge.
4. **Error-log rate** — Loki count-over-time of `level=error` in the last 1 h.
5. **Modules checksum drift** — Loki + `/healthz` blackbox JSON extractor;
   annotates when `modules_checksum` changes between probes.

Dashboard URL (once imported): `https://grafana.uranus.performantlabs.com/d/groups-on-d11-demo`.

Alert rules live in Grafana Alerting alongside the dashboard (they reference
the same queries — do not duplicate them in Alertmanager).

## 6. History

- 2026-07-24 — #213 (REL-4) — initial SLA doc + `/healthz` + dashboard JSON.
