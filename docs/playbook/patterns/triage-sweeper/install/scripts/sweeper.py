#!/usr/bin/env python3
"""
triage-sweeper — evidence-cited issue triage for GitHub, powered by Claude Code headless.

For each eligible open issue it:
  1. runs `claude -p` (read-only tools) to review the issue against current `main`,
  2. gets back a strict JSON verdict with real path:line + commit citations,
  3. applies the sweeper:* labels (verdict + blast + risk),
  4. upserts a single evidence comment carrying idempotency markers.

It NEVER closes issues. Closing stays a human decision — the bot's job is to make that
decision cheap and auditable. (Auto-close is intentionally not implemented; add it behind
a maintainer-only flag if you ever want it, but the whole point of the human gate is that a
wrong close is far more expensive than a wrong label.)

Idempotency: each comment embeds `<!-- triage-sweeper:swept-sha=<HEAD> item=<n> -->`.
An issue already swept at the current HEAD (and not updated since) is skipped.

Requires: gh CLI (authenticated), claude CLI (authenticated or ANTHROPIC_API_KEY), python3.
Everything is driven by env vars so the same script works in any repo — see README.md.
"""
from __future__ import annotations
import json, os, re, subprocess, sys, textwrap, time
from pathlib import Path

REPO        = os.environ["GH_REPO"]                       # e.g. Performant-Labs/language-buddy
MAX_ISSUES  = int(os.environ.get("SWEEPER_MAX_ISSUES", "20"))
DRY_RUN     = os.environ.get("SWEEPER_DRY_RUN", "") not in ("", "0", "false")
MODEL       = os.environ.get("SWEEPER_MODEL", "claude-opus-4-8")
LABEL_QUERY = os.environ.get("SWEEPER_FILTER", "is:open is:issue")  # extra gh search filter
PROMPT_PATH = Path(__file__).with_name("prompt.md")
# Vendored MIT "hands" skills, installed at <repo>/.github/skills/github (see PROVENANCE.md).
SKILLS_DIR  = Path(__file__).resolve().parent.parent / "skills" / "github"
MARKER_RE   = re.compile(r"<!-- triage-sweeper:swept-sha=([0-9a-f]+) item=(\d+) -->")

VERDICT_LABEL = {
    "implemented-on-main": "sweeper:implemented-on-main",
    "cannot-reproduce":    "sweeper:cannot-reproduce",
    "not-planned":         "sweeper:not-planned",
    "incoherent":          "sweeper:incoherent",
    "actionable":          "sweeper:actionable",
}
ALL_VERDICT_LABELS = set(VERDICT_LABEL.values())
BLAST_LABELS = {b: f"sweeper:blast-{b}" for b in
                ("massive", "broad", "moderate", "contained")}
RISK_LABELS  = {r: f"sweeper:risk-{r}" for r in
                ("security-boundary", "compatibility", "data-loss", "automation")}
ALL_MANAGED = ALL_VERDICT_LABELS | set(BLAST_LABELS.values()) | set(RISK_LABELS.values())


def sh(args: list[str], **kw) -> str:
    return subprocess.run(args, check=True, capture_output=True, text=True, **kw).stdout


def gh_json(args: list[str]):
    return json.loads(sh(["gh", "api", *args]) or "null")


def head_sha() -> str:
    return sh(["git", "rev-parse", "HEAD"]).strip()


def eligible_issues() -> list[dict]:
    """Open issues, oldest-updated first, excluding PRs and sweeper:skip."""
    q = f"repo:{REPO} {LABEL_QUERY} -label:sweeper:skip sort:updated-asc"
    out = sh(["gh", "search", "issues", "-q", q, "--json",
              "number,title,updatedAt", "--limit", str(MAX_ISSUES * 3)])
    return json.loads(out)


def already_swept(number: int, sha: str, updated_at: str) -> bool:
    """True if a sweeper comment at this HEAD exists and predates no newer issue edit."""
    comments = gh_json([f"repos/{REPO}/issues/{number}/comments", "--paginate"])
    for c in comments or []:
        m = MARKER_RE.search(c.get("body", ""))
        if m and m.group(1) == sha and c["created_at"] >= updated_at:
            return True
    return False


def existing_sweeper_comment(number: int):
    comments = gh_json([f"repos/{REPO}/issues/{number}/comments", "--paginate"])
    for c in comments or []:
        if MARKER_RE.search(c.get("body", "")):
            return c
    return None


def run_claude(issue: dict) -> dict | None:
    """Run headless Claude with read-only tools; parse the strict JSON verdict."""
    prompt = PROMPT_PATH.read_text()
    for k, v in {
        "{{ISSUE_NUMBER}}": str(issue["number"]),
        "{{ISSUE_TITLE}}":  issue["title"],
        "{{ISSUE_AUTHOR}}": issue.get("author", "?"),
        "{{ISSUE_LABELS}}": ", ".join(issue.get("labels", [])) or "(none)",
        "{{ISSUE_BODY}}":   issue.get("body", "")[:12000],
        "{{HEAD_SHA}}":     issue["_sha"],
        "{{SKILLS_DIR}}":   str(SKILLS_DIR),
    }.items():
        prompt = prompt.replace(k, v)

    proc = subprocess.run(
        ["claude", "-p", prompt,
         "--model", MODEL,
         "--output-format", "json",
         "--allowedTools", "Read,Grep,Glob,Bash(git log:*),Bash(git show:*),"
                           "Bash(git diff:*),Bash(rg:*),Bash(pygount:*)",
         "--permission-mode", "acceptEdits"],   # no edit tools are allowed anyway
        capture_output=True, text=True, timeout=600,
    )
    if proc.returncode != 0:
        print(f"  ! claude failed on #{issue['number']}: {proc.stderr[:300]}", file=sys.stderr)
        return None
    # `claude -p --output-format json` wraps the result; the model's text is in .result
    try:
        envelope = json.loads(proc.stdout)
        text = envelope.get("result", proc.stdout) if isinstance(envelope, dict) else proc.stdout
    except json.JSONDecodeError:
        text = proc.stdout
    return parse_verdict(text, issue["number"])


