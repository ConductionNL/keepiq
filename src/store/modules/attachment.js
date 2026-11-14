/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for encrypted attachments (encrypted-attachments §6).
 *
 * All file crypto happens here, in the browser (ADR-003): upload
 * generates a random AES-256-GCM file key, encrypts the bytes and the
 * `{filename, contentType}` metadata under it, RSA-wraps the key under
 * the owner's public certificate, and POSTs ciphertext only. Download
 * unwraps the file key with the in-session CryptoKey, decrypts, and
 * triggers a local save — plaintext never touches storage or the wire.
 * Sharing re-wraps only the tiny file key per recipient; the blob is
 * never re-uploaded.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { importPublicKey, rsaDecrypt, rsaEncrypt } from '../../crypto/index.js'
import { useSessionStore } from './session.js'

/** AES-GCM IV length in bytes. */
const IV_LENGTH = 12

/**
 * Encode bytes to base64.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string} Base64.
 */
function toBase64(bytes) {
	let binary = ''
	for (let i = 0; i < bytes.length; i += 0x8000) {
		binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
	}
	return btoa(binary)
}

/**
 * Decode base64 to bytes.
 *
 * @param {string} base64 The base64 string.
 * @return {Uint8Array} The bytes.
 */
function fromBase64(base64) {
	return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0))
}

/**
 * AES-256-GCM encrypt bytes under a key, IV-prefixed.
 *
 * @param {CryptoKey} key The AES key.
 * @param {Uint8Array} bytes The plaintext bytes.
 * @return {Promise<Uint8Array>} IV + ciphertext.
 */
async function aesEncryptBytes(key, bytes) {
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, bytes),
	)
	const out = new Uint8Array(IV_LENGTH + ciphertext.length)
	out.set(iv, 0)
	out.set(ciphertext, IV_LENGTH)
	return out
}

/**
 * AES-256-GCM decrypt IV-prefixed bytes.
 *
 * @param {CryptoKey} key The AES key.
 * @param {Uint8Array} data IV + ciphertext.
 * @return {Promise<Uint8Array>} The plaintext bytes.
 */
async function aesDecryptBytes(key, data) {
	const iv = data.subarray(0, IV_LENGTH)
	const ciphertext = data.subarray(IV_LENGTH)
	return new Uint8Array(
		await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext),
	)
}

/**
 * The text a failed request leaves: the server's message, else the error's
 * own, else the operation's fallback.
 *
 * @param {Error|object} e The failure.
 * @param {string} fallback The operation's own text.
 * @return {string}
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
 */
function failureText(e, fallback) {
	return e?.response?.data?.message || e?.message || fallback
}

/**
 * @param {string} secretId The secret id.
 * @return {string} The URL of the secret's attachments.
 */
