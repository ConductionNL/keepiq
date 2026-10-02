#!/usr/bin/env bash
# Drives the GitHub Action script and the GitLab template's install against a
# stub Keepiq (sdk/testdata/stub_server.py) and a local "release" directory.
#
#   integrations/test/run.sh <path to a linux CLI binary for this machine>
#
# Needs bash, curl, sha256sum, jq and python3 with cryptography and PyYAML.
# Used by .github/workflows/integrations.yml; runs the same on a laptop.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
cli="${1:?usage: run.sh <keepiq linux binary>}"
arch="$(uname -m)"
case "$arch" in x86_64 | amd64) arch=amd64 ;; aarch64 | arm64) arch=arm64 ;; esac

work="$(mktemp -d)"
pids=()
cleanup() {
	for p in "${pids[@]}"; do kill "$p" 2> /dev/null || true; done
	rm -rf "$work"
}
trap cleanup EXIT

pass=0
ok() { pass=$((pass + 1)); echo "ok - $*"; }
no() { echo "not ok - $*" >&2; exit 1; }

port() { python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])'; }

# A release directory: the binary, a SHA256SUMS line for it, and a tampered twin.
mkdir -p "$work/release/cli-vtest" "$work/release/cli-vbad"
cp "$cli" "$work/release/cli-vtest/keepiq-linux-$arch"
(cd "$work/release/cli-vtest" && sha256sum "keepiq-linux-$arch" > SHA256SUMS)
cp "$cli" "$work/release/cli-vbad/keepiq-linux-$arch"
printf 'tampered' >> "$work/release/cli-vbad/keepiq-linux-$arch"
cp "$work/release/cli-vtest/SHA256SUMS" "$work/release/cli-vbad/SHA256SUMS"

rport="$(port)"
(cd "$work/release" && exec python3 -m http.server "$rport" --bind 127.0.0.1 > /dev/null 2>&1) &
pids+=($!)

db_value='hunter2-db-password'
token_value=$'first line of the token\nsecond line of the token'
sport="$(port)"
python3 "$root/sdk/testdata/stub_server.py" --port "$sport" --add "DB_PASSWORD=$db_value" --add "API_TOKEN=$token_value" > /dev/null 2>&1 &
pids+=($!)
for _ in $(seq 50); do
	curl -fs "http://127.0.0.1:$sport/index.php/apps/keepiq/api/v1/app/.well-known/keepiq" > /dev/null 2>&1 && curl -fs "http://127.0.0.1:$rport/cli-vtest/SHA256SUMS" > /dev/null 2>&1 && break
	sleep 0.2
done

key="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["privateKeyPem"])' "$root/sdk/testdata/machine_envelope.json")"
action() {
	env -i PATH="$PATH" HOME="$work/home" RUNNER_TEMP="$work/runner-temp" \
		KEEPIQ_URL="http://127.0.0.1:$sport/index.php" KEEPIQ_APP_ID=billing KEEPIQ_APP_KEY="$key" \
		KEEPIQ_DOWNLOAD_BASE="http://127.0.0.1:$rport" KEEPIQ_ACTION_REF="" "$@" \
		bash "$root/integrations/github-action/keepiq-action.sh"
}
mkdir -p "$work/home" "$work/runner-temp"

# 1. run mode: the command sees the value, not the application key.
out="$(action KEEPIQ_VERSION=cli-vtest KEEPIQ_SECRETS=DB_PASSWORD \
	KEEPIQ_RUN='test "$KEEPIQ_DB_PASSWORD" = "hunter2-db-password" && test -z "${KEEPIQ_APP_KEY:-}" && echo ran-with-secret' 2>&1)" || no "run mode failed: $out"
grep -q 'ran-with-secret' <<< "$out" || no "run mode: the command did not see KEEPIQ_DB_PASSWORD: $out"
grep -q 'checksum verified' <<< "$out" || no "run mode: no checksum line: $out"
ok "run mode puts the value in the command's environment, without the application key"

# 2. nothing on disk holds the value.
if grep -rqF "$db_value" "$work/home" "$work/runner-temp" 2> /dev/null; then no "a file holds the secret value"; fi
ok "run mode writes the value to no file"

# 3. NAME=ENV_VAR mapping.
out="$(action KEEPIQ_VERSION=cli-vtest KEEPIQ_SECRETS=$'DB_PASSWORD=PGPASSWORD\n' \
	KEEPIQ_RUN='test "$PGPASSWORD" = "hunter2-db-password" && test -z "${KEEPIQ_DB_PASSWORD:-}" && echo mapped' 2>&1)" || no "mapping failed: $out"
grep -q mapped <<< "$out" || no "mapping: PGPASSWORD not set: $out"
ok "NAME=ENV_VAR maps the value to the chosen variable"

