## Context

`openspec/specs/secret-requests/spec.md` requires the system to create an unfilled Secret when a fresh request is made (`:12`, `:46`, `:222`). The human surface does the opposite: the only entry point is `SecretDetail.vue`, `SecretRequestCreateDialog.vue:110` requires a `secret` prop, and `SecretService::create()` (`lib/Service/SecretService.php:232`) rejects an empty key. A requester must therefore create a Secret and invent a value for the credential they are about to ask someone else for.

The machine surface added in #120 already behaves correctly — `ApplicationSecretRequestService::createForApplicationVault()` creates its own shell with `allowUnfilled: true`. So the seam this change needs already exists and is already tested; it simply was never extended to people.

Two facts from the existing spec shape the design more than anything else:

- **`:147`** — "All fields listed in `requested_fields` MUST be submitted with a non-empty value." The recipient cannot skip a requested field. So including an already-filled field in a fresh request does not merely invite an overwrite, it compels one.
- **`:77`** — "the system CANNOT verify that the requested additional members were actually filled, because it never decrypts the blob (ADR-003) … Per-member completeness is a **client-side concern**." The server can see whether `key`, `login` and `url` hold values, but for members of the `additional_fields` blob it can see only that a blob exists. Only the requester's unlocked browser can tell which members are populated.

## Goals / Non-Goals

**Goals:**
- A fresh request creates its own unfilled Secret; the requester never types a placeholder value.
- Asking for a credential is reachable without first owning a Secret.
- Re-request remains the only flow that targets an existing Secret.
- A fresh request does not default to asking for values that already exist.
- Keylessness stays an exception with a stated boundary, not a general relaxation.

**Non-Goals:**
- Redesigning the fill screen itself. Its field rendering is unchanged; this change alters what ends up in `requested_fields`, not how the recipient sees them.
- Touching the machine surface. `/api/v1/app/secret-requests` is already compliant.
- Changing the encryption boundary. Values remain client-encrypted under the requester's certificate (ADR-003); a placeholder is keyless, not plaintext.
- Reworking notifications, expiry semantics, or the `is_re_request` wire field.
- Retroactively repairing Secrets that users created with a dummy key as a workaround. They are indistinguishable from real secrets; migrating them would be guesswork.

## Decisions

### A Secret may be keyless only while a pending request targets it

A key remains required for an ordinary Secret — a Secret with no request tied to it and no value is just litter, and the current UI is right to demand one. The exception is narrow and stated as an invariant rather than a creation-time convenience:

> A Secret MAY have an empty `key` only while a pending `SecretRequest` targets it.

Enforcement is asymmetric on purpose, and the reason is worth recording:

- **At creation**, the caller asserts intent through an explicit `allowUnfilled` parameter — the same shape as `createByApplication(allowUnfilled: true)`. Default stays `false`, so an ordinary create still fails on an empty key and a future caller cannot inherit the exception by accident.
- **Not by runtime lookup.** Deriving "is a pending request pointing at this?" inside `SecretService::create()` would mean `SecretService` → `SecretRequestMapper`, and `SecretService` is already resolved lazily through `Psr\Container\ContainerInterface` elsewhere in this codebase specifically to dodge that cycle. Paying a DI cycle to re-derive a fact the caller already knows is the wrong trade.

The invariant's other half is cleanup, which the spec already mandates: `:189` requires revoke to delete the placeholder. That stops being theoretical once the human path creates placeholders, so it needs a test rather than new code.

**Known hole, deliberately left open:** an *expired* request stays `pending` (`:126` only stops submissions; it does not revoke), so its placeholder legitimately persists as a permanently empty Secret until someone revokes it. Closing that means deciding whether expiry should auto-revoke — a semantic change to Optional Expiry that belongs in its own change, not smuggled in here. It is called out in tasks as a documented limitation.

### The placeholder is created by the request service, not the controller or the UI

`SecretRequestService` creates the Secret and then the request, mirroring `createForApplicationVault()` — including its rollback: if request creation fails, the shell is removed, because a shell exists only to receive that request. Putting this in the service rather than the controller keeps one creation path for both surfaces and keeps the atomicity guarantee in one place.

`secret_id` becomes optional on the user-side create endpoint and is derived from the created placeholder. It stays **mandatory for a re-request**, which is what makes the two flows structurally distinct rather than distinguished only by a boolean.

### Already-filled fields are excluded at creation, client-side

Three candidate locations, and only one is defensible:

| Where | Verdict |
|---|---|
| Fill screen filters filled fields | **Rejected.** The fill endpoint would have to tell an anonymous recipient which fields already hold values. That is vault metadata about a credential, handed to an unauthenticated party. |
| Server filters at creation | **Rejected as insufficient.** Per `:77` the server cannot know which `additional_fields` members are filled — it never decrypts the blob. It would silently handle top-level fields and miss exactly the case (named extras) this is most useful for. |
| Requester's client, at creation | **Chosen.** The dialog already receives `key`, `login`, `url` and `additionalFields`, and the unlocked vault can decrypt the blob, so per-member filled-ness is knowable. This is the division of labour `:77` already established. |

Behaviour: on a fresh request against an existing Secret, a field that already holds a value is **unticked and labelled as already filled** — visible and tickable, not hidden. Deliberately re-asking is legitimate; it just stops being the default. On a **re-request**, filled fields stay selectable as they are today: overwriting is the entire point of that flow.

Because a fresh request now creates an empty Secret, the common case has nothing filled and the rule is inert — which is the sign it is addressing a symptom of the inversion rather than adding a feature.

### Entry point

"Ask someone for a credential" is added to the secret list (vault level), where a user who has no Secret yet can reach it. `SecretDetail.vue`'s existing action is relabelled to what it actually is — a re-request against that Secret. No new route or page: the same dialog serves both, with the target-Secret inputs shown only when there is no target.

## Risks / Trade-offs

- **Placeholder rows in the vault.** A pending request now shows as an empty Secret in the list, and there is currently no "awaiting fill" indicator anywhere in `SecretList.vue`. Without one, users see rows that look like broken secrets. The list needs a pending marker, or the placeholders need to be visually distinguished; this is the main new UX surface the change introduces and the one most likely to need iteration.
- **Abandoned placeholders accumulate.** Covered above: revoke cleans up, expiry does not.
- **Dummy-key secrets already in the wild.** Users have been working around this; those Secrets stay as they are (see Non-Goals).
- **`allowUnfilled` is a boolean an unrelated caller could pass.** Mitigated by defaulting to `false`, by tests asserting the ordinary path still refuses an empty key, and by the invariant being written into the `secrets` spec so the next reader does not "fix" it back.
- **Ordering dependency on PR #265.** `Version000033` (empty-string default on `doriath_secrets.key`) lives there. Before it merges, a keyless insert dies on the NOT NULL constraint — the Entity setter never marks an unchanged field dirty, so QBMapper omits the column entirely. Implement after #265 merges, or carry that migration here.
