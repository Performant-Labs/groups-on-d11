#!/usr/bin/env bash
# Idempotently create/update the sweeper label taxonomy from labels.yml.
# Usage: GH_REPO=Performant-Labs/language-buddy ./sync-labels.sh
set -euo pipefail
: "${GH_REPO:?set GH_REPO=owner/repo}"
YML="$(dirname "$0")/../.github/labels.yml"

# Reads the flat "- name/color/description" list. Needs PyYAML (pip install pyyaml).
python3 - "$YML" "$GH_REPO" <<'PY'
import subprocess, sys, yaml
path, repo = sys.argv[1], sys.argv[2]
items = yaml.safe_load(open(path))
for it in items:
    name, color, desc = it["name"], it.get("color","ededed"), it.get("description","")
    # create; if it exists, update color+description.
    r = subprocess.run(["gh","label","create",name,"--repo",repo,
                        "--color",color,"--description",desc,"--force"],
                       capture_output=True, text=True)
    print(("ok  " if r.returncode==0 else "ERR ")+name+("" if r.returncode==0 else " :: "+r.stderr.strip()))
PY
