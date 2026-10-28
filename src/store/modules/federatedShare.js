// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import {
	canonicalCloudId,
	verifyFederatedCertificate,
} from '../../crypto/federatedCertificate.js'
import { PROOF_PURPOSE, sessionKeyProofHeaders } from '../../crypto/keyProof.js'
import { importSpkiPublicKey } from '../../crypto/rsa.js'
import {
	BLOB_FIELDS,
	TOTP_PASSKEYS_FIELDS_SINCE,
} from '../../utils/valueFields.js'
import { useShareStore } from './share.js'

/**
 * The fields of a secret that are encrypted for a recipient; name and URL
 * travel in the clear, as for local shares.
 *
 * @type {string[]}
 */
const ENCRYPTED_FIELDS = ['key', 'login', 'additionalFields', ...BLOB_FIELDS]

/** The refusal of a value field a partner's Keepiq is too old to store. */
const PARTNER_UPGRADE_REQUIRED = 'partner_upgrade_required'

/**
 * A new share of a login with a one-time code or passkeys to a partner whose
 * Keepiq cannot store them; nothing was shared.
 */
export class PartnerUpgradeRequiredError extends Error {
	/**
	 * @param {string} [requiredVersion] The Keepiq version the partner needs.
	 * @param {{cause?: Error}} [options] The refusal this one stands for.
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	constructor(requiredVersion = TOTP_PASSKEYS_FIELDS_SINCE, options = undefined) {
		super(
			t(
				'keepiq',
				'Nothing was shared: their organisation runs an older Keepiq that cannot store one-time codes or passkeys. It needs Keepiq {version} or later; until then, share a login without them.',
				{ version: requiredVersion },
			),
			options,
		)
		this.name = 'PartnerUpgradeRequiredError'
		this.code = PARTNER_UPGRADE_REQUIRED
		this.requiredVersion = requiredVersion
	}
}

/**
 * Whether a partner advertised it stores a value field beyond `key`, `login`
 * and `additionalFields`.
 *
 * @param {{valueFields?: Array<string>}} recipient From lookup().
 * @param {string} field `totp` or `passkeys`.
 * @return {boolean}
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
export function partnerStores(recipient, field) {
	return (
		Array.isArray(recipient?.valueFields)
		&& recipient.valueFields.includes(field)
	)
}

/**
 * The text for a value field refused because a partner's Keepiq is too old:
 * a new share refused here, a share save stopped in this browser (`partners`
 * names them) or the server's 409 `partner_upgrade_required`. Null for any
 * other error.
 *
 * @param {Error|object} error The thrown error.
 * @return {string|null}
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
export function partnerUpgradeText(error) {
	if (error instanceof PartnerUpgradeRequiredError) {
		return error.message
	}
	const data = error?.response?.data
	if (
		error?.code !== PARTNER_UPGRADE_REQUIRED
		&& data?.error !== PARTNER_UPGRADE_REQUIRED
	) {
		return null
	}
	const version = data?.requiredVersion || TOTP_PASSKEYS_FIELDS_SINCE
	const partners = (Array.isArray(error.partners) ? error.partners : [])
		.map((share) => share.recipientCloudId)
		.filter(Boolean)
	if (partners.length > 0) {
		return t(
			'keepiq',
			'Nothing was saved: a partner organisation this secret is shared with runs an older Keepiq that cannot store one-time codes or passkeys ({partners}). It needs Keepiq {version} or later; until then, remove the one-time code and passkeys from this login or stop sharing it with them.',
			{ partners: partners.join(', '), version },
		)
	}
	return t(
		'keepiq',
		'Nothing was saved: a partner organisation this secret is shared with runs an older Keepiq that cannot store one-time codes or passkeys. It needs Keepiq {version} or later; until then, remove the one-time code and passkeys from this login or stop sharing it with them.',
		{ version },
	)
}

/**
 * A lookup answer for another account than the one asked for, so its
 * certificate is not one for the recipient.
 */
export class CloudIdMismatchError extends Error {
	/**
	 * @param {string} cloudId The cloud id that was asked for.
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	constructor(cloudId) {
		super('cloud_id_mismatch')
		this.name = 'CloudIdMismatchError'
		this.reason = 'cloud_id_mismatch'
		this.cloudId = cloudId
	}
}

/**
 * A cloud id as the server echoes it after resolving it: `\` read as `/`,
 * cut at `/index.php` and without a trailing `/`, as Nextcloud's
 * CloudIdManager::stripShareLinkFragments() does.
 *
 * @param {string} cloudId A trimmed cloud id.
 * @return {string}
 */
function resolvedForm(cloudId) {
	const id = cloudId.replaceAll('\\', '/')
	const fileName = id.indexOf('/index.php')
	return (fileName > 0 ? id.slice(0, fileName) : id).replace(/\/+$/, '')
}

/**
 * The colon-separated, uppercase fingerprint the verifier shows, as the
 * lowercase hex the server stores.
 *
 * @param {string} fingerprint `AB:CD:...`
 * @return {string}
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
export function fingerprintHex(fingerprint) {
	return String(fingerprint).replaceAll(':', '').toLowerCase()
}

/**
 * The fields of a decrypted secret that are encrypted for a recipient, as
 * strings.
 *
 * @param {object} secret The decrypted secret.
 * @return {object}
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
function snapshotOf(secret) {
	const snapshot = {}
	for (const field of ENCRYPTED_FIELDS) {
		const value = secret[field]
		if (value !== null && value !== undefined && value !== '') {
			snapshot[field] =
				typeof value === 'string' ? value : JSON.stringify(value)
		}
	}
	return snapshot
}

/**
 * Encrypt a secret for a recipient from lookup(): to the public key of the
 * certificate that was verified, never to the lookup answer's text.
 *
 * @param {object} secret The decrypted secret.
 * @param {{spki: Uint8Array}} recipient From lookup().
 * @return {Promise<object>} The ciphertext per field.
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
async function encryptForVerified(secret, recipient) {
	return useShareStore().encryptWithKey(
		snapshotOf(secret),
		await importSpkiPublicKey(recipient.spki),
	)
}

/**
 * The server's 409 `partner_upgrade_required` as a PartnerUpgradeRequiredError;
 * any other error as it is.
 *
 * @param {Error|object} error The share request's error.
 * @return {Error|object}
 */
function upgradeRefusal(error) {
	const data = error?.response?.data
	if (data?.error !== PARTNER_UPGRADE_REQUIRED) {
		return error
	}
	return new PartnerUpgradeRequiredError(
		data.requiredVersion || TOTP_PASSKEYS_FIELDS_SINCE,
		{ cause: error },
	)
}

/**
 * Sharing a secret with a user of a partner organisation
 * (sharing-federated-recipients D3, D4). The owner's server fetches the
 * recipient's certificate from the partner; this browser verifies it
 * against the pinned partner root and the cloud id, and only then encrypts.
 * Only ciphertext goes to the server.
 */
export const useFederatedShareStore = defineStore('federatedShare', {
	state: () => ({
		/** @type {boolean|null} Whether any outbound partner exists; null until asked. */
		available: null,
		/** @type {Array<object>} The federated shares of the secret last listed. */
		shares: [],
	}),

	actions: {
		/**
		 * Ask whether the dialog may offer another organisation at all.
		 *
		 * @return {Promise<boolean>}
		 * @spec openspec/specs/federated-sharing/spec.md#scenario-no-partner-no-federation
		 */
		async checkAvailable() {
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/federation/status'),
				)
				this.available = response.data?.outbound === true
			} catch {
				this.available = false
			}
			return this.available
		},

		/**
		 * Fetch and verify a federated recipient's certificate. Rejects with a
		 * CloudIdMismatchError when the answer is for another cloud id, a
		 * FederatedCertificateError when the certificate does not chain to the
		 * pinned partner root or names someone else, or with the server's
		 * refusal. The certificate text is not returned: only the verified key is.
		 *
		 * The answer's `valueFields` lists the value fields beyond `key`,
		 * `login` and `additionalFields` the partner stores; none when it
		 * names none.
		 *
		 * @param {string} cloudId What the owner typed.
		 * @return {Promise<{cloudId: string, fingerprint: string, spki: Uint8Array, valueFields: Array<string>}>} `cloudId` as the server resolved it.
		 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-verified-remote-certificate
		 */
		async lookup(cloudId) {
			const requested = cloudId.trim()
			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/federation/recipient-certificate'),
				{ cloudId: requested },
			)
			const answer = response.data
			const expected = canonicalCloudId(resolvedForm(requested))
			if (expected === '' || canonicalCloudId(answer?.cloudId) !== expected) {
				throw new CloudIdMismatchError(requested)
			}
			const { fingerprint, spki } = await verifyFederatedCertificate({
				certificate: answer.certificate,
				chain: answer.chain,
				partnerRootFingerprint: answer.partnerRootFingerprint,
				cloudId: answer.cloudId,
			})
			const valueFields = Array.isArray(answer.valueFields)
				? answer.valueFields.filter((field) => BLOB_FIELDS.includes(field))
				: []
			return { cloudId: answer.cloudId, fingerprint, spki, valueFields }
		},

