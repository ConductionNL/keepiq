# Using the browser extension

The Keepiq browser extension opens your Keepiq vault from your browser's toolbar. You can find your logins, copy a user name, password or one-time code, and open the site. The extension opens your vault with your master password, in the browser. Your Nextcloud only ever sees encrypted values.

The extension is in development. You can browse, copy, add, edit, delete, generate passwords and send secrets today; filling in logins and saving them as you sign up come in later updates (see [Coming next](#coming-next)).

## Getting the extension

The extension runs on Chrome and other Chromium browsers, such as Edge and Brave, and on Firefox 109 or later. Your server needs Keepiq 0.3.12 or later; [Supported Keepiq versions](supported-keepiq-versions.md) lists which versions are supported and until when.

It is not in the Chrome Web Store or on Firefox Add-ons yet. Until it is, you build it from the Keepiq source:

1. Install [Node.js](https://nodejs.org). Keepiq is tested with version 24.
2. In the `browser-extension` folder of the Keepiq repository, run `npm install`, then `npm run build` for Chrome or `npm run build:firefox` for Firefox.
3. Load the build:
   - **Chrome:** open `chrome://extensions`, turn on **Developer mode**, choose **Load unpacked** and pick the `.output/chrome-mv3` folder.
   - **Firefox:** open `about:debugging#/runtime/this-firefox`, choose **Load Temporary Add-on…** and pick `.output/firefox-mv2/manifest.json`. Firefox removes a temporary add-on when it restarts.

Pin the Keepiq icon to the toolbar so it is one click away.

## Adding an account

Before you start, open the Keepiq web app once and set your master password.

1. Click the Keepiq icon. The extension shows **Add account**.
2. Enter your **Server URL**. The address of any Nextcloud page works; the extension keeps only the server part.
3. Enter your Nextcloud **Username**.
4. Enter an **App password**. Create one in Nextcloud under **Settings**, then **Security**. Once you have entered the server, the form links straight to that page. Never enter your Nextcloud login password here.
5. Choose **Add account**. The browser asks whether Keepiq may reach your server. Allow it.

The extension checks the details with your server before it stores anything. When something is wrong, it says what:

| Message | What to do |
| --- | --- |
| Enter a valid server URL | Check the address you typed. |
| Use https for this server | The extension only connects over https. |
| Keepiq needs permission to reach *server* | You declined the browser's question. Choose **Add account** again and allow it. |
| Could not reach *server* | Check the address and your connection. |
| *server* does not look like a Nextcloud server | Check the address. |
| *server* is unavailable right now, try again later | The server had a problem. Try again later. |
| Wrong username or app password | Check both, or create a new app password. |
| Keepiq is not installed on *server* | Ask your administrator to install Keepiq. |
| Keepiq on *server* is too old for this extension | Ask your administrator to update Keepiq to the version the message names. |
| Open the Keepiq web app once and set a master password, then try again | Set your master password in the web app first. |
| This account is already added | This user on this server is already in the extension. |
| Maximum of 5 accounts reached | Log out of an account you no longer need first. |

You can add up to five accounts, on the same server or on different ones. Your Nextcloud security settings list each app password you made. Revoke it there to cut the browser off.

## Unlocking

Enter your master password and choose **Unlock**. Your master password never leaves the browser, and the extension does not store it.

**Invalid master password** means the password did not open your vault. When you changed your master password in the web app, the extension notices and takes the new one.

The extension also refuses to unlock when Keepiq on your server is older than it supports, or when your vault key can't be read; the message says so. Ask your administrator in both cases.

Does your organisation require two-factor authentication? Then the extension refuses to unlock until you set up a second factor in Nextcloud under **Settings**, then **Security**.

### Locking

The vault locks after 15 minutes without use, and when you restart the browser. Locking forgets your vault key; your vault stays in the browser in encrypted form only, and you unlock it with your master password again.

To lock straight away, click your avatar at the top right and choose **Lock**, or **Lock all** for every account.

## Accounts

Click your avatar at the top right to see your accounts. Each one shows whether it is **Unlocked**, **Locked** or **Logged out**. Click an account to switch to it; the others stay as they are.

- **Lock** forgets the vault key. Your account stays.
- **Log out** removes the account from the browser, with its vault copy and app password. The extension asks first.
- **Log out all** removes every account.

When your app password stops working, for example because you revoked it, the account shows **Logged out**. Open it and enter a new app password under **Log in again**. Your settings stay.

## The vault

The **Vault** tab lists your items alphabetically. Above the list you can narrow it down:

- **Search** finds items by name or web address.
- The folder menu shows one folder, or **No folder** for items outside a folder.
- The type chips show only logins, cards, identities, notes, authenticators or passkeys. **More** has the other types.

**Autofill suggestions** at the top lists the items for the site in your current tab. It matches on the site's domain, so `login.example.com` also finds an item saved for `example.com`.

Each item has three actions:

- **Launch** opens the item's website in a new tab.
- **Copy** copies the user name, the password or the current one-time code. Copied values are cleared from the clipboard after 30 seconds.
- **More** has **View**, **Edit**, **Clone**, **Move to folder** and **Delete**, and on a login **Send** (see [Send](#send)).

The extension shows a type icon for each item. It does not load website icons, so no site learns which accounts you have.

### Opening an item

Click an item to see its details. Passwords and other secret fields are hidden; the eye button shows them and the copy button copies them. An item shared with you as use-only shows only its user name and website. The back arrow takes you to the list as you left it.

An authenticator item shows its current code and counts down to the next one.

A **blocked** item cannot be opened. Its key was revoked or compromised. The item says why and links to the web app, where you can see what to do.

### Adding an item

**New** at the top of the vault, next to the pop-out button, opens a new item. It starts with the type you chose as your default in Keepiq, or **Login**, and with the address of the site in your current tab. Pick a type, give the item a name and fill in what you need: a login has a user name and password, a card and an identity have their own fields, and an authenticator takes the setup key or `otpauth://` link the site gave you. **Additional fields** holds anything else, such as a PIN, and **Notes** holds free text. **Save** opens the new item.

Passkeys are not added here; they are made when you sign up on a website with one.

### Editing an item

**Edit** on an item, or in its **More** menu, opens it with the values from your server, not from the copy in your browser, so nothing someone else changed meanwhile is lost. Only what you changed is saved. When the item was changed elsewhere while you were editing, the extension says so and keeps what you typed; go back and edit it again.

**Clone** opens a new item with the same values and " - Clone" after the name. **Move to folder** moves the item without opening it. Leaving a form with changes asks whether to discard them; closing the extension drops them.

A passkey item only lets you change its name, folder and website.

An item someone shared with you keeps the limits they set. One shared for signing in only shows its user name but never its password or fields, and can't be edited, cloned or moved. One shared from another organisation can only be moved, one shared to view only can't be edited, and one whose access ends on a date can't be cloned.

When you change the password, user name or fields of an item you own and shared, the extension saves it for everyone it is shared with in one go, directly, through a group or team folder, or at a partner organisation, before it says **Item saved**. Either everyone gets the change or nothing is saved: when a save fails, the form stays open with your change and the reason, and you save again. A new name, website or folder stays your own.

- **Team editor or delegate:** when you edit an item through a team folder where you may write, or as the owner's delegate, the owner's item and the copies in your own organisation are updated too. Recipients at a partner organisation get it with the owner's next save, and the item says so.
- **Partner organisation can't be reached:** nothing is saved, and the form names the recipients there. **Save without them** saves anyway and pauses sharing with them until the item is shared with them again, and the item says so.
- **Partner certificate can't be verified, or their organisation no longer accepts the share:** sharing with them is paused, the save goes through, and the item says so; the Keepiq web app shows the share.
- **Partner certificate isn't valid by your computer's clock:** nothing is saved and nothing is paused, since your clock may be wrong; check your computer's date and time, or make the change in the Keepiq web app.
- **Your server can't share with partner organisations:** an item shared with one can't be saved in the extension; ask your administrator.
- **Part of the item the form doesn't show:** for an item shared with a partner organisation, such as a card with a detail the form has no field for, the extension can't confirm they would get the item exactly as you saw it, so nothing is saved; make the change in the Keepiq web app.
- **Shared with you to view only:** Edit is unavailable on the item, with the reason; from the list's More menu it opens and says why. You can still clone the item or move it to one of your folders. That holds for a direct share, a group share and a team folder where you're a Viewer.

### Deleting an item

**Delete**, the red trash can at the bottom right of an item, asks first, then moves the item to Keepiq's trash, which also stops sharing it. You can restore it from the trash in the Keepiq web app until your organisation's retention ends, 30 days unless an admin changed it; after that it is gone for good.

### Folders

Open **Settings**, **Vault**, **Folders** to add, rename and delete folders. Folders can hold folders; **Add folder** in a folder's menu makes one inside it. A folder name cannot contain `/`. You can also add a folder while choosing one for an item.

Deleting a folder that holds items asks whether to delete them too or move them to the folder above. The count it shows includes archived items and items in the trash, which go the same way. A folder with folders inside asks, for each of them, whether to keep it (it moves up a level), move its items up, or delete it.

Folder names are not encrypted in Keepiq, so pick names you don't mind your server seeing.

### Pop out

The pop-out button at the top opens the extension in its own window, which stays open while you work in the browser. Suggestions keep following the tab you opened it from.

## Generator

The **Generator** tab makes a new value as soon as it opens, and again whenever you change an option. **Regenerate** makes another one and **Copy** copies it. The value is made in your browser, the same way the Keepiq web app makes it, and is sent nowhere. Your options are remembered per account.

- **Password:** 14 characters by default, 8 to 128. Choose upper case, lower case, numbers and special characters, and how many numbers and special characters at least. **Avoid ambiguous characters** leaves out characters that are easy to confuse, such as `l` and `1`.
- **Passphrase:** five words by default, 4 to 12, with a separator of your choice, optionally capitalised and with a number.
- **Username:** a random word with four digits, or an email address. **Plus addressed email** adds a random tag or the current site's name to your own address (`you+shop.example.com@example.org`); **Catch-all email** makes an address at a domain you own.

When your organisation has a password policy, the controls it decides are locked and say **Set by your organisation**, and passphrases are made long and varied enough to meet it. When your organisation turned passphrases off, the Passphrase tab says so.

The History button lists the last 50 values you regenerated, copied or used, or left on a sub-tab, with **Clear** to empty it. The list is gone when the vault locks or the browser closes.

While adding or editing an item, the button next to **Password** or **Username** opens the generator; **Use this password** or **Use this username** puts the value in the form.

The generator also works while the vault is locked and while you're offline: choose **Generate a password** on the lock screen.

## Send

The **Send** tab shares a secret with anyone through a link that stops working after a number of views or a time. The secret is encrypted in your browser; your server only stores the encrypted text. The recipient opens the link in Keepiq's web page and needs no account.

- **New send:** the **New** button at the top. Choose **Text** for any text, or **Credential** for a user name and password, which the recipient gets as two lines labelled `Username:` and `Password:`.
- **Max views:** 1 by default, up to 100. The send is deleted after its last view.
- **Expiry:** 1 hour, 1, 2, 3, 7 or 30 days, or **Custom** hours up to 720 (30 days); 7 days by default.
- **Access password:** optional. The recipient must type it; after five wrong tries the send is deleted. Share it separately from the link.
- **Create** shows the link with **Copy link**. Copy it now: a link without a password can't be shown again once you close the extension, since only your browser ever had its key. A password send's link can be copied from the list at any time.
- **The list** shows your sends, newest first, with the views left and when each expires, including sends made in the web app. An expired send stays listed until Keepiq's hourly cleanup runs.
- **Remove** asks to confirm, then deletes the send, so its link stops working.
- **From an item:** **Send** in a login's **More** menu opens a credential send with its user name and password. It is unavailable for an item shared with you for signing in only, a blocked item, a share whose access ends on a date, and while you're offline.

Sending needs the vault unlocked and a connection to your server.

## Offline

The extension keeps an encrypted copy of your vault in the browser. Without a connection you can still unlock, search, open and copy. The vault shows **Offline** and when it last synced; **Sync now** tries again. Adding, changing and deleting, and sending, need the connection, so they wait until it is back.

The extension checks your server for changes every 15 minutes while the vault is unlocked, and when you open it after a while. When your master password or vault key changed in Keepiq, the extension locks and asks for your current master password.

## Theme

The extension follows your browser's light or dark setting, which on most browsers follows your computer's. To pick one yourself, open **Settings**, **Appearance** and choose **Light** or **Dark** under **Theme**; **System default** follows the browser again. The choice applies to every account and stays when you log out.

## Language

The extension speaks your browser's language. It is available in English and Dutch; in any other language it uses English. To change it, change your browser's language. Item names, folder names and item types come from your vault and stay as they are.

## Privacy

The extension talks to your own Nextcloud and sends nothing to Conduction. It has no analytics. The [browser extension privacy policy](privacy.md) lists what it sends where and what it keeps in your browser.

## Coming next

- **Fill in logins** on websites, and save new logins as you sign up.
- **Passkeys.** Sign in to websites with the passkeys in your vault.
- **More settings:** the lock delay, a PIN and when the clipboard is cleared.
- **Chrome Web Store and Firefox Add-ons.** Install and update the extension from a store.
