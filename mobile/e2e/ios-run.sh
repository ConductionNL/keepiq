#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Runs the iOS UI tests on a simulator against mobile/e2e/server.mjs replay,
# which answers as the recorded test server (there is no Docker on the macOS
# runners). Called by .github/workflows/mobile-e2e.yml after the shared
# framework is built and `xcodegen generate` ran in mobile/ios.
#
#   bash mobile/e2e/ios-run.sh <out dir>
#
# Writes screenshots, a video per test class (each under 3 minutes) and an
# xcresult bundle per class.
set -euo pipefail

OUT="$(mkdir -p "${1:?out dir}" && cd "$1" && pwd)"
HERE="$(cd "$(dirname "$0")" && pwd)"
IOS="$HERE/../ios"
CERTS="$(mktemp -d)"
mkdir -p "$OUT/shots"

# A certificate for localhost, trusted by this simulator only.
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=Keepiq e2e" \
	-addext "subjectAltName=DNS:localhost,IP:127.0.0.1" \
	-addext "basicConstraints=critical,CA:TRUE" \
	-addext "extendedKeyUsage=serverAuth" \
	-keyout "$CERTS/key.pem" -out "$CERTS/cert.pem" 2>/dev/null

UDID="$(xcrun simctl list devices available -j | python3 -c '
import json, sys
devices = json.load(sys.stdin)["devices"]
best = None
for runtime, items in devices.items():
    if "iOS" not in runtime:
        continue
    version = tuple(int(p) for p in runtime.rsplit("iOS-", 1)[-1].split("-") if p.isdigit())
    for d in items:
        if d["name"].startswith("iPhone") and (best is None or version > best[0]):
            best = (version, d["udid"], d["name"])
print(best[1])
')"
echo "simulator $UDID"
xcrun simctl boot "$UDID" || true
xcrun simctl bootstatus "$UDID" -b
xcrun simctl keychain "$UDID" add-root-cert "$CERTS/cert.pem"

node "$HERE/server.mjs" replay --port 8443 --cert "$CERTS/cert.pem" --key "$CERTS/key.pem" > "$OUT/replay.log" 2>&1 &
REPLAY=$!
trap 'kill "$REPLAY" 2>/dev/null || true' EXIT

xcodebuild build-for-testing -project "$IOS/Keepiq.xcodeproj" -scheme Keepiq \
	-destination "id=$UDID" -derivedDataPath "$OUT/DerivedData" -quiet

# One test class at a time, each with its own video under 3 minutes.
status=0
run_class() {
	local class="$1" video="$2"
	xcrun simctl io "$UDID" recordVideo --codec=h264 --force "$OUT/$video.mp4" &
	local recorder=$!
	TEST_RUNNER_KEEPIQ_SERVER=https://localhost:8443 TEST_RUNNER_KEEPIQ_SHOTS_DIR="$OUT/shots" \
		xcodebuild test-without-building -project "$IOS/Keepiq.xcodeproj" -scheme Keepiq \
		-destination "id=$UDID" -derivedDataPath "$OUT/DerivedData" \
		-only-testing:"KeepiqUITests/$class" \
		-resultBundlePath "$OUT/$class.xcresult" || status=1
	kill -INT "$recorder" 2>/dev/null || true
	wait "$recorder" 2>/dev/null || true
	fit_video "$OUT/$video.mp4"
}

# A video longer than 3 minutes is played faster until it fits, so the whole
# run stays visible. The simulator itself has no time limit to record with.
fit_video() {
	local file="$1" seconds
	[ -f "$file" ] || return 0
	command -v ffmpeg >/dev/null || brew install --quiet ffmpeg >/dev/null 2>&1 || return 0
	seconds="$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$file" | cut -d. -f1)"
	[ "${seconds:-0}" -gt 175 ] || return 0
	ffmpeg -loglevel error -y -i "$file" -an -vf "setpts=PTS*170/${seconds}" -c:v libx264 -pix_fmt yuv420p \
		-movflags +faststart "${file%.mp4}-fit.mp4" && mv "${file%.mp4}-fit.mp4" "$file"
}
run_class PairUnlockUITests keepiq-ios-pairing
run_class VaultFlowsUITests keepiq-ios-vault
# The AutoFill extension's screens inside the app (task group 4), then its
# passkey screens and calls (task 5.2), both against the replay.
run_class AutofillUITests keepiq-ios-autofill
run_class PasskeyUITests keepiq-ios-passkeys
# The accessibility audit of the main screens (task 3.1), against the replay.
run_class AccessibilityAuditUITests keepiq-ios-a11y
rm -rf "$OUT/DerivedData"
ls -la "$OUT" "$OUT/shots"
exit "$status"
