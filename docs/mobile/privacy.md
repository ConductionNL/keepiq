# Mobile app privacy policy

This page is the privacy policy for Keepiq for Android and Keepiq for iOS. It covers the preview on GitHub and the coming builds on Google Play, F-Droid and the App Store.

## What the app does

Keepiq opens your Keepiq vault on your phone. Your vault lives on your organisation's own Nextcloud server. The app opens it with your master password, on the phone.

## Where the app connects

The app talks to three kinds of server, and to nobody else.

**Your own Nextcloud.** This is where your vault lives. The app sends it:

- your Nextcloud user name and app password, with every request, to sign in;
- encrypted secrets, when you add or change an item;
- the name and web address of an item, which Keepiq keeps unencrypted so it can match a site or an app;
- the content of a new Send, encrypted, and its expiry and view limit.

Your master password never leaves the phone. Neither do your decrypted secrets.

**The server in a Send link you open.** When you open a Send link in Keepiq, the app fetches that Send from the server in the link. That can be another organisation's Nextcloud. It sends nothing about you or your vault.

**A website's public app list.** Another app on your phone may ask Keepiq for a login or a passkey. Keepiq then checks whether that app really belongs to the website. It reads the website's public file `/.well-known/assetlinks.json` for that. The request carries nothing about you, and Keepiq remembers the answer for a day. A browser asking for a login does not need this check.

The app sends nothing to Conduction. It has no analytics, no crash reports and no advertising.

## What the app stores on your phone

Per connected account: the server address, your Nextcloud user name and an app password. Android keeps these encrypted under a key that never leaves the phone. iOS keeps them in the keychain. You revoke the app password in your Nextcloud security settings to cut the phone off.

Keepiq for Android also keeps a copy of your vault as the server stores it, so you can open it offline. That copy is encrypted as a whole as well. It holds your encrypted secrets and the names, web addresses and folders of your items. It is removed when you disconnect the account.

When you turn on a PIN or fingerprint or face unlock, the phone stores your vault key, locked by that PIN or by the phone's secure hardware. Five wrong PINs delete it.

The app also stores your settings: the lock delay, how long a copy stays on the clipboard, the sites and apps where you said never to save a login, and the signing certificate of each browser that asked for a login.

Your master password, your decrypted secrets and your unlocked vault key are never stored. They stay in memory while the vault is unlocked and are gone when it locks.

Keepiq is left out of phone backups, so your vault copy does not end up in a cloud backup.

## What the app reads on your phone

To fill in a login in another app, Keepiq looks up that app's name and signing certificate on the phone. This is how it makes sure a login only goes to the app it belongs to. The list of your apps stays on the phone.

## What the app does not do

- It does not keep a history of the apps or sites you fill logins in.
- It does not collect analytics or crash reports.
- It does not sell, share or transfer data to third parties.

## Contact

Questions go to your organisation's Keepiq administrator, or to Conduction at info@conduction.nl.
