# Releasing the browser extension

The workflow `.github/workflows/extension-release.yml` builds and publishes the extension.

## On every pull request

It runs the extension tests, builds the Chrome and Firefox packages twice and fails when the two builds differ, lints the Firefox package with `web-ext lint`, and keeps the zips and a source archive as workflow artefacts. A pull request cannot read any store credential.

## Releasing a version

1. Push a tag `extension-v<version>`, for example `extension-v1.2.0`. The version becomes the manifest version; it must be one to four numbers separated by dots.
2. A maintainer approves the `extension-stores` environment for the run.
3. The job submits the Chrome package to the Chrome Web Store, the Firefox package with its source archive to Firefox Add-ons, and the Chrome package to Edge Add-ons, and attaches the packages to the GitHub release.

Store review then takes from hours to days per store.

## Store credentials

All of these are settings of the GitHub environment `extension-stores`. Environment secrets are readable only by jobs that name the environment, and the environment requires a maintainer's approval.

| Name | Kind | What it holds | Where it comes from |
|---|---|---|---|
| `CHROME_EXTENSION_ID` | variable | The Chrome Web Store item id, for example `YOUR_CHROME_EXTENSION_ID` | Chrome Web Store developer dashboard |
| `CHROME_CLIENT_ID` | secret | OAuth client id with access to the Chrome Web Store API, `YOUR_CHROME_CLIENT_ID` | Google Cloud console |
| `CHROME_CLIENT_SECRET` | secret | The matching client secret, `YOUR_CHROME_CLIENT_SECRET` | Google Cloud console |
| `CHROME_REFRESH_TOKEN` | secret | A refresh token for the publishing Google account, `YOUR_CHROME_REFRESH_TOKEN` | OAuth consent flow |
| `AMO_JWT_ISSUER` | secret | Firefox Add-ons API key (JWT issuer), `YOUR_AMO_JWT_ISSUER` | addons.mozilla.org developer hub, API keys |
| `AMO_JWT_SECRET` | secret | Firefox Add-ons API secret, `YOUR_AMO_JWT_SECRET` | addons.mozilla.org developer hub, API keys |
| `EDGE_PRODUCT_ID` | variable | The Edge Add-ons product id, `YOUR_EDGE_PRODUCT_ID` | Microsoft Partner Center |
| `EDGE_CLIENT_ID` | secret | Edge Add-ons API client id, `YOUR_EDGE_CLIENT_ID` | Partner Center, Publish API |
| `EDGE_API_KEY` | secret | Edge Add-ons API key, `YOUR_EDGE_API_KEY` | Partner Center, Publish API |

## Still to do before the first release

- Create the publisher accounts on the three stores and the first listings, with the privacy policy (`privacy.md`) and the permission lines (`permissions.md`).
- Create the `extension-stores` environment, add the settings above and name its required reviewers.
- Run a first release on a test tag and check each store by hand.
