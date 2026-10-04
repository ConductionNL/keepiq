/**
 * Decide what to offer after a login form is submitted (clients-save-prompt):
 * save a new login, update the saved one whose password changed, or nothing
 * when the saved one already has this password. Runs in the service worker,
 * which holds the vault; the content script only learns which of the three.
 *
 * @spec openspec/specs/clients-save-prompt/spec.md
 */

import { hostOf, matchSecrets } from './match.js'

/**
 * Classify a capture against the saved logins for its site.
 *
 * Only exact-host and same-site rows are considered (a name match is too
 * loose to update a password on). A row whose login decrypts to the captured
 * username is the match.
 *
 * @param {{host: string, login: string, secret: string}} capture The submitted login.
 * @param {Array<object>} rows Candidate rows from the match endpoint (ciphertext).
 * @param {Function} decrypt Resolves a row to {login, secret} in plain text.
 * @return {Promise<{action: 'save'|'update'|'none', id?: string, name?: string}>} The offer.
 * @spec openspec/specs/extension-save-prompt-details/spec.md#requirement-update-the-one-login-that-is-meant
 */
export async function classifyCapture(capture, rows, decrypt) {
	const host = hostOf(capture.host)
	const candidates = matchSecrets(rows, host).filter((row) => row._score >= 80)
	const sameLogin = []
	for (const row of candidates) {
		let plain
		try {
			plain = await decrypt(row)
		} catch {
			continue
		}
		if ((plain.login || '') !== (capture.login || '')) {
			continue
		}
		if ((plain.secret || '') === capture.secret) {
			return { action: 'none', id: row.id, name: row.name }
		}
		sameLogin.push(row)
	}
	// One saved login with this username: update it. Several: which one is
	// meant cannot be told, so offer nothing rather than guess.
	if (sameLogin.length === 1) {
		return { action: 'update', id: sameLogin[0].id, name: sameLogin[0].name }
	}
	if (sameLogin.length > 1) return { action: 'none' }
	return { action: 'save' }
}
