# Using the mobile apps

Keepiq for Android and Keepiq for iOS open your Keepiq vault on your phone. You search, copy, add and edit logins there. You also make passwords and share secrets with a Send link. The app opens your vault with your master password. Your Nextcloud only ever sees encrypted values.

Keepiq for Android is available as a preview. Keepiq for iOS is not available yet. The iOS screenshots on this page show where it is heading.

## Getting the app

### Android

Keepiq for Android needs Android 9 or later.

1. Open the [Keepiq mobile releases on GitHub](https://github.com/ConductionNL/keepiq/releases?q=mobile-v&expanded=true) on your phone.
2. Download the `keepiq-android-<version>.apk` file from the newest release.
3. Open the file. Android asks whether your browser may install apps. Allow it for this install.
4. Choose **Install**.

Each `mobile-v<version>-preview.<n>` release is a preview. It is signed with a preview key, not with the key of the coming store builds. When Keepiq arrives on Google Play or F-Droid, you uninstall the preview first. Your vault stays on your Nextcloud, so you only connect the phone again.

#### Checking the download

Each release lists the SHA-256 of the APK, and carries it as a `.sha256` file. On a computer, compare it with:

```
sha256sum keepiq-android-<version>.apk
```

You can also check who signed the app. Run `apksigner verify --print-certs` from the Android SDK on the APK. The preview signing certificate has this SHA-256 fingerprint:

```
89:31:3B:10:6E:7D:43:FA:14:1E:CB:0B:4D:1C:18:8D:77:D8:B3:E2:14:DB:A9:FB:4E:8D:D3:C2:67:4B:FC:35
```

apksigner prints it in lower case and without colons. Do not install an APK with another fingerprint.

### iOS

Keepiq for iOS is not available yet. It comes later, first through TestFlight and then in the App Store. It will need iOS 17 or later.

## Connecting to your Nextcloud

1. Open Keepiq and enter the address of your Nextcloud.
2. Choose **Sign in with your browser**. Your phone's browser opens your Nextcloud login page.
3. Sign in and grant access. Keepiq picks up the connection by itself.

![Connect to your Nextcloud, with the server address and the browser sign-in button](/media/mobile/android-01-connect.png)

Can't use the browser sign-in? Choose **Use an app password instead**. Create an app password in Nextcloud under **Settings**, then **Security**. Then enter your user name and that app password in Keepiq.

Keepiq only connects over https, so your app password never travels in clear. Your Nextcloud security settings list the phone as "Keepiq for Android" or "Keepiq for iOS". Revoke it there to cut the phone off.

Does your organisation require two-factor authentication? Then Keepiq asks you to set it up in Nextcloud first. Do that and choose **Check again**.

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <img src="/media/mobile/android-21-two-factor-required.png" width="240" alt="Unlock Keepiq says the organisation requires two-factor authentication, with a Check again button" />
</div>

Use **Connect another account** to add a second account, on the same server or another one.

## Unlocking

Enter your master password and choose **Unlock**. Your master password never leaves the phone.

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <img src="/media/mobile/android-03-unlock.png" width="240" alt="Android: Unlock Keepiq with the account name and the master password field" />
  <img src="/media/mobile/ios-unlock.png" width="240" alt="iOS: Unlock Keepiq with the account name and the master password field" />
</div>

### PIN and biometrics

Typing your master password each time is slow. Open **Unlock and account** from the settings button to make it faster:

- **Fingerprint or face** unlocks with your fingerprint, or with Face ID on an iPhone. Set up a fingerprint or face in the phone settings first. A new fingerprint on the phone switches it off. Then you unlock with your master password once.
- **PIN** unlocks with a short PIN. Five wrong PINs delete it, and then you need your master password.

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <img src="/media/mobile/android-06-settings-pin-set.png" width="240" alt="Unlock and account settings with the fingerprint or face switch, the PIN, the lock delay and the account" />
  <img src="/media/mobile/android-07-locked-pin.png" width="240" alt="Unlock Keepiq asks for the PIN, with a link to use the master password instead" />
</div>

### Auto-lock

Under **Lock after** you choose how long Keepiq stays open while you do not use it. Pick 1, 5, 15, 30, 60 or 240 minutes. Your organisation can set a maximum, and the screen tells you what it is. Keepiq for Android also locks when you turn the screen off.

The lock button at the top of the vault locks it at once. Locking forgets your vault key. Your logins stay on the phone in encrypted form only.

The same settings screen has **Disconnect this account**. It signs the phone out and revokes its app password. It also removes the vault copy from the phone.

## The vault

The vault lists your folders and your items. Type in the search field to find an item by name or address.

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <img src="/media/mobile/android-vault.png" width="240" alt="Android: the vault with the folders Personal and Work and five demo items" />
  <img src="/media/mobile/ios-vault.png" width="240" alt="iOS: the vault with the folders Personal and Work and the demo items" />
  <img src="/media/mobile/android-search.png" width="240" alt="Android: searching for bank finds the Bank demo item" />
  <img src="/media/mobile/ios-search.png" width="240" alt="iOS: searching for bank finds the Bank demo item" />
</div>

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <video controls muted playsInline width="240" style={{ maxWidth: '100%' }} src="/media/mobile/android-vault.mp4"></video>
  <video controls muted playsInline width="240" style={{ maxWidth: '100%' }} src="/media/mobile/ios-vault.mp4"></video>
</div>

### Opening an item

Tap an item to see its fields. **Show** reveals the password and **Hide** covers it again.

**Copy** puts a value on the clipboard. Keepiq clears it again after 60 seconds. Android 13 and later hide the copied value in the clipboard preview. On iOS the copy stays on the phone. It does not go to your other Apple devices.

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <img src="/media/mobile/android-item.png" width="240" alt="Android: the Webmail demo login with user name, password and address, each with Copy" />
  <img src="/media/mobile/ios-item.png" width="240" alt="iOS: the Webmail demo login, with the note Copied. Cleared in 60 seconds." />
</div>

### One-time codes

An item with an authenticator shows its current code. The circle counts down to the next one. Tap **Copy** to copy the code.

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <img src="/media/mobile/android-authenticator.png" width="240" alt="Android: the Authenticator demo item shows a six digit code and its countdown" />
  <img src="/media/mobile/ios-authenticator.png" width="240" alt="iOS: the Authenticator demo item shows a six digit code and its countdown" />
</div>

### Adding and editing

Tap **+** to add an item. Pick its type and fill in the fields. In an item, **Edit** changes it and **Move** puts it in another folder. **Move to trash** removes it. You can restore it from the trash in the web app.

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <img src="/media/mobile/android-new-login.png" width="240" alt="Android: the new Shop demo login with a generated password" />
  <img src="/media/mobile/ios-new-login.png" width="240" alt="iOS: the new Shop demo login with a generated password" />
</div>

### Generator

The **Generator** tab makes a password or a passphrase. Set the length and the kinds of characters you want. **Avoid look-alike characters** leaves out characters such as 0 and O. Your organisation's password policy sets the limits. Choose **Use this** to put it in the item you are editing.

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <img src="/media/mobile/android-generate.png" width="240" alt="Android: the generator with a 14 character password and its options" />
  <img src="/media/mobile/ios-generate.png" width="240" alt="iOS: the generator with a 14 character password and its options" />
</div>

### Send

A Send shares a secret through a link that expires. Open an item and choose **New Send**, or start one from the **Send** tab. When the link is ready, choose **Share** or **Copy link**. Anyone with the link can open the Send, so pass it on with care.

Open a Send link on an Android phone and Keepiq shows its content. Opening it uses one of its views.

<div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
  <img src="/media/mobile/android-send-link.png" width="240" alt="Android: the new Send is ready, with its link and the Share and Copy link buttons" />
  <img src="/media/mobile/ios-send-link.png" width="240" alt="iOS: the new Send is ready, with its link and the Share and Copy link buttons" />
  <img src="/media/mobile/android-send-opened.png" width="240" alt="Android: an opened Send shows the demo door code and says this was the last view" />
</div>

On iOS you cannot make a Send with a password yet. A Send link there opens in the browser.

## Offline

Keepiq for Android keeps an encrypted copy of your vault on the phone. Without a connection you can still open, search and copy. The vault shows when it last synced. Adding, editing and deleting need a connection, and Keepiq tells you so.

Keepiq for iOS does not keep an offline copy yet. It needs a connection.

## Coming next

- **System autofill.** Fill logins and one-time codes in other apps and in your browser.
- **Passkeys.** Sign in with the passkeys in your vault. This needs Android 14 or iOS 17.
- **Google Play and F-Droid.** Install and update Keepiq for Android from a store.
- **The App Store.** Keepiq for iOS, first through TestFlight.
