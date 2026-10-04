# Using the browser extension

The Keepiq extension fills your logins, one-time codes and passkeys from your Keepiq vault, in Chrome, Edge and Firefox. It opens your vault inside the extension with your master password. The server only ever sees encrypted values.

## Connecting your account

1. In Nextcloud, open **Settings → Security** and create an app password for the extension.
2. Click the Keepiq button in the browser toolbar.
3. Enter the address of your Nextcloud, your user name and the app password, and choose **Connect**.

You can paste any Nextcloud address, for example one you copied from a Keepiq page: the extension keeps only the server part. It needs an https address, so your app password is never sent in clear. Only a server on your own computer may use http.

You can connect up to five accounts, on one or more servers. Pick the account you want in the bar at the top of the popup.

## Unlocking

Enter your master password and press Enter. **Show** lets you check what you typed. Your master password never leaves the extension.

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

## Filling logins

Open the popup on a login page and pick a login under **This site**. Keepiq fills it only in the parts of the page that belong to that site, not in an advert or a frame from somewhere else.

You can also:

- right-click a form field and choose **Fill a login with Keepiq**;
- press **Ctrl+Shift+L** (**Command+Shift+L** on a Mac). You can change the shortcut in your browser's extension settings.

When the site has one login, it fills at once. When it has several, or the vault is locked, the popup opens so you can choose. When a login was saved for a secure (https) site and the page is not secure, Keepiq asks before it fills.

After a login, Keepiq fills the one-time code on the next step when it can, or copies it for you to paste.

## Saving logins

When you sign in with a login Keepiq does not know, a bar asks whether to save it. You can save it, choose **Not now**, or choose **Never for this site**. When you change a password, Keepiq offers to update the saved login. The bar comes back on the next page of the same site if the login page moves you on.

From the popup you can also pick the folder a new login goes into. **Settings → Autofill** switches the save and update offers off, and lists the sites you said never to.

## Copying

Everything you copy from the extension is cleared from the clipboard after 30 seconds, also when the popup has closed. Change the delay in **Settings → Autofill**, or switch it off.

## Sends

A send shares text, or a username and password, through a link. Choose how often it may be opened and when it expires. With an optional password, share the password another way: the link alone then cannot open it. **My sends** shows each send with how often it was opened, when it expires and whether it has a password. **End** stops a link working. You need a connection to make a send.

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
