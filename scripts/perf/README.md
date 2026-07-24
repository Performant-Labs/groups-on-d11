# Performance measurement scripts

## baseline.mjs — PERF-1 baseline profiling (issue #242)

Anonymous zero-load profiling of core pages using Playwright + PerformanceObserver.
Produces JSON on stdout, human-readable progress on stderr.

```bash
BASE_URL=https://groups.performantlabs.com \
RUNS_COLD=3 RUNS_WARM=5 \
node scripts/perf/baseline.mjs > baseline.json 2> baseline.log
```

Environment:

| Var        | Default                              | Meaning                                  |
|------------|--------------------------------------|------------------------------------------|
| `BASE_URL` | `https://groups.performantlabs.com`  | Site to profile.                          |
| `RUNS_COLD`| `3`                                  | Fresh-context runs per page (no cache).  |
| `RUNS_WARM`| `5`                                  | Cached runs per page after one prime.    |

Latest report: [`docs/planning/perf/baseline-2026-07-24.md`](../../docs/planning/perf/baseline-2026-07-24.md).