function attachmentsUrl(secretId) {
	return generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/attachments`)
}

/**
 * A secret's attachment rows with their metadata decrypted.
 *
 * @param {object} store The attachment store.
 * @param {string} secretId The secret id.
 * @return {Promise<Array<object>>}
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */
async function decryptedListing(store, secretId) {
	const response = await axios.get(attachmentsUrl(secretId))
	const rows = []
	for (const row of response.data || []) {
		const meta = await decryptedMetadata(store, row)
		rows.push({
			id: row.id,
			sizeBytes: row.sizeBytes,
			createdAt: row.createdAt,
			wrappedFileKey: row.wrappedFileKey,
			filename: meta.filename ?? null,
			contentType: meta.contentType ?? null,
		})
	}
	return rows
}

/**
 * A listed row's filename and content type. Honest failure: undecryptable
 * metadata shows as invalid.
 *
 * @param {object} store The attachment store.
 * @param {object} row The listed row.
 * @return {Promise<{filename: ?string, contentType: ?string}>}
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */
async function decryptedMetadata(store, row) {
	try {
		const key = await store.unwrapFileKey(row.wrappedFileKey)
		const metaBytes = await aesDecryptBytes(
			key,
			fromBase64(row.encryptedMetadata),
		)
		return JSON.parse(new TextDecoder().decode(metaBytes))
	} catch {
		return { filename: null, contentType: null }
	}
}

/**
 * Encrypt a file for upload: the blob and its metadata under a fresh file
 * key, and that key wrapped under the session certificate.
 *
 * @param {File} file The picked file.
 * @param {object} session The session store (holds the certificate).
 * @return {Promise<{blob: string, encryptedMetadata: string, wrappedFileKey: string}>}
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
 */
async function sealedUpload(file, session) {
	const fileKey = await crypto.subtle.generateKey(
		{ name: 'AES-GCM', length: 256 },
		true,
		['encrypt', 'decrypt'],
	)
	const bytes = new Uint8Array(await file.arrayBuffer())
	const blob = await aesEncryptBytes(fileKey, bytes)
	const encryptedMetadata = await encryptedMetadataOf(file, fileKey)
	const rawKey = new Uint8Array(await crypto.subtle.exportKey('raw', fileKey))
	const publicKey = await importPublicKey(session.certificate)
	const wrappedFileKey = await rsaEncrypt(toBase64(rawKey), publicKey)
	return { blob: toBase64(blob), encryptedMetadata, wrappedFileKey }
}

/**
 * @param {File} file The picked file.
 * @param {CryptoKey} fileKey The file key.
 * @return {Promise<string>} Its filename and content type, encrypted.
 */
async function encryptedMetadataOf(file, fileKey) {
	const metaBytes = new TextEncoder().encode(
		JSON.stringify({
			filename: file.name,
			contentType: file.type || 'application/octet-stream',
		}),
	)
	return toBase64(await aesEncryptBytes(fileKey, metaBytes))
}

/**
 * Fetch an attachment's blob and decrypt it with its file key.
 *
 * @param {object} store The attachment store.
 * @param {object} attachment The listed attachment row.
 * @return {Promise<Uint8Array>} The plaintext bytes.
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */
async function decryptedBlob(store, attachment) {
	const response = await axios.get(
		generateUrl(`/apps/keepiq/api/v1/attachments/${attachment.id}/blob`),
	)
	const key = await store.unwrapFileKey(attachment.wrappedFileKey)
	return aesDecryptBytes(key, fromBase64(response.data.blob))
}

/**
 * The decrypted content types an attachment may be previewed as, and how.
 * SVG is left out on purpose: it can carry script.
 *
 * @type {Readonly<{[contentType: string]: 'image'|'pdf'|'text'}>}
 */
export const PREVIEW_KINDS = Object.freeze({
	'image/png': 'image',
	'image/jpeg': 'image',
	'image/gif': 'image',
	'image/webp': 'image',
	'application/pdf': 'pdf',
	'text/plain': 'text',
})

/**
 * How an attachment of this content type is previewed, or null when it is
 * not: only the types in PREVIEW_KINDS, without parameters, in any case.
 *
 * @param {?string} contentType The decrypted content type.
 * @return {'image'|'pdf'|'text'|null}
 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-preview-an-attachment-in-the-browser
 */
export function previewKind(contentType) {
	const type = String(contentType || '').split(';')[0].trim().toLowerCase()
	return Object.hasOwn(PREVIEW_KINDS, type) ? PREVIEW_KINDS[type] : null
}

/**
 * Trigger a local save of decrypted bytes under the attachment's name.
 *
 * @param {Uint8Array} plaintext The plaintext bytes.
 * @param {object} attachment The listed attachment row.
 * @return {void}
 */
function saveLocally(plaintext, attachment) {
	const blob = new Blob([plaintext], {
		type: attachment.contentType || 'application/octet-stream',
	})
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = attachment.filename || 'attachment'
	a.click()
	URL.revokeObjectURL(url)
}

/**
 * Re-wrap one attachment's file key for the recipient and POST the grant.
 *
 * @param {{session: object, recipientKey: CryptoKey, copySecretId: string, recipientId: string}} grant The re-grant.
 * @param {object} row The listed attachment row.
 * @return {Promise<void>}
 * @spec openspec/specs/encrypted-attachments/spec.md#scenario-sharing-re-wraps-the-key-not-the-blob
 */
async function postGrant(grant, row) {
	const rawBase64 = await rsaDecrypt(row.wrappedFileKey, grant.session.cryptoKey)
	const rewrapped = await rsaEncrypt(rawBase64, grant.recipientKey)
	await axios.post(
		generateUrl(`/apps/keepiq/api/v1/attachments/${row.id}/grants`),
		{
			copySecretId: grant.copySecretId,
			recipientId: grant.recipientId,
			wrappedFileKey: rewrapped,
			recipientType: 'user',
		},
	)
}

export const useAttachmentStore = defineStore('attachment', {
	state: () => ({
		/** @type {Array<object>} Attachments of the focused secret (decrypted metadata). */
		attachments: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
		/**
		 * @type {number} Monotonic id of the newest list request. A response
		 * whose id has been superseded does not write to the store — the
		 * sidebar swaps `:id` without remounting, so a slow response for the
		 * previous secret can otherwise land after the current one.
		 */
		listRequestId: 0,
	}),

	actions: {
		/**
		 * Unwrap an attachment's file key with the session CryptoKey.
		 *
		 * @param {string} wrappedFileKey The RSA-wrapped base64 raw key.
		 * @return {Promise<CryptoKey>} The AES-GCM file key.
		 *
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
		 */
		async unwrapFileKey(wrappedFileKey) {
			const session = useSessionStore()
			if (!session.cryptoKey) {
				throw new Error('Vault is locked')
			}
			const rawBase64 = await rsaDecrypt(wrappedFileKey, session.cryptoKey)
			return crypto.subtle.importKey(
				'raw',
				fromBase64(rawBase64),
				{ name: 'AES-GCM' },
				false,
				['encrypt', 'decrypt'],
			)
		},

		/**
		 * List a secret's attachments and decrypt their metadata.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
		 */
		async fetchAttachments(secretId) {
			const requestId = ++this.listRequestId
			this.loading = true
			this.error = null
			try {
				const rows = await decryptedListing(this, secretId)
				if (requestId === this.listRequestId) {
					this.attachments = rows
				}
			} catch (e) {
				const message = failureText(e, 'Failed to load attachments')
				if (requestId === this.listRequestId) {
					this.error = message
				}
				throw e
			} finally {
				if (requestId === this.listRequestId) {
					this.loading = false
				}
			}
		},

		/**
		 * Encrypt and upload a file against an owned secret.
		 *
		 * @param {string} secretId The owning secret id.
		 * @param {File} file The picked file.
		 * @return {Promise<void>}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
		 */
		async upload(secretId, file) {
			const session = useSessionStore()
			if (!session.certificate) {
				throw new Error('Vault is locked')
			}
			this.loading = true
			this.error = null
			try {
				await axios.post(
					attachmentsUrl(secretId),
					await sealedUpload(file, session),
				)
				await this.fetchAttachments(secretId)
			} catch (e) {
				this.error = failureText(e, 'Upload failed')
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Download and decrypt an attachment, then trigger a local save.
		 *
		 * @param {object} attachment The listed attachment row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
		 */
		async download(attachment) {
			this.loading = true
			this.error = null
			try {
				saveLocally(await decryptedBlob(this, attachment), attachment)
			} catch (e) {
				this.error = failureText(e, 'Download failed')
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Decrypt an attachment in the browser for a preview: an object URL
		 * of the plaintext for an image or a PDF, the text itself for plain
		 * text. Nothing is saved to the device, and the caller revokes the URL
		 * when the preview closes. A type that may not be previewed is refused
		 * before anything is fetched.
		 *
		 * @param {object} attachment The listed attachment row.
		 * @return {Promise<{kind: 'image'|'pdf'|'text', url: ?string, text: ?string}>}
		 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-preview-an-attachment-in-the-browser
		 */
		async preview(attachment) {
			const kind = previewKind(attachment?.contentType)
			if (kind === null) {
				throw new Error('This file type cannot be previewed')
			}
			this.loading = true
			this.error = null
			try {
				const plaintext = await decryptedBlob(this, attachment)
				if (kind === 'text') {
					return { kind, url: null, text: new TextDecoder().decode(plaintext) }
				}
				const type = String(attachment.contentType).split(';')[0].trim().toLowerCase()
				return { kind, url: URL.createObjectURL(new Blob([plaintext], { type })), text: null }
			} catch (e) {
				this.error = failureText(e, 'Preview failed')
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete an attachment (owner-only).
		 *
		 * @param {string} secretId The owning secret id (for the refresh).
		 * @param {string} attachmentId The attachment id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-operations-are-auditable
		 */
		async remove(secretId, attachmentId) {
			this.loading = true
			this.error = null
			try {
				await axios.delete(
					generateUrl(`/apps/keepiq/api/v1/attachments/${attachmentId}`),
				)
				await this.fetchAttachments(secretId)
			} catch (e) {
				this.error = failureText(e, 'Delete failed')
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Re-wrap the file keys of a secret's attachments for a recipient
		 * copy (share flow): unwrap each with the owner's session key,
		 * re-wrap under the recipient certificate, POST the grants. The
		 * blob is never touched (encrypted-attachments §6.3).
		 *
		 * @param {object} regrant The re-grant.
		 * @param {string} regrant.sourceSecretId The owner's secret id.
		 * @param {string} regrant.copySecretId The recipient's copy id.
		 * @param {string} regrant.recipientId The recipient user id.
		 * @param {string} regrant.recipientCertificate The recipient's PEM certificate.
		 * @return {Promise<number>} Grants created.
		 * @spec openspec/specs/encrypted-attachments/spec.md#scenario-sharing-re-wraps-the-key-not-the-blob
		 */
		async regrantForRecipient(regrant) {
			const session = useSessionStore()
			if (!session.cryptoKey) {
				throw new Error('Vault is locked')
			}
			const response = await axios.get(
				attachmentsUrl(regrant.sourceSecretId),
			)
			const grant = {
				session,
				recipientKey: await importPublicKey(regrant.recipientCertificate),
				copySecretId: regrant.copySecretId,
				recipientId: regrant.recipientId,
			}
			let created = 0
			for (const row of response.data || []) {
				await postGrant(grant, row)
				created++
			}
			return created
		},

		/**
		 * Reset the store (secret detail unmount, or a secret switch).
		 *
		 * Bumping the request id retires any list request still in flight,
		 * so its response cannot repopulate the store after the reset. The
		 * spinner is cleared for the same reason: without it, a reset while
		 * a fetch was running left `loading` true with nothing to finish it.
		 *
		 * @return {void}
		 * @spec exclude Store lifecycle; states no attachment behaviour of
		 * its own.
		 */
		reset() {
			this.listRequestId++
			this.attachments = []
			this.error = null
			this.loading = false
		},
	},
})
