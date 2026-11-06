## Context

ext-vault-edit saves the editor's own row only. Each recipient of a Keepiq item holds a copy encrypted to their own key, so the editing client must encrypt a changed value for every copy. Keepiq's `PUT /api/v1/secrets/{id}/share-save` (`ShareSaveController`, `ShareSaveService`, `lib/Service/ShareSave/`) takes the editor's patch and the ciphertext for every copy and partner share, and writes all of it in one transaction or nothing. The web app's client is `src/services/shareSave.js` (`saveShared`); the extension ports it. Where the web app and the server disagree, the server is the truth (`openspec/config.yaml`).

## Goals / Non-Goals

**Goals:**
- A changed `key`, `login` or `additionalFields` reaches every direct, group, team-folder and partner-organisation copy the editor may write, in the same request as the editor's own row.
- A save that fails writes nothing, and the form says why.

**Non-Goals:**
- Sharing, revoking, suspending by hand or changing a share's limits from the extension.
- Partner-organisation recipients of an item a team editor changed; the server leaves them to the owner's next save.
- Waiting out the partner lookup's rate limit, as the web app does.
- A version restore, offline replay, or anything ext-vault-edit left out.

## Decisions

- **Only a value change goes through share-save.** A save whose body carries `key`, `login` or `additionalFields` takes share-save; any other save stays on `PUT /api/v1/secrets/{id}`, since share-save refuses a patch without one (`no_value_change`). A passkey's `key` is never in the body, so a passkey edit of its name, URL or folder never takes it. The patch is the same sparse body ext-vault-edit sends, without `baseUpdatedAt`, which moves to the envelope.
- **One `write-context` read picks the path.** `GET /api/v1/secrets/{id}/write-context` answers `{sourceSecretId, effectiveGrade, ownerCertificate, sourceUpdatedAt}`. `sourceSecretId === id` is the owner path; otherwise `effectiveGrade` `owner`, `write` or `manage` is the copy-editor path, and anything else is a view-only copy (below). The row alone can't tell a copy from a source, since a copy is owned by its holder. Delegates take the copy-editor path, as the server allows.
- **The copy-editor path sends the source version the edit was made from.** `fetchItem` for edit reads `write-context` too and keeps its `sourceUpdatedAt` in the form's record, so the save's `sourceBaseUpdatedAt` refuses a source written since the form opened (409 `stale_source`), as the web app does. A save whose record is gone takes the `sourceUpdatedAt` of its own `write-context` read. An edit fetch whose `write-context` read fails fails as a whole, like a failed row fetch.
- **A copy without write permission is view-only.** Keepiq's server lets such a holder change their own copy alone, which leaves it out of step with the owner's and everyone else's; the extension doesn't offer that, as Bitwarden's "Can view" (chosen; the web app is keepiq#1287).
  - When an item's detail opens, unless Edit is already unavailable (blocked, use-only or read-only), the popup asks `item.editable`; the background reads `write-context` and answers `editable: false` only for a copy (`sourceSecretId !== id`) whose `effectiveGrade` isn't `owner`, `write` or `manage`. The detail then shows Edit as unavailable with "Shared with you to view only. Ask its owner for edit rights to change it.", as for the other limits; Clone and Move stay. The list doesn't ask per row, so a list row's Edit opens the form and the edit fetch refuses it. Like `item.fetch`, the request counts as activity for the vault timeout.
  - Until it answers, or when the read fails, Edit stays available, since the background checks again: `fetchItem` for edit refuses such a copy with `view_only` before decrypting, and `updateItem` refuses any patch but a folder move on it, reading `write-context` first for a patch with anything besides `folderId`.
  - The grade comes from the server alone, so group members become editors once the server treats them as writers (keepiq#1285), and a per-share permission (keepiq#1286) lands here unchanged.
- **A value change needs its base.** share-save refuses a body without `baseUpdatedAt` (400 `base_required`), so `updateItem` refuses a value change without one as `invalid` before any request; ext-autofill's `capture.update` passes the re-fetched row's.
- **Empty lists first, then what the server requires.** As `saveShared`: the first call carries the patch and no entries. A 409 `incomplete` names the required copies (`{secretId, holder, targetUserId}`) and, on the owner path, partner shares (`{shareId, recipientCloudId}`); the background fills what is missing, drops what is no longer required and sends again, at most 3 rounds, then fails with `incomplete`.
- **Local copies take the sparse patch.** Each copy carries exactly the value fields the patch carries, with the same null-ness, encrypted to its holder's certificate (`copy_fields_mismatch` otherwise). The owner holder uses `ownerCertificate`; recipients' certificates come from `POST /api/v1/shares/recipient-certificates`, at most 100 distinct ids per call. A recipient answered `shareable: false`, or an owner with no `ownerCertificate`, goes under `skipped` with `no_vault_key`. When the server refuses that skip (`skipRefused`), the background reads that certificate again once. A certificate that doesn't import fails the save with `bad_certificate`, since leaving the copy out would be refused anyway; so does a verified partner leaf whose key doesn't import.
- **Partner shares take the whole value.** On the owner path each required partner share gets `key`, `login` and `additionalFields`, encrypted to the leaf the background verified, with `certFingerprint`, the lowercase hex SHA-256 of that leaf. The server replaces all three and refuses an empty `key`.
- **The whole value is what the form showed.** Every item is encrypted to the same key and nothing ties a ciphertext to its item, so decrypting a fresh row would let the server pick what a partner receives. A partner's value is the typed fields plus each other field decrypted from the ciphertext the form's edit fetch opened, kept per form under its random `recordId` in `editOpened.<accountId>` in `storage.session`, so a stopped MV3 worker keeps it, with the row's `updatedAt`. share-save's `baseUpdatedAt` check means that ciphertext is still the item's, so the record is used only when its `updatedAt` equals the save's `baseUpdatedAt`. A field the form didn't show in full (a card member the form has no field for), a record that is gone or one of another version fails the save with `unverified`, before any partner is looked up, and the form points to the Keepiq web app. The edit fetch also refuses a row whose ciphertext the vault cache doesn't hold before or after a vault sync, so the form never opens another item's values.
- **Partner certificates are verified in the background, ported from the web app.** `src/crypto/federated-certificate.ts` is a TypeScript port of `src/crypto/federatedCertificate.js`: pinned root by SHA-256, issuer-subject match and signature per link, `cA=TRUE` (and `keyCertSign` when `keyUsage` is present) above the leaf, common name, validity of every certificate. It allows the web app's 5 minutes of clock skew before `notBefore`. Stricter than the web app: the chain must be exactly the `[intermediate, root]` a partner serves (`FederationRootService.php:106-115`), with an intermediate that isn't self-issued; the leaf must not set `cA=TRUE` (keepiq#1257) and must have exactly one common name, a UTF8String in strict UTF-8, or a PrintableString, TeletexString or IA5String of printable ASCII (the host's OpenSSL `string_mask` decides, keepiq#1282), while a CA's name is held only to the issuer-subject match; an SPKI that isn't a SEQUENCE is `malformed`; every certificate's outer signature algorithm must equal the TBS one, its signature must have no unused bits, and RSA-PSS parameters must name MGF1 and trailer 1; the expected cloud id must not be empty, only the host's case is folded, and an unknown critical or repeated extension is refused. It is tested against every case of the web app's fixture. The secret is encrypted to the SPKI of the leaf that was verified, and a certificate with text outside its one PEM block or bytes after its DER is refused. The pin is the owner server's own `partnerRootFingerprint`. It uses `crypto.subtle.digest`, `importKey('spki')` and `verify` with RSASSA-PKCS1-v1_5 and RSA-PSS, all present in the MV3 service worker and the MV2 background page.
- **The name is checked against the required share.** The leaf's common name must name the `recipientCloudId` the `incomplete` answer gives for that share, not the cloud id the lookup echoes back.
- **Suspend only on a verdict about the recipient.** A verifier refusal, the server's own 502 `bad_chain` (`FederatedCertificateService::parseAnswer`, a partner serving the wrong chain shape), `unknown_recipient` (404) and `not_a_partner` (403) go under `suspend` with that reason, as the web app's `PARTNER_GONE`. These reasons arrive only as the body's `message` on a plain controller (`FederationController::recipientCertificate`), so `ApiError.message` is read there; it is never shown.
- **An unreachable partner stops the save.** A 502 `partner_unreachable` fails the save with `partner_unreachable` and the shares it couldn't reach (`{shareId, cloudId}`). The form names their cloud ids and offers Save without them, which sends `item.update` again with those share ids as `withoutUnreachable`; the background then lists them under `suspend` with `partner_unreachable`. A share that fails only on that second save, and isn't in `withoutUnreachable`, stops it again; the form keeps the earlier consent, names every partner agreed to so far and sends them all, until the password, username or fields change. Try again after a failure resends the same request, Save without them included, while the values are unchanged; the footer Save is a fresh one. Chosen to mirror the web app: otherwise one partner that is down blocks every extension save of the item.
- **The rate limit stops the save.** A 429 from the partner lookup fails the save with `partner_lookup_limit`, worded as "try again in a minute". The web app waits up to five minutes, but an MV3 worker can be stopped during a timer wait and a Chrome popup closes on any click outside it. A 409 `federation_unavailable` (Nextcloud below 33, without the OCM endpoint event) is a lasting state, so it gets its own code, worded as "ask your administrator", without Try again. Any other lookup failure is this server's, so it fails the save with the ordinary write code and suspends nothing; that includes a 200 without a `certificate` string, a `chain` of two strings and a 64-digit hex `partnerRootFingerprint`, the shape the server guarantees (`FederatedCertificateService::parseAnswer`, `FederationPartnerService::add`), since suspending can't be undone.
- **Verified partner certificates are kept for 5 minutes.** As the web app's `verifiedPartners`: `{fingerprint, spki}` per account, share id and `recipientCloudId`, in background memory only, used only for the same cloud id, dropped once a save that used it succeeds, and all forgotten on lock, logout and account switch; a save that runs across a lock keeps nothing. So Save without them doesn't look every partner up again. It is public material.
- **Saves above 100 entries are staged.** At most 100 entries in `copies` plus `skipped` and 100 in `federated` plus `suspend` per call; above that the background sends full chunks with `stage: {final: false}`, keeps the `stageId` from the 202 (a 202 without one fails as `unknown`), and the final call carries the patch and `stage: {id, final: true}`. Dropping an entry already staged starts a new stage; a 404 `stage_unknown` restarts it, counted as a round.
- **Failures map through `writeError`.** A 409 `stale` is `conflict`, as for any save; `stale_source` is its own code, worded as "the original changed". `incomplete`, `bad_certificate`, `unverified`, `partner_unreachable`, `partner_lookup_limit` and `federation_unavailable` are new codes. The share-save's own `not_allowed`/`forbidden` refusals are `forbidden`, a policy refusal keeps `policy`; `OcsRefusalMiddleware` turns them into 428, which the API client reads like a 403. The `incomplete` lists come only in the 409 body, so `ApiError` carries the parsed `body`. `writeError` and `WriteFailure` live in `src/vault/write-error.ts`, so `share-save.ts` and `write.ts` share them without an import cycle.
- **No automatic retry, nothing kept.** A failed save leaves the form open with the draft; the user saves again. Nothing about a save outlives its request but the verified partner certificates above: no Try again records, no kept result, no per-item queue, since the server's version check orders concurrent saves. The form's `editOpened` record is dropped once its save succeeds.
- **Popup UX: a note on the item's detail. Default, may be overruled.** A save that reached everyone closes the form with "Item saved" as before. These outcomes add a note on the item's detail, held in popup memory for that item until the popup closes: `ownerOnly` on the copy-editor path ("Recipients at partner organisations get this change when the owner next saves it."), partner shares the save suspended on a verdict about the recipient, by cloud id, and, as their own line, the shares the user suspended through Save without them (`paused`), the only lasting sign of that pause in the extension.
- **Local recipients' certificates are trusted as the web app trusts them.** Only the user's own certificate can be proven (ADR-002); a recipient's can't. A compromised server can therefore list a recipient the user never chose and receive the next value of an edited item. This is the web app's and Bitwarden's organisation-membership trust; ADR-002 and `SECURITY.md` record the risk.

## Data flow

1. Popup: the item's detail asks `item.editable`, and shows Edit as unavailable for a view-only copy. Edit sends `item.fetch` with `purpose: 'edit'`; `fetchItem` fetches the row, checks it against the vault cache, reads `write-context`, refuses a view-only copy, decrypts, and keeps the ciphertext it showed in full, the row's `updatedAt` and `sourceUpdatedAt` under a new `recordId`.
2. Popup: Save sends `item.update` with the patch, `baseUpdatedAt`, `recordId` and, after Save without them, `withoutUnreachable`.
3. Background `updateItem`: refuse restricted copies, encrypt the patch to the user's own key. A folder move alone: `PUT /api/v1/secrets/{id}`. Any other patch reads `write-context` first and refuses a view-only copy with `view_only`. Without a value field: `PUT /api/v1/secrets/{id}` as in ext-vault-edit. With one: `shareSave(...)`.
   1. The path from that `write-context` read.
   2. `PUT …/share-save` with `baseUpdatedAt`, `sourceBaseUpdatedAt` on the copy-editor path, the patch and empty lists.
   3. On 409 `incomplete`: certificates per 100 ids, encrypt the sparse patch per copy; on the owner path build the whole value from the record, then per partner share look up, verify, encrypt, or list it under `suspend`; send again (staged above 100).
4. `syncAfterWrite`, drop the form's record, reply `{ ok: true, value: { id, shareSave } }`.
5. Popup: the form closes, Shell keeps `shareSave` for the item and opens its detail; `ShareSaveNote` renders it. A failure keeps the form open with `writeErrorText`, plus Save without them for `partner_unreachable`.

## Module layout

- `src/vault/verified-partners.ts` (new): the 5-minute memory of verified partner certificates, cleared by `clearKey` and on an account switch or add.
- `src/vault/share-save.ts` (new): `shareSave`, the rounds, the certificate batching, the copy and partner entries and the staging; takes a client, a key and the form's record, so it has no account logic.
- `src/crypto/federated-certificate.ts` (new): `verifyFederatedCertificate`, which resolves with `{ fingerprint, spki }`: the lowercase hex fingerprint Keepiq takes, and the verified leaf's key, which `importSpki` imports; `canonicalCloudId`, `FederatedCertificateError`; exported from `src/crypto/index.ts`.
- `src/vault/write-error.ts` (new): `WriteFailure` and `writeError`, moved out of `write.ts`.
- `src/vault/write.ts` (edit): `fetchItem` keeps the form's record and refuses a view-only copy; `updateItem` refuses one too, sends value changes through `shareSave` and drops the record after a success; `itemEditable` answers `item.editable`.
- `src/messages.ts` (edit): `ShareSaveNote`, `withoutUnreachable` on `item.update`, the `item.update` reply, `partners` on `WriteFailure`, the new codes, and `recordId` on `EditableItem`.
- `src/background/requests.ts` (edit): `item.update` passes `withoutUnreachable` on, dropping one that isn't a list of strings; the `item.editable` arm.
- `src/api/client.ts` (edit): `ApiError` carries the parsed error `body`.
- `entrypoints/popup/components/ShareSaveNote.tsx` (new): the note on the item's detail.
- `entrypoints/popup/views/ItemForm.tsx`, `Shell.tsx`, `ItemDetail.tsx` (edit): Save without them, `onSaved(id, shareSave)`, Shell's state, the note on detail, and Edit unavailable on a view-only copy.
- `entrypoints/popup/errors.ts` (edit): the new codes in `writeErrorText`.

## Message contract

```ts
/** Shown on the item's detail after a save; absent when the save reached everyone. */
export interface ShareSaveNote {
	/** Copy-editor path: partner shares exist that only the owner's next save reaches. */
	ownerOnly?: true
	/** Partner shares this save suspended on a verdict about the recipient, by cloud id. */
	suspended?: string[]
	/** Partner shares the user suspended through Save without them, by cloud id. */
	paused?: string[]
}

// WriteFailure gains, for `partner_unreachable`:
partners?: Array<{ shareId: string; cloudId: string }>

// WriteErrorCode gains `view_only`.

// VaultWrite changes:
| { kind: 'item.update'; id: string; patch: ItemPatch; baseUpdatedAt?: string; recordId?: string; withoutUnreachable?: string[] }
| { kind: 'item.editable'; id: string }
// PopupReplies changes:
'item.update': WriteResult<{ id: string; shareSave?: ShareSaveNote }>
'item.editable': WriteResult<{ editable: boolean }>
```

## Keepiq routes

| Route | Body | Answer |
| --- | --- | --- |
| `GET /api/v1/secrets/{id}/write-context` | none | `{sourceSecretId, effectiveGrade, ownerCertificate, sourceUpdatedAt}`; 404 when the secret is gone |
| `PUT /api/v1/secrets/{id}/share-save` | `{baseUpdatedAt, sourceBaseUpdatedAt?, patch?, copies, skipped, federated?, suspend?, stage?}` | 200 `{secret, sourceUpdatedAt, copies: {written, skipped}, federated: {written, suspended, notified, queued, ownerOnly}}`; 202 `{stageId}` for a chunk; 409 `incomplete` `{path, sourceUpdatedAt, copies: {required, missing, unexpected, skipRefused}, federated: {required, missing, unexpected}}`, `stale` `{current, sourceUpdatedAt?}`, `stale_source`; 400 `too_many_entries`, `copy_fields_mismatch`, `no_value_change`, `invalid_body`, …; 428 (`OcsRefusalMiddleware`) `not_allowed`, `forbidden` or a policy code; 404 `not_found`, `stage_unknown`; 423 `write_locked` |
| `POST /api/v1/shares/recipient-certificates` | `{userIds}`, 1 to 100 distinct | `{recipients: [{userId, shareable: true, certificate} \| {userId, shareable: false, reason}]}`; 400 outside the bounds |
| `POST /api/v1/federation/recipient-certificate` | `{cloudId}` | `{cloudId, certificate, chain, partnerRootFingerprint}`; 404 `unknown_recipient`, 403 `not_a_partner`, 409 `federation_unavailable`, 502 `partner_unreachable` or `bad_chain`; 429 past 60 per minute per user |

Entries: `copies` `{secretId, key?, login?, additionalFields?}`, `skipped` `{secretId, reason: 'no_vault_key'}`, `federated` `{shareId, certFingerprint, key, login, additionalFields}`, `suspend` `{shareId, reason}`. Controllers: `ShareSaveController` and `ShareController` are OCS, `FederationController` is plain. Routes in `appinfo/routes.php`.

## Risks / Trade-offs

- [A compromised server lists a recipient the user never chose] → Accepted as in the web app; recorded in ADR-002.
- [A share created while a save runs encrypts the older value] → The next save's completeness check names it; closing it needs share creation to send the version it encrypted from (keepiq#1255).
- [The reply to a save that committed is lost] → The form stays open with `offline`; saving again answers `conflict`, since the base moved, and opening the item shows the saved change.
- [More than 60 partner shares] → The lookup limit fails the save with `partner_lookup_limit`; a batch lookup would close it (keepiq#1256).
- [An unreachable partner suspended through Save without them] → Nothing resumes a suspended share; it stays suspended until the owner revokes it or shares again, and the form says so before the user confirms.
- [A large team folder] → One certificate request per 100 members and RSA-OAEP encryption per copy and field, and one staged chunk per 100 entries; public-key encryption is cheap next to decryption.
- [The MV3 worker stops mid-save] → An open `fetch` keeps it alive; if it dies, the popup gets no reply, and the server wrote everything or nothing.
- [A request that hangs] → The save waits until it errors, since the client has no request timeout.
- [Partner verifier drifts from the web app] → It is tested against the web app's own fixture chain, `../tests/fixtures/federation-chain.json`.
