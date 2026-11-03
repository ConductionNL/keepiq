---
kind: code
---

# Generate keys and passphrases in the browser

## Why

Keepiq is zero-knowledge: the server stores ciphertext and never sees a secret's value. The key generator broke that. `src/dialogs/KeyGeneratorModal.vue` asked `POST /api/v1/generate-key` for a value, so every generated password existed in plain text on the server for one response, before the browser encrypted it. A server that is compromised, or simply logging request bodies, would see every generated password.

The browser extension also needs a generator (`clients-extension-generator`), and it cannot depend on a server round trip for a value it is about to fill into a page.

`health-passphrase-generator` (row `health-08`) planned a passphrase mode on the same server endpoint, and recorded in its D1: "if Keepiq moves all generation into the browser, it moves both modes together in its own change". This is that change, and it supersedes `health-passphrase-generator`.

## What Changes

- **Generation moves to the browser.** `src/generator/generator.js` generates passwords, regex-shaped keys and passphrases with `crypto.getRandomValues`, with the same options, policy clamp and refusals as the server's `KeyGeneratorService`. The web app and the browser extension both import it, as they already share `src/totp`.
- **The generator dialog stops calling the server.** `KeyGeneratorModal.vue` generates locally. `POST /api/v1/generate-key` is deprecated for the app's own use and kept for API clients.
- **Passphrases.** A Password or Passphrase switch in the dialog: 4 to 12 words from the EFF large word list (7,776 words), a separator, capitals and a digit. With the org policy on, a passphrase is extended to meet it.
- **An administrator can switch passphrases off.** `generator_allow_passphrase` (default on) in the org password policy section.

## Capabilities

### New Capabilities

- `passphrase-generator`: word-based passphrases, generated in the browser, bounded by the organisation password policy.

### Modified Capabilities

- `key-generator`: generation runs in the browser; the endpoint is deprecated.
- `org-password-policies`: the generator policy is applied by the browser generator; `generator_allow_passphrase` is added.

## Impact

- `src/generator/generator.js`, `src/generator/eff-large-wordlist.js` (CC BY 3.0 US, `LICENSES/CC-BY-3.0-US.txt`, `REUSE.toml`)
- `src/dialogs/KeyGeneratorModal.vue`, `src/components/settings/OrgPasswordPolicySection.vue`
- `lib/Service/PasswordPolicyService.php`, `lib/Controller/KeyGeneratorController.php` (deprecation note)
- Matrix row `health-08`.
