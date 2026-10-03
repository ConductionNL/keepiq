## ADDED Requirements

### Requirement: The extension is published in the browser stores

The system MUST publish the browser extension to the Chrome Web Store, Firefox Add-ons and Microsoft Edge Add-ons from a CI job that runs on an `extension-v<semver>` tag, and MUST attach the built packages, including a signed Firefox package for self-hosting, to a GitHub release for that tag. The publishing job MUST run in a protected GitHub environment that a maintainer approves, and store credentials MUST NOT be readable by any pull-request workflow.

#### Scenario: A tag ships to the stores

- **GIVEN** a maintainer pushes the tag `extension-v1.2.0`
- **WHEN** a maintainer approves the `extension-stores` environment for the release job
- **THEN** the job MUST submit the Chrome package to the Chrome Web Store, the Firefox package to Firefox Add-ons and the Chrome package to Edge Add-ons
- **AND** the GitHub release `extension-v1.2.0` MUST hold the Chrome zip and the Firefox package

#### Scenario: The signed Firefox file follows the review

- **GIVEN** the release `extension-v1.2.0` exists and Firefox Add-ons has approved version 1.2.0
- **WHEN** the daily follow-up job runs, or a maintainer runs it for version 1.2.0
- **THEN** the job MUST attach the file Firefox Add-ons signed to the release `extension-v1.2.0`
- **AND** it MUST refuse to attach a file whose hash differs from the one Firefox Add-ons publishes, or whose files other than the signature differ from the release's Firefox package

#### Scenario: A pull request cannot reach store credentials

- **GIVEN** a pull request that changes `browser-extension/manifest.json`
- **WHEN** the extension workflow runs for that pull request
- **THEN** it MUST build, lint and test both targets
- **AND** it MUST NOT have access to any `extension-stores` secret

### Requirement: Packages are built from source per browser and reproducibly

The system MUST build a Chrome manifest with `background.service_worker` and a Firefox manifest with `browser_specific_settings.gecko.id` and `background.scripts` from one source tree, MUST take the manifest `version` from the release tag, and MUST fail the workflow when two builds of the same commit produce different packages.

#### Scenario: Two builds match

- **GIVEN** the extension workflow on any commit
- **WHEN** it builds the Firefox target twice
- **THEN** the two package hashes MUST be equal, or the workflow MUST fail

### Requirement: Listings carry a privacy policy and least permissions

The system MUST ship each store listing with a privacy policy that states the extension sends the server only ciphertext and the unencrypted `name` and `url` index fields, and stores only the pairing in extension storage. The manifest MUST NOT request a permission that no extension code uses.

#### Scenario: An unused permission fails the build

- **GIVEN** a manifest that lists `scripting`
- **WHEN** the extension test suite checks every requested permission against its call sites in `browser-extension/src/`
- **THEN** the test MUST fail naming `scripting`

### Requirement: The extension checks the server version on pairing

The system MUST return the Keepiq server version from `POST /api/v1/extension/pair`, and the extension MUST show an update message instead of the vault view when the server version is below the extension's minimum.

#### Scenario: Old server, new extension

- **GIVEN** a store-updated extension whose minimum server version is newer than the paired Keepiq server
- **WHEN** a vault owner opens the popup
- **THEN** the popup MUST say the Keepiq server needs an update
- **AND** the extension MUST NOT call `GET /api/v1/extension/match`

### Requirement: Organisations can force-install the extension

The system MUST document how an administrator force-installs the extension by store id through Chrome and Edge enterprise policy, and how to deploy the signed Firefox package through Firefox enterprise policy.

#### Scenario: Chrome policy install

- **GIVEN** an administrator who adds the Keepiq store id to `ExtensionInstallForcelist`
- **WHEN** a managed Chrome profile starts
- **THEN** the Keepiq extension MUST be installed and shown in the toolbar without user action
