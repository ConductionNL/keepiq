# Keepiq versions

How the extension decides what a server's Keepiq can do. The rules are in [CLAUDE.md](CLAUDE.md#keepiq-version-support); the supported versions are in [supported-keepiq-versions.md](../docs/browser-extension/supported-keepiq-versions.md); the spec is `keepiq-version` in `openspec/changes/ext-share-sync`.

## The support promise

- Every released Keepiq version from `MIN_KEEPIQ_VERSION` on is supported for five years from its release date.
- "Supported" means everything the extension offers works, or a feature is withheld because that version lacks it, saying which version adds it. Never a broken request.
- After five years, support may end: the minimum may then pass that version.

## Where the version comes from

| Step | What happens |
| --- | --- |
| Add account, re-login | `verifyCredentials` reads `GET /api/health` and refuses with `keepiq_outdated` below the minimum. |
| Stored | `keepiqVersion` on the `AccountRecord`; `undefined` on accounts added before it was kept, `null` when health named none. |
| Unlock | `checkServer` reads it again only when the stored one is missing or too old. |
| Full sync | `refreshVersion` reads it on every full sync, which every write starts; an answer without a version keeps the stored one. |

So after an admin updates Keepiq, a new feature turns on at the next full sync, not before.

## How versions compare

`meetsVersion(version, minimum)` in `src/api/keepiq-version.ts`:

- Only `major.minor.patch` counts: `0.4.0-beta.2026…` counts as `0.4.0`.
- An `-unstable` build always passes, since a `development` checkout reports an old version (`0.3.9-unstable.*`). A gated route may therefore still be missing there: handle its HTML 404 (below).
- A missing or unreadable version never passes, so a gated feature stays off for it.

## Adding a feature that needs a newer Keepiq

Example: Keepiq 0.4.0 adds `PUT /api/v1/folders/{id}/share`, and the extension wants a Share button for folders.

1. **Register it.** Add the feature with the first version that has it:

   ```ts
   export const KEEPIQ_FEATURES = {
   	folderShares: '0.4.0',
   } as const satisfies Record<string, string>
   ```

   The name becomes a `KeepiqFeature`, so a typo fails the typecheck. Use the version the feature will ship in, also before that release exists.

2. **Gate it in the background.** The background decides, from the account it writes for:

   ```ts
   export function shareFolder(id: string, recipients: string[], accountId?: string) {
   	return run(async ({ account, client }) => {
   		if (!keepiqSupports(account.keepiqVersion, 'folderShares')) throw new WriteFailure({ code: 'keepiq_outdated' })
   		await client.keepiq('PUT', `${folderPath(id)}/share`, { recipients })
   	}, 'sync', accountId)
   }
   ```

   When older supported versions have another way to do the same thing, take that path instead of refusing, as `updateItem` does with a plain `PUT` for a change without values.

3. **Handle the route missing anyway.** An `-unstable` build passes the check without having the route. Nextcloud answers it with an HTML 404, which the client throws as `KeepiqNotInstalled`; map that to `keepiq_outdated` where the call is made, as `refusalOf` in `share-save.ts` does. A 404 with a JSON body is Keepiq's own and stays `not_found`.

4. **Tell the popup.** The popup never sees the version. Send it the answer:
   - for a button: a boolean in the reply the view already asks for (as `item.editable` answers `{ editable }`), or a new request in `PopupRequest` and `PopupReplies`;
   - show the button as unavailable with the reason, as `itemActions` does, not hidden, with a text naming the version that adds it, in `en.yml` and `nl.yml`;
   - a refusal arrives as `keepiq_outdated`, which `writeErrorText` already words.

5. **Test both sides.** At least: the version that has it, the one before it, an `-unstable` build, `keepiqVersion` `undefined`, and the HTML 404. Fixture accounts carry `keepiqVersion: '0.3.12'`; set it per test where it matters.

6. **Write it down.**
   - The feature's spec: a scenario for a Keepiq without it.
   - [ADR-003](openspec/architecture/adr-003-keepiq-api-contract.md): the route's row names the version that added it.
   - [supported-keepiq-versions.md](../docs/browser-extension/supported-keepiq-versions.md): a note on that version's row.
   - `../docs/browser-extension/using.md`: what users of older versions see.

## Raising the minimum

Only when every release below the new minimum is past its five years; check the table first.

1. Set `MIN_KEEPIQ_VERSION`. The texts take it from there.
2. Remove every `KEEPIQ_FEATURES` entry the new minimum reaches, then fix what the typecheck flags: drop each `keepiqSupports` check and the fallback path behind it, and its tests. The test in `keepiq-version.test.ts` fails while an entry is not newer than the minimum.
3. Update the table, ADR-003's health row and the `keepiq-version` spec.

## Don'ts

- Compare versions anywhere but `keepiq-version.ts`.
- Ask the version from the popup or a content script.
- Probe a route to learn whether a feature exists; the 404 handling is a fallback for `-unstable` builds only.
- Raise the minimum to avoid writing a fallback.
