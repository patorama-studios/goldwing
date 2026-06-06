#!/usr/bin/env bash
# Install TinyMCE Community (GPL) into /public_html/assets/vendor/tinymce/.
# Run this once on the server before using the AGM Content tab WYSIWYG editor.
# The Content tab falls back to a plain textarea when TinyMCE isn't present, so
# this script is required only when the WYSIWYG experience is desired.

set -euo pipefail

VERSION="${TINYMCE_VERSION:-7.5.1}"
TARGET="$(cd "$(dirname "$0")/.." && pwd)/public_html/assets/vendor/tinymce"
TMP_DIR="$(mktemp -d)"

cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

URL="https://download.tiny.cloud/tinymce/community/tinymce_${VERSION}.zip"

echo "Downloading TinyMCE Community ${VERSION} from ${URL}..."
curl -fSL "$URL" -o "$TMP_DIR/tinymce.zip"

echo "Unpacking..."
unzip -q "$TMP_DIR/tinymce.zip" -d "$TMP_DIR"

mkdir -p "$TARGET"
rm -rf "$TARGET"/*
cp -R "$TMP_DIR/tinymce/"* "$TARGET/"

echo "Installed TinyMCE ${VERSION} to:"
echo "  $TARGET"
echo "The AGM Content tab now uses the self-hosted WYSIWYG editor."
