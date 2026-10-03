---
kind: code
---

# The rest of the browser extension

## Why

`clients-extension-generator-vault-send` built the core of the popup's Generator, Vault and Send tabs and listed what it left for follow-ups, measured against the specs of `ConductionNL/keepiq-extension` (`ext-generator`, `ext-vault-browse`, `ext-vault-edit`, `ext-send`). This change builds those follow-ups, in five steps that each land on their own.

## What Changes

1. **Generator and send.** Password, Passphrase and Username sub-tabs; character classes, minimum digits and symbols, ambiguous characters; usernames (random word, plus-addressed email, catch-all email, with the website name); colour-coded output; a history of 50 values that goes on lock; options remembered per account; a pick mode that hands a value to the item form; the generator from the lock screen and offline with the cached policy. Password-protected sends with the web app's Argon2id, bundled in the extension.
2. **Item detail and editing.** TOTP with countdown, websites, additional fields, notes, card and identity, passkey and metadata sections; clone, move, input limits and an unsaved-changes warning.
3. **Folder manager.** Create, rename and delete folders.
4. **Offline vault cache and sync.**
5. **Popup shell.** Bottom tab bar, pop-out window, last tab remembered, theme tokens.

## Where Keepiq's own specs win

Where the keepiq-extension specs follow Bitwarden and a Keepiq spec is stricter, Keepiq's spec applies and the difference is recorded: a generated password has at least 8 characters (`key-generator`, Bitwarden allows 5), a passphrase 4 to 12 words (`passphrase-generator`, Bitwarden 3 to 20), and symbols come from Keepiq's OWASP set.

## Capabilities

### Modified Capabilities

- `extension-generator`, `extension-send` (step 1); `extension-vault` (steps 2 and 3); new `extension-vault-sync` (step 4) and `extension-popup-shell` (step 5).
