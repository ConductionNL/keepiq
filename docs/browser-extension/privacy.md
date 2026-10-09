# Browser extension privacy policy

This page is the privacy policy for the Keepiq browser extension on Chrome, other Chromium browsers and Firefox. It covers the builds from the Keepiq source and the coming store versions.

## What the extension does

Keepiq opens your Keepiq vault in your browser. Your vault lives on your organisation's own Nextcloud server. The extension opens it with your master password, in the browser.

## Where the extension connects

The extension talks to your own Nextcloud, and to nobody else. It sends your server:

- your Nextcloud user name and app password, with every request, to sign in;
- requests for your profile (name, email address and picture), your encrypted vault key and your encrypted vault.

Your master password never leaves the browser. Neither do your decrypted secrets.

The extension sends nothing to Conduction. It has no analytics, no crash reports and no advertising. It does not load website icons, so no site and no icon service learns which accounts you have. The Firefox version declares to Mozilla that it collects no data.

## What the extension stores in your browser

Per account: the server address, your Nextcloud user name, display name, email address and profile picture, an app password, and your settings. These stay until you log out of the account. The app password also goes when the server stops accepting it.

A copy of your vault as the server stores it, so you can open it offline. It holds your encrypted secrets and the names, web addresses and folders of your items, which Keepiq keeps unencrypted. The copy goes when you log out of the account, when your vault key changes in Keepiq, and when your organisation's two-factor policy stops this login from opening the vault.

While the vault is unlocked, the browser keeps your vault key in its session storage, which lives in memory and is cleared when the browser closes. The **Never** lock option, which comes with the settings screen, is the one exception: it stores the key on disk so the vault stays open after a restart, and it warns you about that before you pick it.

Your master password and your decrypted secrets are never stored. Decrypted values stay in memory while you look at them and are gone when you close the item or the extension.

When you are halfway through adding an account, the extension remembers the server address and user name until the browser closes, so closing the popup does not lose them. It never remembers the app password you typed.

## What the extension reads in your browser

- **The address of your current tab,** to suggest the items for that site. It stays in the extension.
- **The pages you visit:** the extension's page script runs on every site, so that filling in logins can work there. Today it only tells the extension a page has loaded. It never receives your vault, and when autofill arrives it gets one login, after you chose it.
- **Whether your computer is locked,** for the **On system lock** lock option, which comes with the settings screen.
- **Your browser's language,** to show the extension in it. It stays in the extension.

## Permissions

| Permission | Why |
| --- | --- |
| Access to your Nextcloud server | Asked when you add an account, for that server only. Keepiq needs it to reach your vault. |
| Read and change data on all websites | The page script that fills in logins runs on every site. It holds no vault data. |
| Storage, unlimited storage | Keeps your accounts, settings and the encrypted vault copy. Firefox otherwise limits it to 5 MB. |
| Tabs | Reads the current tab's address for suggestions, and opens an item's website in a new tab. |
| Alarms | Locks the vault on time and checks for changes every 15 minutes. |
| Idle | Locks the vault when your computer locks, if you choose that. |
| Clipboard write | Clears a copied password after a delay, which you set in the settings screen when it comes. Until then the clipboard is not cleared. |
| Offscreen document (Chrome) | Chrome needs a hidden page to clear the clipboard. |

## What the extension does not do

- It does not keep a history of the sites you visit or fill in.
- It does not collect analytics or crash reports.
- It does not sell, share or transfer data to third parties.

## Contact

Questions go to your organisation's Keepiq administrator, or to Conduction at info@conduction.nl.
