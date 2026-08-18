---
kind: code
depends_on:
  # Not a spec slug: PR #265 (feature/120/application-secret-request-creation)
  # carries Version000033, which gives `doriath_secrets.key` an empty-string
  # default. Without it every keyless placeholder insert dies on the NOT NULL
  # constraint, because the Nextcloud Entity setter never marks an unchanged
  # field dirty and QBMapper omits the column. See Impact → Ordering.
  - pr-265-application-secret-request-creation
---

## Why

The spec already says Doriath creates the placeholder. The implementation makes the human do it, and then refuses to let them:

- `openspec/specs/secret-requests/spec.md:12` — "Doriath creates an unfilled Secret (**a placeholder with no key value**) and generates a fill-in link"
- `:46` — "THEN the system **MUST** create an unfilled Secret and a SecretRequest with a unique token"
- `:222` — "Each new SecretRequest creates its **own** unfilled Secret; a re-request targets an existing Secret (no new Secret created)"

What ships instead: the only entry point is `SecretDetail.vue`, so a requester must already be standing on a Secret; `SecretRequestCreateDialog.vue:110` declares `secret: { type: Object, required: true }`; and `SecretService::create()` (`lib/Service/SecretService.php:232`) throws `A secret requires a name and a key` on an empty key. To ask a colleague for a credential you must first invent a value for the very thing you do not have. That is not a rough edge in the flow — it is a MUST being violated, and the `secrets` spec never asked for that key constraint in the first place.

The human path is the outlier, not the standard. The machine surface added in #120 already complies: `ApplicationSecretRequestService::createForApplicationVault()` creates its own shell via `allowUnfilled: true`, precisely because an unattended application "has nothing to point at yet". A person has nothing to point at either.

The inversion has a second consequence, which is how it was noticed. Because a fresh request must aim at an existing — possibly already-populated — Secret, a requester can ask a recipient to overwrite a good value, and `:147` ("All fields listed in `requested_fields` MUST be submitted with a non-empty value") means the recipient cannot decline: the fill screen demands every requested field. Under the spec's model that situation cannot arise, because a fresh request targets a Secret that is empty by construction. One root cause, two symptoms.

## What Changes

- **A request no longer requires a pre-existing Secret.** A fresh `SecretRequest` creates its own unfilled Secret — name, optional folder, optional expiry, requested fields — as `:46` mandates. The requester never types a placeholder value.
- **A vault-level entry point.** "Ask someone for a credential" becomes reachable from the secret list, not only from inside a Secret's detail view. The current entry point remains valid for re-requests.
- **The user create path gains the unfilled seam the machine path already has.** `SecretService::create()` accepts a keyless Secret when, and only when, it is being created as a request placeholder — the same explicit opt-in as `createByApplication(allowUnfilled: true)`, never a relaxed default.
- **Re-request becomes a distinct action rather than a flag.** It stays the only flow that targets an existing Secret, matching `:222`. `is_re_request` remains the wire representation; what changes is that the user chooses "ask for new values for this credential" from the Secret, while "ask someone for a credential" starts from the vault.
- **A fresh request MUST NOT pre-select fields that already hold a value.** Enforced at creation, where the requester is authorised to know what is filled — never surfaced on the fill screen, which would leak vault metadata to an anonymous recipient. A requester may still deliberately tick such a field; the point is that it is a decision, not a default.
- **Internal signature change (not user-facing, not an API break):** `SecretRequestCreateDialog`'s `secret` prop becomes optional. No published HTTP contract changes shape — `secret_id` becomes optional on the existing user-side create endpoint.

## Capabilities

### New Capabilities

None. This change adds no capability; it brings the human surface into line with requirements that already exist, plus two requirement additions noted below. Inventing a capability name here would misrepresent a correction as a feature.

### Modified Capabilities

- `secret-requests`: two requirement additions. First, `Create Secret Request` gains an explicit statement that a fresh request is created WITHOUT naming an existing Secret and that the system creates the placeholder — today this is only implied by the Purpose section and an acceptance criterion, which is how the implementation drifted without failing a scenario. Second, a new requirement that a fresh request must not pre-select already-filled fields, and that filled-ness must not be exposed to the fill recipient.
- `secrets`: an explicit statement that a Secret MAY exist with no key value while it is an unfilled request placeholder. The spec never required a non-empty key; the implementation invented that constraint, and without stating the exception it will be re-introduced by the next person who reads `create()` and decides a Secret without a key looks like a bug.

## Impact

**Backend**
- `lib/Service/SecretService.php` — `create()` gains the `allowUnfilled` opt-in (mirrors `createByApplication`).
- `lib/Service/SecretRequestService.php` — creates the placeholder for a fresh user request; `secretId` becomes derived rather than supplied.
- `lib/Controller/SecretRequestController.php` — `secret_id` optional on create; still mandatory for a re-request.

**Frontend**
- `src/dialogs/SecretRequestCreateDialog.vue` — `secret` optional; name/folder inputs when there is no target Secret; already-filled fields unticked and labelled.
- `src/views/SecretList.vue` — the new vault-level entry point.
- `src/views/SecretDetail.vue` — its action becomes explicitly "re-request".

**Not affected**
- No DB migration of its own (see Ordering).
- No change to the encryption boundary. Values stay client-encrypted under the requester's certificate per ADR-003; the placeholder is keyless, not plaintext.
- The machine surface (`/api/v1/app/secret-requests`) is already compliant and is left alone.

**Ordering** — this change must land after PR #265, which carries `Version000033` (empty-string default on `doriath_secrets.key`). Merged first, no migration is needed here. If this change is implemented before #265 merges, it must carry that migration itself or every placeholder insert will fail the NOT NULL constraint.

**Revoke** — `:189` already requires the placeholder to be deleted on revoke. Once placeholders exist on the human path this stops being theoretical, so it needs a test rather than new behaviour.
