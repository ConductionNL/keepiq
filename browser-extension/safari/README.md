# Safari build (open task)

Safari runs web extensions through an app wrapper that Apple's converter makes
from the Chromium package. This step needs macOS with Xcode, which the
project does not have yet, so it is an open task of
`clients-extension-firefox-and-safari-builds` (task 1.2). Signing and store
release belong to `clients-extension-store-release`.

On a Mac with Xcode 15 or later:

```sh
npm ci
npm run build:extension                 # makes browser-extension/dist/chromium
bash browser-extension/safari/convert.sh
```

`convert.sh` runs `xcrun safari-web-extension-converter` on
`dist/chromium` without opening Xcode, writes the project to
`browser-extension/dist/safari/` and keeps the converter's output in
`browser-extension/dist/safari/convert.log`. Keep that log with the build.

What is known to differ in Safari, to check on the first run:

- Safari has no `idle` permission; the idle lock falls back to the in-worker timer.
- Passkeys go through the page-context shim, as in Firefox.
