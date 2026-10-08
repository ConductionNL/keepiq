# Using the browser extension

The Keepiq browser extension opens your Keepiq vault from your browser's toolbar. You can find your logins, copy a user name, password or one-time code, and open the site. The extension opens your vault with your master password, in the browser. Your Nextcloud only ever sees encrypted values.

The extension is in development. You can browse and copy today; filling in logins, saving new ones, the generator and Send come in later updates (see [Coming next](#coming-next)).

## Getting the extension

The extension runs on Chrome and other Chromium browsers, such as Edge and Brave, and on Firefox 109 or later.

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
| Open the Keepiq web app once and set a master password, then try again | Set your master password in the web app first. |
| This account is already added | This user on this server is already in the extension. |
| Maximum of 5 accounts reached | Log out of an account you no longer need first. |

You can add up to five accounts, on the same server or on different ones. Your Nextcloud security settings list each app password you made. Revoke it there to cut the browser off.

## Unlocking

Enter your master password and choose **Unlock**. Your master password never leaves the browser, and the extension does not store it.

**Invalid master password** means the password did not open your vault. When you changed your master password in the web app, the extension notices and takes the new one.

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
- **Copy** copies the user name, the password or the current one-time code.
- **More** has **View**. Its other actions, Edit, Clone, Move to folder and Delete, are shown but come in a later update.

The extension shows a type icon for each item. It does not load website icons, so no site learns which accounts you have.

### Opening an item

Click an item to see its details. Passwords and other secret fields are hidden; the eye button shows them and the copy button copies them. The back arrow takes you to the list as you left it.

An authenticator item shows its current code and counts down to the next one.

A **blocked** item cannot be opened. Its key was revoked or compromised. The item says why and links to the web app, where you can see what to do.

### Pop out

The pop-out button at the top opens the extension in its own window, which stays open while you work in the browser. Suggestions keep following the tab you opened it from.

## Offline

The extension keeps an encrypted copy of your vault in the browser. Without a connection you can still unlock, search, open and copy. The vault shows **Offline** and when it last synced; **Sync now** tries again.

The extension checks your server for changes every 15 minutes while the vault is unlocked, and when you open it after a while. When your master password or vault key changed in Keepiq, the extension locks and asks for your current master password.

## Privacy

The extension talks to your own Nextcloud and sends nothing to Conduction. It has no analytics. The [browser extension privacy policy](privacy.md) lists what it sends where and what it keeps in your browser.

## Coming next

- **Fill in logins** on websites, and save new logins as you sign up.
- **Passkeys.** Sign in to websites with the passkeys in your vault.
- **Adding and editing** items in the extension.
- **Generator and Send**, as in the web app.
- **Settings:** the lock delay, a PIN, clearing the clipboard and the theme.
- **Chrome Web Store and Firefox Add-ons.** Install and update the extension from a store.
