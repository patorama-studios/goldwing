#!/usr/bin/env bash
# Tour impact check — wrapper that diffs vs origin/main and pipes to the PHP checker.
#
# Use as a git pre-push hook, a CI step, or run manually:
#   ./scripts/check_tour_impact.sh
#
# To install as a git pre-push hook:
#   ln -s ../../scripts/check_tour_impact.sh .git/hooks/pre-push

set -euo pipefail
cd "$(dirname "$0")/.."

# Compare current HEAD to origin/main (fall back to main if no origin).
BASE="origin/main"
if ! git rev-parse --verify --quiet "$BASE" >/dev/null; then
  BASE="main"
fi

CHANGED="$(git diff --name-only "$BASE"...HEAD || true)"
if [ -z "$CHANGED" ]; then
  echo "✓ No diff vs $BASE — nothing to check."
  exit 0
fi

echo "$CHANGED" | php scripts/check_tour_impact.php
