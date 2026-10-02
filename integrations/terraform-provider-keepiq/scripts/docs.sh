#!/usr/bin/env bash
# Regenerate docs/ with tfplugindocs from the provider's real schema.
#   scripts/docs.sh <terraform 1.11+ binary> [--check]
# With --check it fails when docs/ is not current (CI).
#
# The schema is exported through a dev_overrides install of a fresh build,
# because tfplugindocs's own Terraform download needs a reachable release
# signing key. The registry address is shortened to "keepiq" for tfplugindocs.
set -euo pipefail
tf="${1:?usage: scripts/docs.sh <terraform binary> [--check]}"
here="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
(cd "$here" && go build -buildvcs=false -o "$work/bin/terraform-provider-keepiq" .)
printf 'provider_installation {\n  dev_overrides { "conductionnl/keepiq" = "%s" }\n  direct {}\n}\n' "$work/bin" > "$work/rc"
mkdir -p "$work/cfg"
printf 'terraform {\n  required_providers {\n    keepiq = { source = "conductionnl/keepiq" }\n  }\n}\n' > "$work/cfg/main.tf"
(cd "$work/cfg" && TF_CLI_CONFIG_FILE="$work/rc" "$tf" providers schema -json) | sed 's#registry.terraform.io/conductionnl/keepiq#keepiq#' > "$work/schema.json"
cd "$here"
GOFLAGS=-buildvcs=false go run github.com/hashicorp/terraform-plugin-docs/cmd/tfplugindocs@v0.20.1 generate --provider-name keepiq --providers-schema "$work/schema.json"
if [ "${2:-}" = "--check" ]; then
	git diff --exit-code -- docs || { echo "docs/ is not current: run scripts/docs.sh and commit" >&2; exit 1; }
	[ -z "$(git status --porcelain -- docs)" ] || { echo "docs/ has new files: run scripts/docs.sh and commit" >&2; exit 1; }
fi
