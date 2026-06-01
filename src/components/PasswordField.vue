<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Masked password/key field with a show/hide toggle. Defaults to masked; the
  first reveal triggers on-demand decryption via the `reveal` callback so list
  rows do not decrypt until the user asks.
-->
<template>
	<div class="password-field">
		<NcTextField
			:value="displayValue"
			:label="label"
			:type="visible ? 'text' : 'password'"
			:readonly="true"
			:show-trailing-button="false" />
		<NcButton
			type="tertiary"
			:aria-label="visible ? t('doriath', 'Hide') : t('doriath', 'Show')"
			@click="toggle">
			<template #icon>
				<EyeOffIcon v-if="visible" :size="20" />
				<EyeIcon v-else :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import EyeOffIcon from 'vue-material-design-icons/EyeOff.vue'

export default {
	name: 'PasswordField',

	components: {
		NcButton,
		NcTextField,
		EyeIcon,
		EyeOffIcon,
	},

	props: {
		/** Field label. */
		label: {
			type: String,
			default: '',
		},
		/**
		 * Already-decrypted value, if available. When omitted, `reveal` is
		 * called on first show to produce the plaintext.
		 */
		value: {
			type: String,
			default: '',
		},
		/**
		 * Callback returning a Promise of the plaintext, invoked on first
		 * reveal when no `value` was provided.
		 */
		reveal: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			visible: false,
			revealed: this.value,
		}
	},

	computed: {
		/**
		 * The value shown in the field: dots when hidden, plaintext when shown.
		 *
		 * @return {string} The display value.
		 */
		displayValue() {
			if (!this.visible) {
				return '••••••••••'
			}
			return this.revealed || ''
		},
	},

	methods: {
		/**
		 * Toggle visibility, decrypting on the first reveal if needed.
		 */
		async toggle() {
			if (!this.visible && !this.revealed && this.reveal) {
				this.revealed = await this.reveal()
			}
			this.visible = !this.visible
		},
	},
}
</script>

<style scoped>
.password-field {
	display: flex;
	align-items: flex-end;
	gap: 0.25rem;
}
</style>
