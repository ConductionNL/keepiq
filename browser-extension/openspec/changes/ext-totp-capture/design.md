## Context

By the time this change lands, the earlier changes provide:
- `ItemForm` with the add, edit and clone flows, `item.fetch`, `item.create` and `item.update`, the form's record of what it opened, and the write errors (ext-vault-edit, ext-share-sync);
- `shareSave()` with copies and partner shares, `lookUp()` and `verifiedPartners()` (ext-share-sync);
- `src/totp/totp.ts` with `parseTotpSeed()`, and a login's code in the detail view, the inline list and the context menu from its `totp` field (ext-totp-autofill);
- the `totp` and `passkeys` ciphertext of each row in the snapshot (ext-totp-autofill).

The web app's `login-one-time-codes` spec defines the field: an entered or QR-read key, the seed never shown after saving, Replace and Remove, Remove only on another type, `''` to remove and no `totp` to keep. Its `shareSave.js` always sends a partner the whole value with `totp` and `passkeys`, null where the partner does not store them, and refuses a change that gives the login a field such a partner cannot store.

No web standard marks a two-factor setup page: there is no attribute or event for it, and `autocomplete="one-time-code"` only marks where a code is typed. The QR code's `otpauth://totp/` text (Google's Key URI Format) is the one convention, and it is mostly only in the pixels.

## Goals / Non-Goals

**Goals:**
- A user on a site's two-factor setup page saves the site's key to a login without leaving the popup.
- A saved key can be replaced and removed in the extension.
- Every copy and partner share of a shared login gets the change, or nothing is saved.

**Non-Goals:**
- Noticing a setup page by itself (proposal § Out of scope).
- Picking an image file, HOTP and Steam codes, editing `passkeys`.

## Decisions

- **Scan by picture, not by reading the page.** `browser.tabs.captureVisibleTab()` from the popup takes in `<img>`, `<canvas>`, `<svg>` and CSS backgrounds alike. A content script cannot read a cross-origin image's pixels, would need a decoder per element kind, and would hand the seed through a hostile page's frame.
- **`activeTab`, not `<all_urls>`.** Opening the toolbar popup grants it for the tab under it on Chrome and on Firefox 126 and later; it adds no install prompt. Firefox 109 to 125 need `<all_urls>` for the capture, which this change does not request: there the scan fails with the paste hint.
- **Decode in the popup.** The key ends up in the form, which is popup memory, either way, and the popup has a DOM for `createImageBitmap` and a canvas. jsQR 1.4.0, the web app's version, is loaded with a dynamic `import()` on the first scan, so it stays out of the popup's first chunk. The picture is scaled to at most 2048 pixels on its longer side before decoding: a high-density screen gives pictures of 8 million pixels and more, and a setup QR code stays readable at that size. jsQR runs with `inversionAttempts: 'attemptBoth'` for dark themes.
- **The picture lives only for the decode.** `scanVisibleTab()` returns only the link or a failure code; the data URL, the bitmap and the pixels go out of scope when it returns. Nothing of it is logged.
- **One QR code per picture.** jsQR reports the first it finds. A page with two codes is rare on a setup page; the user can scroll one out of view.
- **The key module is shared.** `src/totp/authenticator-key.ts` ports `normaliseAuthenticatorKey`, `authenticatorKeyError` (as `isAuthenticatorKey`) and `otpauthFromQrText`: a link is trimmed, a bare secret loses its whitespace and is upper-cased, and a key is valid when `parseTotpSeed()` reads it. The popup validates for the message, the background again before it encrypts, since it writes.
- **The seed never goes to the edit form.** `item.fetch` answers `totpSaved: true` when the row's `totp` is non-empty ciphertext and decrypts nothing for it. The form's key field starts empty; `totp` in the patch is a new seed, `''` removes the saved one, and its absence keeps it.
- **A key is entered only on a Login.** `item.create` refuses `totp` on another type with `invalid`; `item.update` refuses a non-empty `totp` unless the cached row's type is `login`, and takes `''` on any type. A standalone `totp` item keeps its own Secret field in `key` (ext-vault-edit § Composite types).
- **`totp` is a value field of share-save.** It joins `key`, `login` and `additionalFields`: a patch with it goes through share-save, and each copy carries it with the patch's null-ness, `''` for a removed key. `passkeys` is not a form field here; ext-passkey-provider adds it to the patch.
- **Partner shares get the whole value with `totp` and `passkeys`.** Today the extension sends a partner `key`, `login` and `additionalFields` only, and the server clears a field left out when the partner's certificate changed, so a password change could drop a partner's code. `sealWhole()` now adds `totp` and `passkeys` for a partner whose `valueFields` lists each, null for one that does not. Their plaintext comes from the typed patch, else from the ciphertext the form's own fetch read, as for the other fields.
- **The record holds `totp` and `passkeys` though the form does not show them.** The rule that a partner gets only what the form showed in full exists so the user saw what is sent. The form shows that a key is saved and never the seed or a private key, by design; the ciphertext is still checked against the vault cache (`holds()`), so the server cannot swap in another item's. `recordOpened()` always records both, and `shownInFull()` is not asked for them.
- **Adding a key a partner cannot store is refused before anything is written.** As in the web app: when the patch sets a non-empty `totp`, the stored row held none, and a required partner's `valueFields` lacks `totp`, the save fails with `partner_upgrade_required` naming those partners. A replaced or removed key reaches such a partner as null, since it never held one. The server's own 409 `partner_upgrade_required` maps to the same error, with its `requiredVersion`.
- **`valueFields` is kept with the verified certificate.** `lookUp()` reads it, an array of strings or else empty, and `verifiedPartners()` keeps it for the certificate's 5 minutes, so a partner that upgraded counts within that time.
- **Clone sends nothing of the key.** `item.fetch` for a clone answers `totpSaved: false` and `totpLeftOut: true`, and the form says the key is not cloned when the original had one; the clone form can take a new key.
- **The Type select and Default item type drop `totp`,** keeping it only to show an existing item's type, as `TypeSelect` already does for `passkey`. A stored default of `totp` reads as Login.
- **One trash button instead of the web app's Replace and Remove.** Replacing a key means removing the old one, so on a Login the trash brings back the input: a new key replaces the saved one, an empty input removes it. The scan is an icon button in the input's box, like the password's generate button.
- **Focus.** The trash moves focus to the key input, or to Keep the saved key where there is no input, and Keep the saved key back to the trash, so focus never falls to the page. A scan result is announced in a `role="status"` line; a scan failure is the field's error, linked with `aria-describedby`.

