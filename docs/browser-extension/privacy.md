# Browser extension privacy policy

This page is the privacy policy for the Keepiq browser extension on Chrome, other Chromium browsers and Firefox. It covers the builds from the Keepiq source and the coming store versions.

## What the extension does

Keepiq opens your Keepiq vault in your browser. Your vault lives on your organisation's own Nextcloud server. The extension opens it with your master password, in the browser.

## Where the extension connects

The extension talks to your own Nextcloud, and to nobody else. It sends your server:

- your Nextcloud user name and app password, with every request, to sign in;
- requests for your profile (name, email address and picture), your encrypted vault key, your encrypted vault, and your organisation's policies: the password policy the generator follows, whether vault export is turned off for you, and the longest the vault may stay unlocked while idle. It does not read your Keepiq preferences;
- a request for the Keepiq version your server runs, to check the extension supports it;
- the items and folders you add, change or delete. An item's secrets, user name, notes and additional fields are encrypted in the browser first; its name, web address, type and folder go as you typed them, as Keepiq stores them. Folder names are not encrypted.
- when you open an item, a check whether you may edit it, which names the item;
- when you change a shared item's secrets, user name or fields, a lookup of the encryption certificates of everyone it is shared with (by user id, or by cloud id at a partner organisation), and the change encrypted for each of them;
- the sends you create, encrypted in the browser first, with their type, number of views, expiry and, for a password send, the encrypted key and its salt, never the password; and requests to list and remove your sends. The key of a send without a password is only in its link, which goes only where you paste it.

Your master password never leaves the browser. Neither do your decrypted secrets.

Filling in logins and the save bar send nothing new: matching happens in your browser, and a login you save or update from the bar goes to your server like any item you add or change.

The extension sends nothing to Conduction. It has no analytics, no crash reports and no advertising. It does not load website icons, so no site and no icon service learns which accounts you have. The Firefox version declares to Mozilla that it collects no data.

## What the extension stores in your browser

Per account: the server address and its Keepiq version, your Nextcloud user name, display name, email address and profile picture, an app password, and your settings. These stay until you log out of the account. The app password also goes when the server stops accepting it.

A copy of your vault as the server stores it, so you can open it offline. It holds your encrypted secrets and the names, web addresses and folders of your items, which Keepiq keeps unencrypted. The copy goes when you log out of the account, when your vault key changes in Keepiq, and when your organisation's two-factor policy stops this login from opening the vault.

While the vault is unlocked, the browser keeps your vault key in its session storage, which lives in memory and is cleared when the browser closes. The **Never** lock option is the one exception: it stores the key on disk so the vault stays open after a restart, and it warns you about that before you pick it. Your organisation's lock maximum rules it out, and the key leaves the disk as soon as the extension reads that maximum.

When you set a PIN, the browser keeps your vault key encrypted under that PIN in its session storage, with the number of wrong PINs entered, also while the vault is locked. That copy goes when the browser closes. On disk the extension keeps only your PIN, encrypted under a key that only your unlocked vault key gives, so it can bring the PIN back after you enter your master password once following a restart. Both go when you turn the PIN off, log out or enter five wrong PINs, and when your vault key changes or your organisation's two-factor policy stops this login from opening the vault.

Your master password and your decrypted secrets are never stored. Decrypted values stay in memory while you look at them and are gone when you close the item or the extension.

Your theme and appearance choices, for every account. They stay when you log out.

Per account, your organisation's lock maximum and whether it turned vault export off, and whether you dismissed the notice that folder names are not encrypted, until you remove the account. The lock maximum stays when your app password stops working or the timeout logs you out, so logging in again never lifts it.

Per account, your generator options, until you remove the account, and your organisation's password policy, until you log out.

Per account, when you last filled in each login, so the one you used last comes first and the keyboard shortcut knows which to fill. It is one time per login, not a list of the sites you visited, and stays in this browser until you log out. It is the only thing filling in and saving logins keep on disk.

While a list of logins is open under a login field, the browser keeps a random key and which logins it offered in its session storage, without names or user names. It goes when the list closes.

A user name and password you just submitted on a website stay in memory only, for the save bar, until you answer it, leave the site or lock the vault, and at most 5 minutes.

The last 50 values you regenerated, copied or used, or left on a sub-tab, in the browser's session storage, which lives in memory. They are not encrypted, and the extension shows them only while the vault is unlocked. They go when the vault locks, when you log out, when the browser closes, or when you clear the history. Values you generate on the lock screen are kept encrypted until you unlock, and only then show in the history.

An item you are adding or editing stays in memory only, and is gone when you save, leave the form or close the extension.

A send you are writing, its password and the links of the sends you just made stay in memory only, and are gone when you close the extension, lock it or switch account. Your list of sends is fetched from your server each time and is not stored.

When you are halfway through adding an account, the extension remembers the server address and user name until the browser closes, so closing the popup does not lose them. It never remembers the app password you typed.

## What the extension reads in your browser

- **The address of your current tab,** to suggest the items for that site, and for the site name in a generated email address when you choose it. It stays in the extension.
- **The pages you visit:** the extension's page script runs on every site, to find login fields, fill in a login and offer to save one. It never receives your vault: a fill gives the page one login, after you chose it. The list under a login field and the save bar are extension frames the site can't read.
- **The user name and password you submit on a website,** to offer to save or update them. They stay in the extension, and reach your server only encrypted, when you choose **Save** or **Update**.
- **Whether your computer is locked,** for the **On system lock** lock option.
- **Your browser's language,** to show the extension in it. It stays in the extension.
- **Whether your browser is set to light or dark,** for the **System default** theme. It stays in the extension.

## Permissions

| Permission | Why |
| --- | --- |
| Access to your Nextcloud server | Asked when you add an account, for that server only. Keepiq needs it to reach your vault. |
| Read and change data on all websites | The page script that fills in logins runs on every site. It holds no vault data. |
| Storage, unlimited storage | Keeps your accounts, settings and the encrypted vault copy. Firefox otherwise limits it to 5 MB. |
| Tabs | Reads the current tab's address for suggestions, and opens an item's website in a new tab. |
| Alarms | Locks the vault on time and checks for changes every 15 minutes. |
| Idle | Locks the vault when your computer locks, if you choose that. |
| Clipboard write | Copies from the right-click menu, and clears a copied password after 30 seconds, or after the delay you choose in the settings. |
| Offscreen document (Chrome) | Chrome needs a hidden page to write and clear the clipboard. |
| Context menus | Adds the Keepiq menu when you right-click a field. |

## What the extension does not do

- It does not keep a history of the sites you visit or fill in; it only remembers when each login was last filled.
- It does not collect analytics or crash reports.
- It does not sell, share or transfer data to third parties.

## Contact

Questions go to your organisation's Keepiq administrator, or to Conduction at info@conduction.nl.
