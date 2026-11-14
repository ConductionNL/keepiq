<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  What protects the vault, in plain words with the technical names an
  auditor recognises next to them. Every algorithm and number comes from
  CRYPTO_PARAMETERS (the crypto modules' own exports) and the vault
  certificate's facts from the server-parsed inventory, so this section
  cannot say something the code does not do. Only public parameters and
  public certificate fields are shown.
-->
<template>
	<section class="vault-encryption-details" data-testid="vault-encryption-details">
		<h3 class="vault-encryption-details__heading">
			{{ t('keepiq', 'How your vault is protected') }}
		</h3>
		<dl class="vault-encryption-details__list">
			<template v-for="line in lines" :key="line.id">
				<dt :data-testid="`vault-encryption-${line.id}`">
					{{ line.label }}
				</dt>
				<dd>
					{{ line.text }}
					<code class="vault-encryption-details__technical">{{ line.technical }}</code>
				</dd>
			</template>
		</dl>

		<h4 class="vault-encryption-details__heading">
			{{ t('keepiq', 'Your vault certificate') }}
		</h4>
		<dl
			v-if="certificate"
			class="vault-encryption-details__list"
			data-testid="vault-encryption-certificate">
			<template v-for="fact in certificateFacts" :key="fact.id">
				<dt>{{ fact.label }}</dt>
				<dd>{{ fact.value }}</dd>
			</template>
		</dl>
		<p v-else class="vault-encryption-details__muted">
			{{
				certificateStore.loading
					? t('keepiq', 'Loading certificate details…')
					: t('keepiq', 'No certificate details are available for this vault.')
			}}
		</p>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { CRYPTO_PARAMETERS, technicalNames } from '../../crypto/parameters.js'
import { useCertificateStore } from '../../store/modules/certificate.js'

/**
 * The vault's encryption overview for the Encryption section of the user
 * settings.
 *
 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-user-sees-what-protects-the-vault
 */
export default {
	name: 'VaultEncryptionDetails',

	props: {
		/** The id of the user's active encryption suite. */
		suiteId: {
			type: String,
			default: null,
		},

		/** The parameters to describe; defaults to the crypto code's own. */
		parameters: {
			type: Object,
			default: () => CRYPTO_PARAMETERS,
		},
	},

	computed: {
		/**
		 * The certificate store holding the inventory.
		 *
		 * @return {object}
		 * @spec exclude Store-ref passthrough — returns the Pinia certificate store with no domain logic.
		 */
		certificateStore() {
			return useCertificateStore()
		},

		/**
		 * One line per protected thing: plain words, then the technical names.
		 *
		 * @return {Array<{id: string, label: string, text: string, technical: string}>}
		 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-user-sees-what-protects-the-vault
		 */
		lines() {
			const { secretValues, privateKey, attachments, passwordProtected } = this.parameters
			const names = technicalNames(this.parameters)
			return [
				{
					id: 'secret-values',
					label: t('keepiq', 'Passwords and other secret values'),
					text: t(
						'keepiq',
						'Encrypted in your browser with your own {bits}-bit key pair before they are stored.',
						{ bits: secretValues.keyBits },
					),

					technical: names.secretValues,
				},
				{
					id: 'private-key',
					label: t('keepiq', 'Your private key'),
					text: t(
						'keepiq',
						'Locked with a key made from your master password, which is stretched {iterations} times so guessing it is slow.',
						{ iterations: privateKey.iterations.toLocaleString() },
					),

					technical: names.privateKey,
				},
				{
					id: 'attachments',
					label: t('keepiq', 'Attachments'),
					text: t(
						'keepiq',
						'Each file gets its own {bits}-bit key, which is locked with your key pair.',
						{ bits: attachments.keyBits },
					),

					technical: names.attachments,
				},
				{
					id: 'password-protected',
					label: t('keepiq', 'Backups, link shares and sends'),
					text: t(
						'keepiq',
						'Protected by their own password, stretched with a method that needs {memory} MiB of memory per guess.',
						{ memory: passwordProtected.memoryKiB / 1024 },
					),

					technical: names.passwordProtected,
				},
			]
		},

		/**
		 * The inventory row of the user's vault certificate, if loaded.
		 *
		 * @return {object|null} Its server-parsed metadata.
		 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-vault-certificate-shows-its-key-and-signature-algorithm
		 */
		certificate() {
			const suites = this.certificateStore.inventory?.suites || []
			const row = suites.find((s) => s.id === this.suiteId)
			return row?.metadata || null
		},

		/**
		 * The certificate's public facts, in display order; missing ones show a dash.
		 *
		 * @return {Array<{id: string, label: string, value: string}>}
		 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-vault-certificate-shows-its-key-and-signature-algorithm
		 */
		certificateFacts() {
			const c = this.certificate
			const key = c.keyType && c.keyBits
				? t('keepiq', '{type}, {bits}-bit', { type: c.keyType, bits: c.keyBits })
				: '—'
			return [
				{ id: 'key', label: t('keepiq', 'Key'), value: key },
				{ id: 'signature', label: t('keepiq', 'Signature algorithm'), value: c.signatureAlgorithm || '—' },
				{ id: 'fingerprint', label: t('keepiq', 'Fingerprint'), value: c.fingerprintSha256 || '—' },
				{ id: 'issuer', label: t('keepiq', 'Issuer'), value: c.issuer || '—' },
				{
					id: 'validity',
					label: t('keepiq', 'Valid'),
					value: c.notBefore && c.notAfter
						? t('keepiq', '{from} to {until}', {
							from: new Date(c.notBefore).toLocaleDateString(),
							until: new Date(c.notAfter).toLocaleDateString(),
						})
						: '—',
				},
			]
		},
	},

	mounted() {
		if (this.suiteId && !this.certificateStore.inventory && !this.certificateStore.loading) {
			this.certificateStore.fetchInventory()
		}
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.vault-encryption-details__heading {
	margin: 12px 0 4px;
}

.vault-encryption-details__list {
	display: grid;
	grid-template-columns: minmax(10em, max-content) 1fr;
	gap: 4px 16px;
	margin: 0;
}

.vault-encryption-details__list dt {
	font-weight: bold;
}

.vault-encryption-details__list dd {
	margin: 0;
}

.vault-encryption-details__technical {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	overflow-wrap: anywhere;
}

.vault-encryption-details__muted {
	color: var(--color-text-maxcontrast);
}
</style>
