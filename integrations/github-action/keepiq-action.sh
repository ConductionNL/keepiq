#!/usr/bin/env bash
# The Keepiq GitHub Action (integrations/github-action/action.yml).
#
# 1. Installs the CLI for this runner from a cli-v* release and refuses it
#    unless its SHA-256 matches the release's SHA256SUMS.
# 2. With KEEPIQ_RUN, runs that command through `keepiq ci run`: the values
#    exist only in the command's environment and nothing is written to disk.
# 3. With KEEPIQ_EXPORT_ENV=true, masks every line of every value with
#    ::add-mask:: and only then appends it to $GITHUB_ENV.
# 4. With neither, fails and names both options.
#
# Inputs arrive as environment variables (see action.yml). It can run outside
# GitHub too, which is how its test drives it.
set -euo pipefail

fail() { echo "::error::$*" >&2; exit 1; }

export_env="$(printf '%s' "${KEEPIQ_EXPORT_ENV:-false}" | tr '[:upper:]' '[:lower:]')"
if [ -z "${KEEPIQ_RUN:-}" ] && [ "$export_env" != "true" ]; then
	fail "Nothing to do: set 'run' to run a command with the secrets, or set 'export-env: true' to export them to later steps."
fi

# --- the secret list: NAME or NAME=ENV_VAR, one per line ---
names=()
targets=()
while IFS= read -r line || [ -n "$line" ]; do
	line="$(printf '%s' "$line" | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
	[ -z "$line" ] && continue
	name="${line%%=*}"
	target=""
	[ "$name" != "$line" ] && target="${line#*=}"
	if [ -z "$target" ]; then
		target="KEEPIQ_$(printf '%s' "$name" | tr '[:lower:]' '[:upper:]' | sed 's/[^A-Z0-9]/_/g')"
	fi
	case "$target" in
		[A-Za-z_]*) ;;
		*) fail "Invalid environment variable name '$target' for secret '$name'." ;;
	esac
	if printf '%s' "$target" | grep -q '[^A-Za-z0-9_]'; then
		fail "Invalid environment variable name '$target' for secret '$name'."
	fi
	names+=("$name")
	targets+=("$target")
done <<< "${KEEPIQ_SECRETS:-}"
[ "${#names[@]}" -gt 0 ] || fail "No secret names given in 'secrets'."
for n in "${names[@]}"; do
	case "$n" in *,*) fail "Secret name '$n' contains a comma, which keepiq ci run uses as a separator." ;; esac
done

# --- install the CLI and check it against SHA256SUMS ---
version="${KEEPIQ_VERSION:-}"
if [ -z "$version" ]; then
	case "${KEEPIQ_ACTION_REF:-}" in
		cli-v*) version="$KEEPIQ_ACTION_REF" ;;
		*) fail "Set 'version' to a CLI release such as cli-v0.3.0 (this action is not used at a cli-v tag, so there is no matching release)." ;;
	esac
fi

case "${RUNNER_OS:-$(uname -s)}" in
	Linux) os=linux ;;
	macOS | Darwin) os=darwin ;;
	Windows | MINGW* | MSYS*) os=windows ;;
	*) fail "Unsupported runner OS '${RUNNER_OS:-$(uname -s)}'." ;;
esac
case "${RUNNER_ARCH:-$(uname -m)}" in
	X64 | x86_64 | amd64) arch=amd64 ;;
	ARM64 | arm64 | aarch64) arch=arm64 ;;
	*) fail "Unsupported runner architecture '${RUNNER_ARCH:-$(uname -m)}'." ;;
esac
ext=""
[ "$os" = windows ] && ext=".exe"
binary="keepiq-${os}-${arch}${ext}"

dir="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/keepiq-cli.XXXXXX")"
trap 'rm -rf "$dir"' EXIT
base="${KEEPIQ_DOWNLOAD_BASE%/}/${version}"
curl -fsSL --retry 3 -o "$dir/$binary" "$base/$binary" || fail "Could not download $base/$binary."
curl -fsSL --retry 3 -o "$dir/SHA256SUMS" "$base/SHA256SUMS" || fail "Could not download $base/SHA256SUMS."

want="$(awk -v f="$binary" '{ n = $2; sub(/^\*/, "", n); if (n == f) print $1 }' "$dir/SHA256SUMS")"
[ -n "$want" ] || fail "SHA256SUMS of $version has no line for $binary."
if command -v sha256sum > /dev/null 2>&1; then
	got="$(sha256sum "$dir/$binary" | awk '{ print $1 }')"
else
	got="$(shasum -a 256 "$dir/$binary" | awk '{ print $1 }')"
fi
if [ "$got" != "$want" ]; then
	fail "Checksum mismatch for $binary from $version: got $got, SHA256SUMS says $want. Refusing to run it."
fi
chmod +x "$dir/$binary"
keepiq="$dir/$binary"
echo "Installed keepiq $version ($binary), checksum verified."

# --- run mode: values only in the command's environment ---
if [ -n "${KEEPIQ_RUN:-}" ]; then
	# keepiq ci run puts each value in KEEPIQ_<NAME>. A NAME=ENV_VAR mapping is
	# applied inside the wrapped shell, so it never touches disk either.
	# The wrapped command gets the secrets, not the application key.
	prelude="unset KEEPIQ_APP_KEY KEEPIQ_APP_KEY_FILE KEEPIQ_WRAPPED; "
	for i in "${!names[@]}"; do
		default="KEEPIQ_$(printf '%s' "${names[$i]}" | tr '[:lower:]' '[:upper:]' | sed 's/[^A-Z0-9]/_/g')"
		if [ "${targets[$i]}" != "$default" ]; then
			prelude+="export ${targets[$i]}=\"\${${default}}\"; unset ${default}; "
		fi
	done
	joined="$(IFS=,; printf '%s' "${names[*]}")"
	set +e
	KEEPIQ_WRAPPED="${prelude}${KEEPIQ_RUN}" "$keepiq" ci run "$joined" -- bash -c 'eval "$KEEPIQ_WRAPPED"'
	status=$?
	set -e
	[ "$status" -eq 0 ] || exit "$status"
fi

# --- export mode: mask, then write to $GITHUB_ENV ---
if [ "$export_env" = "true" ]; then
	[ -n "${GITHUB_ENV:-}" ] || fail "export-env needs \$GITHUB_ENV, which only exists on a GitHub runner."
	for i in "${!names[@]}"; do
		json="$("$keepiq" ci fetch "${names[$i]}" --output json)"
		if command -v jq > /dev/null 2>&1; then
			value="$(printf '%s' "$json" | jq -j '.value')"
		else
			value="$(printf '%s' "$json" | python3 -c 'import json,sys; sys.stdout.write(json.load(sys.stdin)["value"])')"
		fi
		# Mask every line before the value can reach any log.
		while IFS= read -r part || [ -n "$part" ]; do
			[ -n "$part" ] && echo "::add-mask::$part"
		done <<< "$value"
		delimiter="KEEPIQ_EOF_$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
		case "$value" in *"$delimiter"*) fail "Value of '${names[$i]}' contains the generated delimiter; retry." ;; esac
		{
			printf '%s<<%s\n' "${targets[$i]}" "$delimiter"
			printf '%s\n' "$value"
			printf '%s\n' "$delimiter"
		} >> "$GITHUB_ENV"
		echo "Exported ${targets[$i]} (masked)."
	done
fi
