#!/usr/bin/env bash
# Wraps .output/safari-mv3/ in Keepiq.app with our handler, builds it ad hoc signed, and zips it to
# .output/keepiq-safari.zip (design.md § Containing app). macOS with Xcode only; `npm run package:safari`.
# Written for macOS's bash 3.2: no mapfile.
set -euo pipefail

cd "$(dirname "$0")/.."

APP_NAME=Keepiq
APP_ID=nl.conduction.keepiq.safari
EXTENSION=.output/safari-mv3
PROJECT_DIR=.output/safari-app
BUILD_DIR=.output/safari-build
ZIP=.output/keepiq-safari.zip

fail() {
	echo "package:safari: $*" >&2
	exit 1
}

# Collects find's matches into the array `found`, keeping paths with spaces ("Keepiq Extension") whole.
find_all() {
	found=()
	local path
	while IFS= read -r path; do found+=("$path"); done < <(find "$@")
}

[ -f "$EXTENSION/manifest.json" ] || fail "no Safari build at $EXTENSION, run npm run build:safari first"
version=$(node -p "require('./package.json').version")

rm -rf "$PROJECT_DIR" "$BUILD_DIR" "$ZIP"
xcrun safari-web-extension-packager "$EXTENSION" \
	--project-location "$PROJECT_DIR" --app-name "$APP_NAME" --bundle-identifier "$APP_ID" \
	--swift --macos-only --copy-resources --no-open --no-prompt --force

# The packager's layout changes between Xcode versions, so find what we overlay rather than assume its path.
find_all "$PROJECT_DIR" -type f -name SafariWebExtensionHandler.swift
[ "${#found[@]}" -eq 1 ] || fail "expected one generated SafariWebExtensionHandler.swift under $PROJECT_DIR, found ${#found[@]}"
cp safari/SafariWebExtensionHandler.swift "${found[0]}"

find_all "$PROJECT_DIR" -maxdepth 3 -type d -name '*.xcodeproj'
[ "${#found[@]}" -eq 1 ] || fail "expected one .xcodeproj under $PROJECT_DIR, found ${#found[@]}"
project=${found[0]}

# The packager names the app <id prefix>.<app name> but the extension <id>.Extension, a pair xcodebuild refuses,
# so every bundle id that is not ours or under it (the app target's) becomes ours.
pbxproj="$project/project.pbxproj"
[ -f "$pbxproj" ] || fail "no $pbxproj"
node -e '
	const fs = require("fs")
	const [file, appId] = process.argv.slice(1)
	const ours = (id) => id === appId || id.startsWith(appId + ".")
	let apps = 0
	let extensions = 0
	const text = fs.readFileSync(file, "utf8").replace(/^(\s*PRODUCT_BUNDLE_IDENTIFIER = )(.*);$/gm, (line, key, value) => {
		const id = value.replace(/^"(.*)"$/, (_, inner) => inner)
		console.log("Generated PRODUCT_BUNDLE_IDENTIFIER: " + id)
		if (id.startsWith(appId + ".")) extensions++
		if (id === appId) apps++
		if (ours(id)) return line
		apps++
		return key + appId + ";"
	})
	if (apps === 0 || extensions === 0) {
		console.error("expected app bundle ids to set to " + appId + " and extension ones under it, found " + apps + " and " + extensions)
		process.exit(1)
	}
	fs.writeFileSync(file, text)
' "$pbxproj" "$APP_ID" || fail "could not set the app's bundle id to $APP_ID in $pbxproj"

schemes=$(xcodebuild -list -json -project "$project")
scheme=$(printf '%s' "$schemes" | node -e '
	const schemes = JSON.parse(require("fs").readFileSync(0, "utf8")).project?.schemes ?? []
	if (schemes.includes(process.argv[1])) console.log(process.argv[1])
	else console.error("schemes in the project: " + (schemes.join(", ") || "none"))
' "$APP_NAME")
[ -n "$scheme" ] || fail "no scheme named $APP_NAME in $project"

# Ad hoc ("Sign to Run Locally"): runs on a test Mac with "Allow unsigned extensions"; Apple Silicon runs no unsigned code.
xcodebuild -project "$project" -scheme "$scheme" -configuration Release -derivedDataPath "$BUILD_DIR" \
	CODE_SIGN_IDENTITY=- CODE_SIGN_STYLE=Manual DEVELOPMENT_TEAM= \
	MARKETING_VERSION="$version" CURRENT_PROJECT_VERSION="${BUILD_NUMBER:-1}" build

app="$BUILD_DIR/Build/Products/Release/$APP_NAME.app"
[ -d "$app" ] || fail "xcodebuild made no $app"
find_all "$app/Contents/PlugIns" -maxdepth 1 -type d -name '*.appex'
[ "${#found[@]}" -eq 1 ] || fail "expected one .appex in $app/Contents/PlugIns, found ${#found[@]}"

bundle_id() {
	/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$1/Contents/Info.plist"
}
app_id=$(bundle_id "$app")
extension_id=$(bundle_id "${found[0]}")
echo "App bundle id: $app_id"
echo "Extension bundle id: $extension_id"
[ "$app_id" = "$APP_ID" ] || fail "app bundle id is $app_id, expected $APP_ID"
case "$extension_id" in
	"$APP_ID".?*) ;;
	*) fail "extension bundle id is $extension_id, expected $APP_ID.<name>" ;;
esac

ditto -c -k --keepParent "$app" "$ZIP"
echo "Packaged $ZIP"