def parse_verdict(text: str, number: int) -> dict | None:
    m = re.search(r"\{.*\}", text, re.DOTALL)
    if not m:
        print(f"  ! no JSON in verdict for #{number}", file=sys.stderr)
        return None
    try:
        v = json.loads(m.group(0))
    except json.JSONDecodeError as e:
        print(f"  ! bad JSON for #{number}: {e}", file=sys.stderr)
        return None
    if v.get("verdict") not in VERDICT_LABEL:
        return None
    # Evidence discipline: a close-type verdict with no citation is downgraded.
    if v["verdict"] in ("implemented-on-main", "cannot-reproduce", "not-planned") \
            and not v.get("evidence"):
        v["verdict"], v["confidence"] = "actionable", "low"
        v["summary"] = "(downgraded: close-type verdict lacked citations) " + v.get("summary", "")
    return v


def desired_labels(v: dict) -> set[str]:
    labels = {VERDICT_LABEL[v["verdict"]]}
    if v.get("blast") in BLAST_LABELS:
        labels.add(BLAST_LABELS[v["blast"]])
    for r in v.get("risks", []):
        if r in RISK_LABELS:
            labels.add(RISK_LABELS[r])
    return labels


def apply_labels(number: int, want: set[str]):
    have = {l["name"] for l in gh_json([f"repos/{REPO}/issues/{number}/labels"]) or []}
    add = want - have
    remove = (have & ALL_MANAGED) - want            # only ever touch our own labels
    for name in remove:
        if not DRY_RUN:
            subprocess.run(["gh", "api", "-X", "DELETE",
                            f"repos/{REPO}/issues/{number}/labels/{name}"],
                           capture_output=True)
    if add and not DRY_RUN:
        subprocess.run(["gh", "issue", "edit", str(number), "--repo", REPO,
                        *sum([["--add-label", n] for n in add], [])],
                       capture_output=True)
    print(f"  labels +{sorted(add)} -{sorted(remove)}")


def render_comment(number: int, sha: str, v: dict) -> str:
    lines = [
        f"<!-- triage-sweeper:swept-sha={sha} item={number} -->",
        f"<!-- triage-sweeper:verdict={v['verdict']} confidence={v['confidence']} -->",
        "**🤖 Automated triage-sweeper review.** A maintainer makes the final call — "
        "this comment only labels and cites evidence.",
        "",
        f"**Verdict:** `{v['verdict']}` · **confidence:** `{v['confidence']}`"
        + (f" · **blast:** `{v['blast']}`" if v.get("blast") else ""),
        "",
        v.get("summary", "").strip(),
    ]
    ev = v.get("evidence") or []
    if ev:
        lines += ["", "**Evidence** (against current `main`):"]
        for e in ev:
            if e.get("path"):
                loc = f"`{e['path']}" + (f":{e['lines']}`" if e.get("lines") else "`")
                lines.append(f"- {loc} — {e.get('note','').strip()}")
            elif e.get("commit"):
                lines.append(f"- commit `{e['commit'][:12]}` — {e.get('note','').strip()}")
    lines += ["", "<sub>Wrong? Add the `sweeper:skip` label and I won't touch this issue "
              "again. Re-run happens automatically when `main` moves.</sub>"]
    return "\n".join(lines)


def upsert_comment(number: int, body: str):
    if DRY_RUN:
        print(f"  [dry-run] would comment on #{number}")
        return
    existing = existing_sweeper_comment(number)
    if existing:
        subprocess.run(["gh", "api", "-X", "PATCH",
                        f"repos/{REPO}/issues/comments/{existing['id']}",
                        "-f", f"body={body}"], capture_output=True)
    else:
        subprocess.run(["gh", "issue", "comment", str(number), "--repo", REPO,
                        "--body", body], capture_output=True)


def main() -> int:
    sha = head_sha()
    print(f"triage-sweeper on {REPO} @ {sha[:12]} "
          f"(max={MAX_ISSUES}, dry_run={DRY_RUN})")
    swept = 0
    for issue in eligible_issues():
        if swept >= MAX_ISSUES:
            break
        n, updated = issue["number"], issue["updatedAt"]
        if already_swept(n, sha, updated):
            continue
        print(f"- #{n} {issue['title'][:70]}")
        full = gh_json([f"repos/{REPO}/issues/{n}"])
        issue.update(body=full.get("body", ""),
                     author=(full.get("user") or {}).get("login", "?"),
                     labels=[l["name"] for l in full.get("labels", [])],
                     _sha=sha)
        v = run_claude(issue)
        if not v:
            print("  skipped (no usable verdict)")
            continue
        apply_labels(n, desired_labels(v))
        upsert_comment(n, render_comment(n, sha, v))
        swept += 1
        time.sleep(2)  # be gentle on the API
    print(f"done — swept {swept} issue(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