# 4. export mode: every line masked before it is written to GITHUB_ENV.
genv="$work/github_env"
: > "$genv"
out="$(action KEEPIQ_VERSION=cli-vtest KEEPIQ_SECRETS=API_TOKEN KEEPIQ_EXPORT_ENV=true GITHUB_ENV="$genv" 2>&1)" || no "export failed: $out"
grep -qxF '::add-mask::first line of the token' <<< "$out" || no "export: first line not masked: $out"
grep -qxF '::add-mask::second line of the token' <<< "$out" || no "export: second line not masked: $out"
if grep -v '^::add-mask::' <<< "$out" | grep -qF 'line of the token'; then no "export: the value reached the log unmasked: $out"; fi
exported="$(bash -c 'set -a; while IFS= read -r l; do
	if [[ "$l" =~ ^([A-Z_]+)\<\<(.*)$ ]]; then n="${BASH_REMATCH[1]}"; d="${BASH_REMATCH[2]}"; v=""; first=1;
		while IFS= read -r x && [ "$x" != "$d" ]; do if [ $first = 1 ]; then v="$x"; first=0; else v="$v"$'"'"'\n'"'"'"$x"; fi; done
		printf "%s" "$v"; fi; done < "$1"' _ "$genv")"
[ "$exported" = "$token_value" ] || no "export: GITHUB_ENV holds '$exported'"
ok "export-env masks every line and writes the multi-line value to GITHUB_ENV"

# 5. neither run nor export-env: fail and name both.
if out="$(action KEEPIQ_VERSION=cli-vtest KEEPIQ_SECRETS=DB_PASSWORD 2>&1)"; then no "no mode: should fail"; fi
grep -q "'run'" <<< "$out" && grep -q "'export-env: true'" <<< "$out" || no "no mode: message does not name both options: $out"
ok "without run or export-env the step fails and names both options"

# 6. a binary that does not match SHA256SUMS is refused before it runs.
if out="$(action KEEPIQ_VERSION=cli-vbad KEEPIQ_SECRETS=DB_PASSWORD KEEPIQ_RUN='echo should-not-run' 2>&1)"; then no "tampered binary: should fail"; fi
grep -q 'Checksum mismatch' <<< "$out" || no "tampered binary: no checksum error: $out"
grep -q 'should-not-run' <<< "$out" && no "tampered binary ran"
ok "a binary that does not match SHA256SUMS is refused"

# 7. no version and no cli-v action ref: a clear error.
if out="$(action KEEPIQ_SECRETS=DB_PASSWORD KEEPIQ_RUN=true 2>&1)"; then no "no version: should fail"; fi
grep -q "Set 'version'" <<< "$out" || no "no version: unclear error: $out"
ok "without a version or a cli-v tag the step says what to set"

# 8. GitLab: the template's .keepiq before_script installs a checked CLI, and a
#    job script wrapped with keepiq ci run sees the value.
before="$(python3 -c 'import sys,yaml; print("\n".join(yaml.safe_load(open(sys.argv[1]))[".keepiq"]["before_script"]))' "$root/integrations/gitlab-ci/keepiq.gitlab-ci.yml")"
mkdir -p "$work/gitlab"
# gitlab <KEEPIQ_CLI_VERSION> <job script>: before_script and script share one
# shell, as in a GitLab job.
gitlab() {
	(cd "$work/gitlab" && env -i PATH="$PATH" HOME="$work/home" CI_PROJECT_DIR="$work/gitlab" \
		KEEPIQ_URL="http://127.0.0.1:$sport/index.php" KEEPIQ_APP_ID=billing KEEPIQ_APP_KEY="$key" \
		KEEPIQ_DOWNLOAD_BASE="http://127.0.0.1:$rport" KEEPIQ_CLI_VERSION="$1" sh -c "$before
$2")
}
cat > "$work/gitlab/migrate.sh" << 'SH'
#!/bin/sh
test "$KEEPIQ_DB_PASSWORD" = "hunter2-db-password" && echo migrated
SH
chmod +x "$work/gitlab/migrate.sh"
out="$(gitlab cli-vtest 'keepiq ci run DB_PASSWORD -- ./migrate.sh' 2>&1)" || no "gitlab job failed: $out"
grep -q migrated <<< "$out" || no "gitlab: migrate.sh did not see the value: $out"
ok "a GitLab job extending .keepiq runs its command with the secret in its environment"
if out="$(gitlab cli-vbad 'echo should-not-run' 2>&1)"; then no "gitlab tampered: should fail"; fi
grep -q 'Checksum mismatch' <<< "$out" || no "gitlab tampered: $out"
ok "the GitLab template refuses a binary that does not match SHA256SUMS"

echo "all $pass checks passed"
