# Design: a passphrase mode for the key generator

## Context

At development `4c214a9d`:

- `appinfo/routes.php:71` routes `POST /api/v1/generate-key` to `KeyGeneratorController::generate()` (`lib/Controller/KeyGeneratorController.php:80-100`, parameters `length`, `includeSpecialCharacters`, `excludedCharacters`, `regex`).
- `lib/Service/KeyGeneratorService.php:39-88` holds the limits (length 8 to 128) and character sets; `:110-125` `policy()` reads the organisation policy (`policy_enabled`, `generator_min_length`, `generator_require_upper`, `_lower`, `_digit`, `_symbol`); `:179-195` `generate()` chooses regex or charset mode.
- `openspec/specs/org-password-policies/spec.md:36-44` "Generator Locked to Policy": the server clamps the generator so it never emits a value below the floor or missing a required class; this enforcement is server-authoritative.
- `openspec/specs/key-generator/spec.md:16-29` lists the configuration fields; `:107-113` the frontend integration through the generator modal.
- `src/dialogs/KeyGeneratorModal.vue:143-250` calls the endpoint and emits the value to the create or edit dialog.
- `src/components/settings/OrgPasswordPolicySection.vue` edits the policy.

## Goals / Non-Goals

**Goals**
- Memorable passphrases of known strength, from the same generator and under the same policy.

**Non-Goals**
- Word lists in other languages. A Dutch list is added only when a list of at least 7,776 words under a licence compatible with EUPL-1.2 and REUSE is found; this change does not choose one.
- Generating in the browser. See D1.

## Decisions

**D1. Keep generation on the server, where the generator and the policy clamp already are.** The `org-password-policies` spec makes policy enforcement server-authoritative, and the existing generator is server-side by the `key-generator` spec. A second, browser-side generator would split that. Alternative: a browser-only passphrase generator. Rejected for this change; if Keepiq moves all generation into the browser, it moves both modes together in its own change.

**D2. The EFF large word list, uniform draws.** 7,776 words, about 12.9 bits each, drawn with `random_int()` over the list index. The minimum of 4 words gives about 51.7 bits before any capital, digit or separator. The list is shipped as a resource with its CC BY 3.0 US licence recorded in `LICENSES/` and `REUSE.toml`.

**D3. The policy is met by extending, not by rejecting.** When the policy is on: add words until the length floor is met; capitalise when an upper-case class is required; add one digit when a digit is required; use a random symbol from the OWASP set as separator when a symbol is required. The result is still checked by the existing class checks. `generator_allow_passphrase` (default true) lets an administrator switch the mode off; the endpoint then answers 400 for `mode: "passphrase"`.

**D4. The API keeps its old default.** Without `mode`, the endpoint behaves as today (`mode: "password"`).

## Security and zero-knowledge

A generated passphrase exists on the server for one response, exactly as a generated password does today, and is neither stored nor logged. It is then encrypted in the browser like any typed value when the secret is saved. The strength shown is the existing client-side zxcvbn meter.

## Risks / Trade-offs

- A four-word passphrase is weaker than a 20-character random string. The dialog shows the strength and the default is five words.
- The word list adds about 60 KB to the app package.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. No fixture is needed.

## Migration

None. One new app config key with a default.