		/**
		 * The federated shares of one of the user's secrets, with their state.
		 *
		 * @param {string} secretId The owner's secret.
		 * @return {Promise<Array<object>>}
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
		 */
		async listFor(secretId) {
			const response = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/secrets/${secretId}/federated-shares`,
				),
			)
			this.shares = Array.isArray(response.data) ? response.data : []
			return this.shares
		},

		/**
		 * Revoke a federated share; the recipient's instance deletes its copy.
		 *
		 * @param {string} id The federated share.
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
		 */
		async revoke(id) {
			await axios.delete(
				generateUrl(`/apps/keepiq/api/v1/federated-shares/${id}`),
			)
			this.shares = this.shares.filter((share) => share.id !== id)
		},

		/**
		 * Encrypt a secret for a verified recipient and send the ciphertext.
		 *
		 * A login with a one-time code or passkeys is refused with a
		 * PartnerUpgradeRequiredError, before anything is sent, when the
		 * partner did not advertise it stores them.
		 *
		 * @param {string} secretId The owner's secret.
		 * @param {object} secret The decrypted secret (key, login, additionalFields, totp, passkeys).
		 * @param {{cloudId: string, fingerprint: string, spki: Uint8Array, valueFields?: Array<string>}} recipient From lookup().
		 * @return {Promise<object>} The federated share.
		 * @throws {PartnerUpgradeRequiredError} The partner cannot store a field the login holds.
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
		 */
		async share(secretId, secret, recipient) {
			// The server would take the field as null, so the partner's copy
			// would quietly lack it.
			if (
				BLOB_FIELDS.some(
					(field) => secret?.[field] && !partnerStores(recipient, field),
				)
			) {
				throw new PartnerUpgradeRequiredError()
			}
			const encrypted = await encryptForVerified(secret, recipient)
			const url = generateUrl(
				`/apps/keepiq/api/v1/secrets/${secretId}/federated-shares`,
			)
			const body = {
				recipientCloudId: recipient.cloudId,
				certFingerprint: fingerprintHex(recipient.fingerprint),
				key: encrypted.key ?? '',
				login: encrypted.login ?? null,
				additionalFields: encrypted.additionalFields ?? null,
				totp: encrypted.totp ?? null,
				passkeys: encrypted.passkeys ?? null,
			}
			try {
				return (await axios.post(url, body)).data
			} catch (refusal) {
				// Every recipient at another organisation is a new party, so the
				// server asks for the vault key proof (keepiq#818).
				if (refusal?.response?.data?.error !== 'key_proof_required') {
					throw upgradeRefusal(refusal)
				}
				const { headers } = await sessionKeyProofHeaders({
					purpose: PROOF_PURPOSE.SHARE_NEW_RECIPIENT,
					reason: t(
						'keepiq',
						'You are sharing with someone new. Enter your master password to confirm.',
					),
					boundValues: [secretId, recipient.cloudId],
				})
				try {
					return (await axios.post(url, body, { headers })).data
				} catch (e) {
					throw upgradeRefusal(e)
				}
			}
		},
	},
})
