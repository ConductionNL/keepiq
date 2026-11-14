<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The SSH key section of the create and edit secret dialogs: private key,
  public key and fingerprint, with Generate key pair and Import from file.
  Shown by SecretPayloadFields for the `ssh_key` type, in place of the
  generic value field, the way the card and identity types get their own.

  The private key is the secret's value; the public key and fingerprint go
  into the same encrypted additional-fields blob. Both actions run in the
  browser: generation uses WebCrypto and a file is read with the File API,
  so the private key is never sent anywhere before the store encrypts it.
  The fingerprint is derived from the public key and cannot be typed.

  The dialog owns the values and the errors; this component only edits them
  through update events.

  @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
-->
<template>
	<section class="keepiq-ssh-key" data-testid="ssh-key-fields">
		<NcTextArea
			:modelValue="privateKey"
			:label="t('keepiq', 'Private key')"
			:error="privateKeyError !== ''"
			:helperText="privateKeyError"
			:disabled="disabled || busy"
			resize="vertical"
			rows="6"
			spellcheck="false"
			autocomplete="off"
			class="keepiq-ssh-key__mono"
			data-testid="ssh-private-key"
			@update:modelValue="onPrivateKey" />

		<div class="keepiq-ssh-key__actions">
			<NcButton
				variant="secondary"
				:disabled="disabled || busy"
				data-testid="ssh-generate"
				@click="onGenerate">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<KeyPlus v-else :size="20" />
				</template>
				{{ t('keepiq', 'Generate key pair') }}
			</NcButton>
			<NcButton
				variant="secondary"
				:disabled="disabled || busy"
				data-testid="ssh-import"
				@click="$refs.file.click()">
				<template #icon>
					<FileUpload :size="20" />
				</template>
				{{ t('keepiq', 'Import from file') }}
			</NcButton>
			<input
				ref="file"
				type="file"
				class="keepiq-ssh-key__file"
				data-testid="ssh-import-file"
				@change="onFile" />
		</div>

		<p
			v-if="actionError"
			class="keepiq-ssh-key__error"
			role="alert"
			data-testid="ssh-action-error">
			{{ actionError }}
		</p>

		<NcTextField
			:modelValue="publicKey"
			:label="t('keepiq', 'Public key')"
			:error="publicKeyError !== ''"
			:helperText="publicKeyError"
			:disabled="disabled || busy"
			spellcheck="false"
			autocomplete="off"
			class="keepiq-ssh-key__mono"
			data-testid="ssh-public-key"
			@update:modelValue="onPublicKey" />

		<NcTextField
			:modelValue="fingerprint"
			:label="t('keepiq', 'Fingerprint')"
			:helperText="t('keepiq', 'Derived from the public key.')"
			readonly
			class="keepiq-ssh-key__mono"
			data-testid="ssh-fingerprint" />
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcTextArea, NcTextField } from '@nextcloud/vue'
import FileUpload from 'vue-material-design-icons/FileUpload.vue'
import KeyPlus from 'vue-material-design-icons/KeyPlus.vue'
import {
	fingerprintOf,
	generateKeyPair,
	isPublicKey,
	parsePrivateKey,
} from '../ssh/sshKey.js'

/** A private key file larger than this is not a key; it is not read. */
const MAX_KEY_FILE_BYTES = 64 * 1024

/**
 * Read a file as text in the browser.
 *
 * @param {File} file The file.
 * @return {Promise<string>}
 */
function readText(file) {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => resolve(String(reader.result ?? ''))
		reader.onerror = () => reject(reader.error)
		reader.readAsText(file)
	})
}

export default {
	name: 'SshKeyFields',

	components: {
		FileUpload,
		KeyPlus,
		NcButton,
		NcLoadingIcon,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The private key text (the secret's value). */
		privateKey: {
			type: String,
			default: '',
		},

		/** The OpenSSH public key line. */
		publicKey: {
			type: String,
			default: '',
		},

		/** The derived SHA256 fingerprint. */
		fingerprint: {
			type: String,
			default: '',
		},

		/** Why the private key was refused on save, '' when it was not. */
		privateKeyError: {
			type: String,
			default: '',
		},

		/** Why the public key was refused on save, '' when it was not. */
		publicKeyError: {
			type: String,
			default: '',
		},

		/** Whether editing is blocked (a save in flight). */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:privateKey', 'update:keys'],

	data() {
		return {
			busy: false,
			actionError: '',
			/** Bumped by every edit, so a slow derivation of older text is dropped. */
			revision: 0,
		}
	},

	methods: {
		t,

		/**
		 * Take a new private key and fill in the public key and fingerprint
		 * derived from it, when it gives one.
		 *
		 * @param {string} text The private key text.
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
		 */
		async onPrivateKey(text) {
			this.$emit('update:privateKey', text)
			const revision = ++this.revision
			let derived = null
			try {
				derived = (await parsePrivateKey(text)).publicKey
			} catch {
				// Not (yet) a key: the save refuses it with a field error.
			}
			if (derived !== null && revision === this.revision) {
				await this.emitKeys(derived, revision)
			}
		},

		/**
		 * Take a typed or pasted public key and derive its fingerprint.
		 *
		 * @param {string} text The public key line.
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
		 */
		async onPublicKey(text) {
			const revision = ++this.revision
			await this.emitKeys(text, revision)
		},

		/**
		 * Emit a public key with the fingerprint derived from it ('' when it
		 * is not a public key), unless a newer edit came in meanwhile.
		 *
		 * @param {string} publicKey The public key line.
		 * @param {number} revision The edit this belongs to.
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
		 */
		async emitKeys(publicKey, revision) {
			const fingerprint = isPublicKey(publicKey)
				? await fingerprintOf(publicKey)
				: ''
			if (revision === this.revision) {
				this.$emit('update:keys', { publicKey, fingerprint })
			}
		},

		/**
		 * Generate a key pair in the browser and fill in all three fields.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
		 */
		async onGenerate() {
			this.busy = true
			this.actionError = ''
			const revision = ++this.revision
			try {
				const pair = await generateKeyPair()
				if (revision !== this.revision) {
					return
				}
				this.$emit('update:privateKey', pair.privateKey)
				this.$emit('update:keys', {
					publicKey: pair.publicKey,
					fingerprint: pair.fingerprint,
				})
			} catch {
				this.actionError = t(
					'keepiq',
					'This browser could not generate a key pair.',
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Read a chosen private key file in the browser and fill in the
		 * private key, then the public key and fingerprint derived from it.
		 *
		 * @param {Event} event The file input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
		 */
		async onFile(event) {
			const input = event.target
			const file = input?.files?.[0]
			if (!file) {
				return
			}
			this.actionError = ''
			try {
				if (file.size > MAX_KEY_FILE_BYTES) {
					throw new Error('too large')
				}
				await this.onPrivateKey(await readText(file))
			} catch {
				this.actionError = t('keepiq', 'The file could not be read.')
			} finally {
				input.value = ''
			}
		},
	},
}
</script>

<style scoped>
.keepiq-ssh-key {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.keepiq-ssh-key__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.keepiq-ssh-key__file {
	display: none;
}

.keepiq-ssh-key__mono :deep(textarea),
.keepiq-ssh-key__mono :deep(input) {
	font-family: var(--font-face-monospace, monospace);
}

.keepiq-ssh-key__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
