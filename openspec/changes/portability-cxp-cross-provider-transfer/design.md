# Design: exchange a vault with another provider through Credential Exchange files

## Context

At development `156cd800`:

- `src/views/SecretList.vue:163` opens the transfer dialog; `src/dialogs/CxpTransferDialog.vue:275` `startReceive` and `:360` `doSend`.
- `src/crypto/cxp.js:110` `createImportRequest`, `:136` `sealForRequest`, `:187` `openEnvelope` are already transport free.
- `lib/Controller/CxpRelayController.php:219` and routes `:400-401` are a same-instance mailbox.
- `src/store/modules/export.js:206` `exportCxpSealed` exports the whole vault.

## Goals / Non-Goals

**Goals**
- A user can move a vault to or from a provider that speaks the standard, without an intermediary account.

**Non-Goals**
- Operating system credential exchange APIs, which a web page cannot reach.
- A hosted public relay.

## Decisions

### D1: File and QR transport over the existing crypto

`cxp.js` already separates the envelope from the mailbox, so a file or QR carries the same request and response the relay carries. Nothing new is trusted.

### D2: Vectors first

The first task checks the sealing against the published protocol test vectors, so a mismatch is found before any UI is built.
