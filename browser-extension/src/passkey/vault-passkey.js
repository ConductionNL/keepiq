/**
 * Passkey vault serialization for the extension — the SAME canonical schema
 * `passkey-item-type` defined, re-exported verbatim (extension-passkey-provider
 * §"consumes the passkey canonical schema"). A created passkey is written as a
 * `passkey`-typed secret with this JSON in the encrypted `key` and the RP id in
 * the plaintext `url`; there is no new create path.
 */
export {
	buildPasskeyCredential,
	serializePasskey,
	parsePasskey,
	passkeyRpId,
	PASSKEY_TYPE_NAME,
} from '../../../src/passkey/passkey.js'
