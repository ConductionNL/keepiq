# Browser extension privacy policy

This page is the privacy policy for the Keepiq browser extension in the Chrome Web Store, Firefox Add-ons and Microsoft Edge Add-ons.

## What the extension does

The Keepiq extension fills logins, one-time codes and passkeys from your Keepiq vault. Your vault lives on your organisation's own Nextcloud server. The extension talks only to that server, the one you connect it to.

## What leaves your device

The extension opens your vault inside the extension, with your master password. Your master password never leaves the extension. The server receives:

- encrypted secrets, when you save or update a login;
- the name and web address of a secret, which Keepiq keeps unencrypted so it can match a site;
- the first five characters of a password's SHA-1 hash, when your organisation checks new passwords against known breaches. The full password and its full hash stay on your device.

The extension sends nothing to Conduction or to anyone other than your own server.

## What the extension stores on your device

In the browser's extension storage, per connected account: the server address, your Nextcloud user name, a Nextcloud app password, a label and the idle lock delay you picked. You revoke the app password in your Nextcloud security settings to cut the extension off.

In the browser's session storage, for at most five minutes after a login fill: the tab, the site and which one-time code secret to use on the next step. It holds no code and no secret, and the browser keeps it in memory only.

Your master password, your decrypted secrets and your vault key are never stored. They stay in memory while the vault is unlocked and are gone when it locks.

## What the extension does not do

- It does not track the sites you visit.
- It does not collect analytics or crash reports.
- It does not sell, share or transfer data to third parties.

## Contact

Questions go to your organisation's Keepiq administrator, or to Conduction at info@conduction.nl.
