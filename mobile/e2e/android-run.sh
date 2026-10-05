#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Runs the Android end-to-end tests on a booted emulator, against the test
# server behind mobile/e2e/server.mjs proxy (https://10.0.2.2:8443 from the
# emulator). Called by .github/workflows/mobile-e2e.yml inside
# reactivecircus/android-emulator-runner.
#
#   bash mobile/e2e/android-run.sh <out dir> <compose project> <app password file>
#
# Writes screenshots (PNG), a video per test class (each under 3 minutes) and
# the instrumentation output to <out dir>. Fails when a test class did not
# report exactly its one test as passed: a class that did not run is a
# failure, not a pass. The vault test needs the demo vault of
# `server.mjs seed`.
set -euo pipefail

OUT="$(mkdir -p "${1:?out dir}" && cd "$1" && pwd)"
PROJECT="${2:?compose project}"
APP_PASSWORD="$(tr -d '[:space:]' < "${3:?app password file}")"
HERE="$(cd "$(dirname "$0")" && pwd)"
APK_DIR="$HERE/../android/app/build/outputs/apk"
PKG=nl.conduction.keepiq
SERVER=https://10.0.2.2:8443

occ() { docker exec -u www-data "${PROJECT}-nc-1" php occ "$@"; }

# The web server can keep an app setting cached for a while after occ changes
# it. Wait until the suites answer no longer reports the two-factor block, so
# the next test class does not start against a vault that is still withheld.
wait_until_unblocked() {
	local answer
	for _ in $(seq 1 60); do
		answer="$(curl -sk -u "admin:$APP_PASSWORD" -H 'OCS-APIRequest: true' \
			https://localhost:8443/index.php/apps/keepiq/api/v1/suites || true)"
		case "$answer" in
			*unlockBlocked*) sleep 2 ;;
			'') sleep 2 ;;
			*) return 0 ;;
		esac
	done
	echo "::error::the two-factor block was still reported 120 s after the setting was removed"
	return 1
}

adb wait-for-device
adb install -r -t "$APK_DIR/e2e/app-e2e.apk"
adb install -r -t "$APK_DIR/androidTest/e2e/app-e2e-androidTest.apk"
# An ordinary app that does not instrument Keepiq (PackageVisibilityTest).
adb install -r -t "$HERE/../android/otherapp/build/outputs/apk/debug/otherapp-debug.apk"
adb shell input keyevent KEYCODE_WAKEUP
adb shell wm dismiss-keyguard || true
adb shell settings put system screen_off_timeout 1800000 || true

pull_shots() {
	adb exec-out run-as "$PKG" sh -c 'cd files 2>/dev/null && tar -cf - e2e-shots 2>/dev/null' | tar -xf - -C "$OUT" 2>/dev/null || true
}

# The video is recorded by the emulator itself, on this host, under 3 minutes.
video_start() {
	adb emu screenrecord start --time-limit 175 "$OUT/$1.webm" || echo "::warning::the emulator did not start a recording"
}

video_stop() {
	adb emu screenrecord stop || true
	sleep 3
	if [ -f "$OUT/$1.webm" ] && command -v ffmpeg >/dev/null; then
		ffmpeg -loglevel error -y -i "$OUT/$1.webm" -c:v libx264 -pix_fmt yuv420p -movflags +faststart "$OUT/$1.mp4" \
			&& rm "$OUT/$1.webm"
	fi
}

run_class() {
	local class="$1"; shift
	local log="$OUT/$class.txt"
	adb logcat -c || true
	adb shell am instrument -w \
		-e class "$PKG.android.$class" \
		-e keepiqServer "$SERVER" \
		-e keepiqUser admin \
		-e keepiqMasterPassword Oj \
		"$@" \
		"$PKG.test/androidx.test.runner.AndroidJUnitRunner" | tee "$log"
	pull_shots
	adb logcat -d > "$OUT/logcat-$class.txt" || true
	grep -q '^OK (1 test)' "$log"
}

status=0
video_start keepiq-android-pairing
run_class PairUnlockUnpairTest || status=1
video_stop keepiq-android-pairing

# The vault flows, over the seeded demo vault, with an app password of their own.
adb shell pm clear "$PKG" >/dev/null
VAULT_PASSWORD="$(docker exec -u www-data -e NC_PASS=admin "${PROJECT}-nc-1" \
	php occ user:auth-tokens:add --password-from-env --name 'Keepiq e2e vault' admin | tail -n 1 | tr -d '[:space:]')"
video_start keepiq-android-vault
run_class VaultFlowsTest -e keepiqAppPassword "$VAULT_PASSWORD" || status=1
video_stop keepiq-android-vault

# The two-factor block: the organisation requires two-factor, and admin has
# none, so the server withholds the key.
adb shell pm clear "$PKG" >/dev/null
occ config:app:set keepiq vault_require_two_factor --value=true --type=boolean
run_class ManualPairingAndBlockTest -e keepiqAppPassword "$APP_PASSWORD" || status=1
occ config:app:delete keepiq vault_require_two_factor
wait_until_unblocked

# System autofill (task group 4): the test APK's forms and a WebView page
# on the test server.
adb shell pm clear "$PKG" >/dev/null
video_start keepiq-android-autofill
run_class SystemAutofillTest -e keepiqAppPassword "$APP_PASSWORD" || status=1
video_stop keepiq-android-autofill

# Package visibility: Keepiq fills an app that the test APK does not stand in for.
adb shell pm clear "$PKG" >/dev/null
run_class PackageVisibilityTest -e keepiqAppPassword "$APP_PASSWORD" || status=1

ls -la "$OUT" "$OUT/e2e-shots" 2>/dev/null || true
exit "$status"
