# Browser extension privacy policy

This page is the privacy policy for the Keepiq browser extension in the Chrome Web Store, Firefox Add-ons and Microsoft Edge Add-ons.

## What the extension does

The Keepiq extension fills logins, one-time codes and passkeys from your Keepiq vault. Your vault lives on your organisation's own Nextcloud server. The extension talks only to that server, the one you connect it to.

## What leaves your device

The extension opens your vault inside the extension, with your master password. Your master password never leaves the extension. The server receives:

- your Nextcloud user name and app password, with every request, to sign in;
- the address of the site you are on, when you open the extension there, so the server can find the logins for it;
- encrypted secrets, when you save or update a login;
- the name and web address of a secret, which Keepiq keeps unencrypted so it can match a site;
- the first five characters of a password's SHA-1 hash, when your organisation checks new passwords against known breaches. The full password and its full hash stay on your device.

The extension sends nothing to Conduction or to anyone other than your own server.

## What the extension stores on your device

In the browser's extension storage, per connected account: the server address, your Nextcloud user name, a Nextcloud app password, a label and the idle lock delay you picked. You revoke the app password in your Nextcloud security settings to cut the extension off.

Also per account, a copy of your vault as the server stores it, so the extension works while the server cannot be reached: encrypted secrets, and the unencrypted names, web addresses and folders. It is replaced at every sync and removed when you disconnect the account, or when its app password is revoked.

Once for the browser: how long a copied value stays on the clipboard.

In the browser's session storage, for at most five minutes after a login fill: the tab, the site and which one-time code secret to use on the next step. It holds no code and no secret. Also, per open tab, which frames are on which site, so a fill reaches only the site you picked. The browser keeps session storage in memory only.

A login you submit on a site waits in memory for at most five minutes, for you to save it. It is gone when you save or dismiss it, when its tab closes, or when the vault locks.

Your master password, your decrypted secrets and your vault key are never stored. They stay in memory while the vault is unlocked and are gone when it locks.

## What the extension does not do

- It does not keep a history of the sites you visit. It sends the site you are on to your own server only when you open the extension there.
- It does not collect analytics or crash reports.
- It does not sell, share or transfer data to third parties.

## Firefox data collection declaration

Firefox asks extensions to declare what they send outside the browser. The extension declares `authenticationInfo` (your app password and your encrypted logins) and `browsingActivity` (the site you are on, to find its logins). Both go only to your own server.

## Contact

Questions go to your organisation's Keepiq administrator, or to Conduction at info@conduction.nl.
