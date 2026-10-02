# Rolling out the browser extension

How to install the Keepiq extension for a whole organisation.

## Chrome and Edge

Add the store id to the `ExtensionInstallForcelist` policy. Chrome and Edge then install the extension on every managed profile and keep it updated from the store.

```text
Chrome: <CHROME_EXTENSION_ID>;https://clients2.google.com/service/update2/crx
Edge:   <EDGE_EXTENSION_ID>;https://edge.microsoft.com/extensionwebstorebase/v1/crx
```

On Windows set it through Group Policy or Intune, on macOS through a configuration profile, and on Linux in `/etc/opt/chrome/policies/managed/keepiq.json`:

```json
{
  "ExtensionInstallForcelist": [
    "<CHROME_EXTENSION_ID>;https://clients2.google.com/service/update2/crx"
  ]
}
```

The store ids are filled in here once the listings are live.

## Firefox

Firefox installs add-ons by policy from Firefox Add-ons or from a signed package. Add this to `policies.json` (or the matching Group Policy):

```json
{
  "policies": {
    "ExtensionSettings": {
      "keepiq@conduction.nl": {
        "installation_mode": "force_installed",
        "install_url": "https://addons.mozilla.org/firefox/downloads/latest/keepiq/latest.xpi"
      }
    }
  }
}
```

To host the package yourself, download the signed Firefox package from the GitHub release `extension-v<version>` and point `install_url` at your copy.

## After installing

Each user connects the extension to Keepiq once: server address, Nextcloud user and a Nextcloud app password. The extension needs a Keepiq server at least as new as its minimum version, and says so when the server is older.
