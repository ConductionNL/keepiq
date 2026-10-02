<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The Authenticator key field of a login in the create and edit dialogs
  (vault-login-totp-codes). The seed is kept inside the login's encrypted
  additional fields under `totp`; a QR image is decoded in the browser and
  never uploaded.

  @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
-->
<template>
	<div class="secret-totp-seed-field">
		<div class="secret-totp-seed-field__row">
			<NcPasswordField
				:modelValue="modelValue"
				class="secret-totp-seed-field__input"
				:label="t('keepiq', 'Authenticator key (optional)')"
				:disabled="disabled"
				data-testid="secret-totp-seed"
				@update:modelValue="$emit('update:modelValue', $event)" />
			<NcButton
				variant="secondary"
				:disabled="disabled"
				data-testid="secret-totp-qr-button"
				@click="$refs.file.click()">
				{{ t('keepiq', 'Read QR image') }}
			</NcButton>
			<input
				ref="file"
				type="file"
				accept="image/*"
				class="hidden-visually"
				tabindex="-1"
				:aria-label="t('keepiq', 'QR image')"
				data-testid="secret-totp-qr-file"
				@change="onFile" />
		</div>
		<p v-if="qrError" class="secret-totp-seed-field__error" role="alert">
			{{ qrError }}
		</p>
		<p class="secret-totp-seed-field__help">
			{{
				t(
					'keepiq',
					'Paste the otpauth link or the key the site shows when you turn on two-step sign-in. Keeping it here puts both factors in one item.',
				)
			}}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcPasswordField } from '@nextcloud/vue'
import { decodeQrFile } from '../totp/qr.js'

export default {
	name: 'SecretTotpSeedField',

	components: {
		NcButton,
		NcPasswordField,
	},

	props: {
		/** The seed: an otpauth URI or a base32 key. */
		modelValue: {
			type: String,
			default: '',
		},

		/** Whether editing is blocked. */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			qrError: '',
		}
	},

	methods: {
		/**
		 * Decode the picked image and take its text as the seed.
		 *
		 * @param {Event} event The file input change.
		 * @return {Promise<void>}
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		async onFile(event) {
			const file = event?.target?.files?.[0]
			if (!file) {
				return
			}
			const text = await decodeQrFile(file)
			event.target.value = ''
			if (text === '') {
				this.qrError = t('keepiq', 'No QR code was found in that image.')
				return
			}
			this.qrError = ''
			this.$emit('update:modelValue', text)
		},
	},
}
</script>

<style scoped>
.secret-totp-seed-field__row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.secret-totp-seed-field__input {
	flex: 1;
}

.secret-totp-seed-field__help {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.secret-totp-seed-field__error {
	color: var(--color-error-text);
	margin: 4px 0 0;
}
</style>
