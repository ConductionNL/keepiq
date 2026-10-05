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
# Writes screenshots (PNG), a video of the run and the instrumentation output
# to <out dir>. Fails when a test class did not report exactly its one test
# as passed: a class that did not run is a failure, not a pass.
set -euo pipefail

OUT="$(mkdir -p "${1:?out dir}" && cd "$1" && pwd)"
PROJECT="${2:?compose project}"
APP_PASSWORD="$(tr -d '[:space:]' < "${3:?app password file}")"
HERE="$(cd "$(dirname "$0")" && pwd)"
APK_DIR="$HERE/../android/app/build/outputs/apk"
PKG=nl.conduction.keepiq
SERVER=https://10.0.2.2:8443

occ() { docker exec -u www-data "${PROJECT}-nc-1" php occ "$@"; }

adb wait-for-device
adb install -r -t "$APK_DIR/e2e/app-e2e.apk"
adb install -r -t "$APK_DIR/androidTest/e2e/app-e2e-androidTest.apk"
adb shell input keyevent KEYCODE_WAKEUP
adb shell wm dismiss-keyguard || true
adb shell settings put system screen_off_timeout 1800000 || true

# The video is recorded by the emulator itself, on this host, under 3 minutes.
adb emu screenrecord start --time-limit 175 "$OUT/keepiq-android.webm" || echo "::warning::the emulator did not start a recording"

pull_shots() {
	adb exec-out run-as "$PKG" sh -c 'cd files 2>/dev/null && tar -cf - e2e-shots 2>/dev/null' | tar -xf - -C "$OUT" 2>/dev/null || true
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
run_class PairUnlockUnpairTest || status=1

# The two-factor block: the organisation requires two-factor, and admin has
# none, so the server withholds the key.
adb shell pm clear "$PKG" >/dev/null
occ config:app:set keepiq vault_require_two_factor --value=true --type=boolean
run_class ManualPairingAndBlockTest -e keepiqAppPassword "$APP_PASSWORD" || status=1
occ config:app:delete keepiq vault_require_two_factor

adb emu screenrecord stop || true
sleep 3

# System autofill (task group 4): the test APK's forms and a WebView page
# on the test server, with a recording of its own.
adb shell pm clear "$PKG" >/dev/null
adb emu screenrecord start --time-limit 175 "$OUT/keepiq-android-autofill.webm" || echo "::warning::the emulator did not start a recording"
run_class SystemAutofillTest -e keepiqAppPassword "$APP_PASSWORD" || status=1
adb emu screenrecord stop || true
sleep 3

for video in keepiq-android keepiq-android-autofill; do
	if [ -f "$OUT/$video.webm" ] && command -v ffmpeg >/dev/null; then
		ffmpeg -loglevel error -y -i "$OUT/$video.webm" -c:v libx264 -pix_fmt yuv420p -movflags +faststart "$OUT/$video.mp4" \
			&& rm "$OUT/$video.webm"
	fi
done
ls -la "$OUT" "$OUT/e2e-shots" 2>/dev/null || true
exit "$status"