## Lock audit

The form is only reachable unlocked; a lock closes it and drops the draft (ext-vault-edit).

| Surface or payload | While locked |
| --- | --- |
| Authenticator key field, Scan QR code on page | Not reachable; the popup shows the unlock view |
| `item.fetch`, `item.create`, `item.update` | Refused, nothing decrypted or encrypted |
| Form record with `totp` and `passkeys` ciphertext | Cleared on lock with the rest of the record (ext-share-sync) |
| `storage.local`, `storage.session` | `totp` only as ciphertext, in the snapshot and the form record |

## Storage

| Datum | Lives in | Cleared by |
| --- | --- | --- |
| The picture of the tab, its bitmap and pixels | Popup memory | `scanVisibleTab()` returning |
| The typed or scanned key | Popup memory, in the form draft | Save, the form closing, the popup closing |
| The key in plain while saving | Background memory | The end of the save handler |
| `totp` and `passkeys` ciphertext of the opened row | The form record in `storage.session` | A successful save, lock, logout (ext-share-sync) |
| A partner's `valueFields` | Background memory, with its verified certificate | After 5 minutes, a failed save (ext-share-sync) |
| `totpSaved` | Popup memory | The form closing |

## Module layout

- `src/totp/authenticator-key.ts` (new): `normaliseAuthenticatorKey()`, `isAuthenticatorKey()`, `otpauthFromQrText()`; codes and booleans, the popup words them.
- `src/totp/qr-scan.ts` (new): `scanVisibleTab(deps)`, with the capture, the pixel read and the decoder replaceable for tests.
- `entrypoints/popup/components/AuthenticatorKeyField.tsx` (new): the entry, the saved state with its trash button, the scan button and its messages.
- `entrypoints/popup/views/ItemForm.tsx`: the field on Login and the remove-only state on other types, the clone note, `totp` in the draft and the patch.
- `entrypoints/popup/components/TypeSelect.tsx`, `entrypoints/popup/views/settings/Vault.tsx`: no `totp` for new items.
- `src/messages.ts`: the contract below.
- `src/vault/write.ts`: `totp` on create and update with its checks, `totpSaved` on fetch, `totp` and `passkeys` in `holds()` and the form record.
- `src/vault/share-save.ts`: `totp` in the sparse copies, `totp` and `passkeys` in `sealWhole()`, the partner refusal; `src/vault/verified-partners.ts`: `valueFields`.
- `src/vault/write-error.ts` and the popup's write messages: `partner_upgrade_required`.
- `wxt.config.ts`: `activeTab`. `package.json`: `jsqr`.
- `locales/en.yml`, `locales/nl.yml`: every new text.
- `test-site/qr-website.html`: a setup-like page whose QR code holds `https://example.com/`, linked from `test-site/index.html`.

## Message contract

```ts
interface ItemDraft {
	// …as before
	/** A login's new authenticator key; absent or `''` for none. */
	totp?: string
}
/** `totp`: a new seed, `''` removes the saved one, absent keeps it. */
type ItemPatch = Partial<ItemDraft>
interface EditableItem extends ItemDraft {
	// …as before
	/** The row holds a seed; the seed itself is never sent to the popup. Always `false` for a clone. */
	totpSaved: boolean
	/** A clone of a row that holds a seed, for the form's note. */
	totpLeftOut?: true
}
/** `partners`: the partner shares that cannot store the key, as `partner_unreachable` names them; `requiredVersion` when the server named it. */
type WriteError = /* …as before */ | { code: 'partner_upgrade_required'; partners: Array<{ shareId: string; cloudId: string }>; requiredVersion?: string }
```

No new kind.

## Risks / Trade-offs

- [The picture holds whatever the tab shows, a page with personal data too] → It is taken only on the user's click, only of the tab under the popup, decoded in memory and dropped; nothing of it is sent or stored.
- [Firefox 109 to 125 cannot take the picture under `activeTab`] → The scan says to paste the key. Raising the minimum Firefox or asking for `<all_urls>` is not worth it for a paste away.
- [A QR code outside the visible part of the page is not found] → The message says to scroll it into view.
- [A setup page that times out the QR code while the popup is open] → The site's own flow; the key stays valid once saved.
- [A user saves the key but never confirms it on the site] → The site then has no two-factor sign-in and the login a key it does not use; The trash button takes it off. The detail view's code lets the user confirm right away.
- [A partner on an older Keepiq stops a change that adds a key] → As in the web app; the message names the partner, and the version when the server gave it.

## Open Questions

- Should a scan also fill the login's Website URL when the form's is empty and the link's issuer names the site? Provisional: no; the add form already takes the tab's URL.
