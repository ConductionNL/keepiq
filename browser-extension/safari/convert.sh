#!/usr/bin/env bash
# Convert the Chromium package into a Safari web extension Xcode project.
# Needs macOS with Xcode. See README.md next to this script.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
pkg="$here/../dist/chromium"
out="$here/../dist/safari"
if [ "$(uname)" != "Darwin" ]; then
	echo "The Safari conversion needs macOS with Xcode." >&2
	exit 2
fi
[ -f "$pkg/manifest.json" ] || { echo "Build first: npm run build:extension" >&2; exit 1; }
rm -rf "$out" && mkdir -p "$out"
xcrun safari-web-extension-converter "$pkg" \
	--project-location "$out" \
	--app-name Keepiq \
	--bundle-identifier nl.conduction.keepiq \
	--no-open --no-prompt --force 2>&1 | tee "$out/convert.log"
