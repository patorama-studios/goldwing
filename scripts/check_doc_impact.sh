#!/usr/bin/env bash
# Documentation impact check — wrapper that diffs vs origin/main and pipes
# changed files into the PHP checker.
#
# Use as a git pre-push hook, a CI step, or run manually:
#   ./scripts/check_doc_impact.sh
#
# To install as a git pre-push hook:
#   ln -s ../../scripts/check_doc_impact.sh .git/hooks/pre-push

set -euo pipefail
cd "$(dirname "$0")/.."

BASE="origin/main"
if ! git rev-parse --verify --quiet "$BASE" >/dev/null; then
  BASE="main"
fi

CHANGED="$(git diff --name-only "$BASE"...HEAD || true)"
# Include uncommitted changes too — the doc author probably wants to know
# about everything that's about to land, not just what's already committed.
UNCOMMITTED="$(git diff --name-only HEAD || true)"
UNTRACKED="$(git ls-files --others --exclude-standard || true)"

ALL="$(printf "%s\n%s\n%s\n" "$CHANGED" "$UNCOMMITTED" "$UNTRACKED" | awk 'NF' | sort -u)"
if [ -z "$ALL" ]; then
  echo "✓ No diff vs $BASE — nothing to check."
  exit 0
fi

echo "$ALL" | php scripts/check_doc_impact.php
