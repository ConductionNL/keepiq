/**
 * TOTP for the extension — the SAME RFC 6238 implementation the web UI uses,
 * re-exported verbatim (ADR-003 dual-implementation invariant, extension-totp-
 * autofill §1.1). No re-implementation: the seed parser and code generator are
 * imported from the web app's `src/totp/totp.js`.
 */
export { parseOtpauth, generateTotp, secondsRemaining, base32Decode, TOTP_DEFAULTS } from '../../../src/totp/totp.js'
