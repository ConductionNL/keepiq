# Supported Keepiq versions

The browser extension supports every released Keepiq version from 0.3.12 on for five years from that version's release date. After that, support may end. This applies from the extension's first store release.

| Keepiq | Released | Supported until | Notes |
| --- | --- | --- | --- |
| 0.3.12 | Not yet | Five years after release | First version with share-save, which saving an edit of a shared item needs. |

Older versions are refused: the extension won't add or unlock an account on them, and says which version it needs.

## For maintainers

How to gate a feature on a version is in [KEEPIQ-VERSIONS.md](https://github.com/ConductionNL/keepiq/blob/development/browser-extension/KEEPIQ-VERSIONS.md).

- Add a row for each Keepiq release, with its release date and the date five years on.
- The minimum is `MIN_KEEPIQ_VERSION` in `browser-extension/src/api/keepiq-version.ts`. Raise it only past versions whose five years have ended.
- A feature that needs a newer Keepiq goes in `KEEPIQ_FEATURES` with the first version that has it, and is offered only where `keepiqSupports` allows it; older supported versions keep working without it. Add a note to that version's row.
