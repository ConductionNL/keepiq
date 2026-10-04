# Using the browser extension

The Keepiq extension fills your logins, one-time codes and passkeys from your Keepiq vault, in Chrome, Edge and Firefox. It opens your vault inside the extension with your master password. The server only ever sees encrypted values.

## Getting the extension

The store listings for Chrome, Edge and Firefox are not live yet. Until then, download the packages from the [Keepiq releases on GitHub](https://github.com/ConductionNL/keepiq/releases?q=extension-v&expanded=true). Each `extension-v<version>` release carries:

- `keepiq-chromium-<version>.zip` for Chrome and Edge. Unzip it, open `chrome://extensions`, switch on developer mode and choose **Load unpacked**.
- `keepiq-firefox-<version>-amo-signed.xpi` for Firefox, once Firefox Add-ons has signed that version. Open the file in Firefox to install it.
- `keepiq-firefox-<version>.zip`, the unsigned Firefox package. Firefox only loads it as a temporary add-on, from `about:debugging`.

Your organisation can also install the extension for you. See [rolling it out](rollout.md).

## Connecting your account

1. In Nextcloud, open **Settings → Security** and create an app password for the extension.
2. Click the Keepiq button in the browser toolbar.
3. Enter the address of your Nextcloud, your user name and the app password, and choose **Connect**.

![The Keepiq popup asks for the server address, the Nextcloud user and an app password](/media/browser-extension/pair.png)

You can paste any Nextcloud address, for example one you copied from a Keepiq page: the extension keeps only the server part. It needs an https address, so your app password is never sent in clear. Only a server on your own computer may use http.

You can connect up to five accounts, on one or more servers. Pick the account you want in the bar at the top of the popup.

## Unlocking

Enter your master password and press Enter. **Show** lets you check what you typed. Your master password never leaves the extension.

![The lock screen with the master password field and the Unlock button](/media/browser-extension/unlock.png)

<video controls muted playsInline width="380" style={{ maxWidth: '100%' }} src="/media/browser-extension/pair-and-unlock.webm"></video>

Other ways to unlock:

- **A PIN.** In **Settings → This account**, enter your master password and a PIN of at least six characters. After that, the lock screen offers the PIN first. The PIN works until you close the browser, and after five wrong PINs Keepiq asks for your master password again.
- **Fingerprint or face.** Where your computer supports it, **Settings** offers fingerprint or face unlock.
- **Without a connection.** When your server cannot be reached, your master password still unlocks the copy of your vault the extension keeps.

The vault locks again after the idle time you choose in **Settings** (1 minute to 4 hours), when your computer locks, or when you press the lock button. When it locks, the popup clears everything it showed.

## The tabs

The tabs at the bottom of the popup:

- **This site** lists the logins for the site you are on. Pick one to fill it in.
- **Vault** lists all your items. Search, filter by folder or type, and open an item to see, copy, edit, clone, move or send it. Each item shows its type and site, with **Copy** for a password and **Open** for a web address.
- **Generator** makes passwords, passphrases and usernames.
- **Send** shares text or a username and password through a link that expires.
- **Settings** holds your account, autofill and appearance settings.

The pop-out button opens the popup in its own window, which stays with the site you opened it from.

![This site shows the one login saved for webmail.example.com](/media/browser-extension/this-site.png)

### The vault

Search the vault, or filter it by folder or type. Open an item to see its details. **Show** reveals the password, **Copy** copies it.

![The Vault tab lists five demo logins, each with Copy and Open](/media/browser-extension/vault.png)

![The details of the demo bank login, with its username, password and web address](/media/browser-extension/vault-item.png)

<video controls muted playsInline width="380" style={{ maxWidth: '100%' }} src="/media/browser-extension/vault.webm"></video>

### The generator

Pick a password, a passphrase or a username. Set the length and the characters you want. **Regenerate** makes a new one, **Copy** copies it.

![The generator shows a 14 character password and its options](/media/browser-extension/generator.png)

![The generator shows a passphrase of words joined by dashes](/media/browser-extension/generator-passphrase.png)

<video controls muted playsInline width="380" style={{ maxWidth: '100%' }} src="/media/browser-extension/generator.webm"></video>

## Filling logins

Open the popup on a login page and pick a login under **This site**. Keepiq fills it only in the parts of the page that belong to that site, not in an advert or a frame from somewhere else.

You can also:

- right-click a form field and choose **Fill a login with Keepiq**;
- press **Ctrl+Shift+L** (**Command+Shift+L** on a Mac). You can change the shortcut in your browser's extension settings.

When the site has one login, it fills at once. When it has several, or the vault is locked, the popup opens so you can choose. When a login was saved for a secure (https) site and the page is not secure, Keepiq asks before it fills.

After a login, Keepiq fills the one-time code on the next step when it can, or copies it for you to paste.

![A demo webmail sign-in page with the email address and password filled in by Keepiq](/media/browser-extension/fill-filled.png)

<video controls muted playsInline width="640" style={{ maxWidth: '100%' }} src="/media/browser-extension/fill.webm"></video>

## Saving logins

When you sign in with a login Keepiq does not know, a bar asks whether to save it. You can save it, choose **Not now**, or choose **Never for this site**. When you change a password, Keepiq offers to update the saved login. The bar comes back on the next page of the same site if the login page moves you on.

![After signing in to a demo forum, a bar asks whether to save the login in Keepiq](/media/browser-extension/save-prompt.png)

![The bar confirms the login was saved to Keepiq](/media/browser-extension/save-done.png)

<video controls muted playsInline width="640" style={{ maxWidth: '100%' }} src="/media/browser-extension/save-prompt.webm"></video>

From the popup you can also pick the folder a new login goes into. **Settings → Autofill** switches the save and update offers off, and lists the sites you said never to.

## Passkeys

When a website offers to create a passkey, Keepiq asks whether to store it in your vault. Choose **Allow**. The next time you sign in on that site, Keepiq asks again and signs you in with the passkey. The passkey's private key stays in the extension: the popup never shows it.

![Keepiq asks whether to create and store a passkey for passkeys.example.com](/media/browser-extension/passkey-consent.png)

![The demo page says you are signed in with your passkey](/media/browser-extension/passkey-signed-in.png)

<video controls muted playsInline width="640" style={{ maxWidth: '100%' }} src="/media/browser-extension/passkey.webm"></video>

## Copying

Everything you copy from the extension is cleared from the clipboard after 30 seconds, also when the popup has closed. Change the delay in **Settings → Autofill**, or switch it off.

## Sends

A send shares text, or a username and password, through a link. Choose how often it may be opened and when it expires. With an optional password, share the password another way: the link alone then cannot open it. **My sends** shows each send with how often it was opened, when it expires and whether it has a password. **End** stops a link working. You need a connection to make a send.

![The Send tab with a short text, opened once and expiring after one hour](/media/browser-extension/send-form.png)

![The new link, with Copy link, and the send listed under My sends](/media/browser-extension/send-link.png)

<video controls muted playsInline width="380" style={{ maxWidth: '100%' }} src="/media/browser-extension/send.webm"></video>

## Without a connection

The extension keeps an encrypted copy of your vault, updated every 15 minutes while it is unlocked and after every change you make. When your server cannot be reached, you can still unlock, browse and fill from that copy. Changes, folders and sends need the connection back.

## Signed out

When Keepiq refuses your app password, because it was revoked or changed in Nextcloud, the extension signs the account out and deletes what it kept of it. Create a new app password in Nextcloud and enter it in the popup to sign in again. **Settings → This account** can also log out on purpose, which deletes the app password in Nextcloud. **Disconnect** removes the account from the extension.

## Settings

- **This account:** the idle lock time, a PIN, fingerprint or face unlock, log out, lock all accounts, log out of all accounts, disconnect.
- **Autofill:** the save and update offers, password suggestions in sign-up fields, the sites never to save on, the clipboard delay, and the keyboard shortcut.
- **New items:** the type a new item starts with.
- **Appearance:** light, dark, or the same as your system.
- **Keepiq on the web:** import and export, notifications and your master password are managed in Keepiq on your Nextcloud.
- **About:** the version of the extension and of Keepiq on your server, and the third-party notices.

See also [the permissions the extension asks for](permissions.md) and [the privacy policy](privacy.md).
