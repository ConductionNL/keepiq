# Design

## Group B: decisions recorded without a spec requirement

These are choices about presentation or build, held here instead of in a requirement:

- **Filters.** The Vault tab filters by folder and type with two dropdowns, not chips and a More menu.
- **Actions.** Copy, Open, Edit, Clone, Move, Send and Delete live in the item detail, not in a menu on each card.
- **Popup technology.** The popup is plain JavaScript bundled with esbuild, not React (the old ADR-004). It keeps the bundle small and the extension free of a framework runtime.
- **Symbols.** The generator draws symbols from Keepiq's OWASP set, as `key-generator` specifies, not from `!@#$%^&*`.
- **Send expiry.** A new send expires after one day by default, not seven.
- **Build.** esbuild, MV3 on every browser including Firefox 115 and later, and `chrome.*` APIs (the old notes assumed WXT, MV2 on Firefox and `browser.*`).

## The name limit

The `name` column of `oc_keepiq_secrets` is `varchar(255)`. The worker already refused longer names, but the extension's form and the import allowed 4096, so a long name failed at the database. Both now refuse a name over 255 characters before it is sent. Widening the column was the alternative and was not chosen.
