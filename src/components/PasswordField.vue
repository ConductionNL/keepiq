<template>
	<div class="keepiq-password-field">
		<!-- v9 NcInputField models through `modelValue` and has NO `value` prop.
		     A `:value` binding leaves `modelValue` undefined (it is declared
		     `required`), the component throws
		     "Cannot read properties of undefined (reading 'toString')", and the
		     <input> never renders — the wrapper div is still there, so it looks
		     present. No lint rule catches this. -->
		<NcInputField
			:modelValue="displayValue"
			:label="label"
			:type="revealed ? 'text' : 'password'"
			:readOnly="true" />
		<NcButton
			variant="tertiary"
			:aria-label="revealed ? t('keepiq', 'Hide') : t('keepiq', 'Show')"
			:title="revealed ? t('keepiq', 'Hide') : t('keepiq', 'Show')"
			@click="toggle">
			<template #icon>
				<EyeOff v-if="revealed" :size="20" />
				<Eye v-else :size="20" />
			</template>
		</NcButton>
		<CopyButton :resolve="resolvePlain" :label="t('keepiq', 'Copy password')" />
	</div>
</template>

<script>
import { NcButton, NcInputField } from '@nextcloud/vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import CopyButton from './CopyButton.vue'

/**
 * A masked key/password field with a show/hide eye toggle and a copy button.
 *
 * The plaintext value is produced lazily by the `resolve` async function so
 * that decryption only happens on the first reveal or copy (a performance
 * optimisation for list rows). Fields default to masked.
 */
export default {
	name: 'PasswordField',

	components: {
		NcButton,
		NcInputField,
		Eye,
		EyeOff,
		CopyButton,
	},

	props: {
		/** The field label. */
		label: {
			type: String,
			default() {
				return t('keepiq', 'Password')
			},
		},

		/** An async resolver that returns the plaintext value (e.g. decrypt). */
		resolve: {
			type: Function,
			required: true,
		},
	},

	data() {
		return {
			revealed: false,
			plain: null,
			masked: '••••••••••',
		}
	},

	computed: {
		displayValue() {
			return this.revealed ? (this.plain ?? '') : this.masked
		},
	},

	methods: {
		t,

		/**
		 * Toggle the visibility, decrypting on the first reveal.
		 *
		 * @return {Promise<void>}
		 */
		async toggle() {
			if (!this.revealed && this.plain === null) {
				this.plain = await this.resolve()
			}
			this.revealed = !this.revealed
		},

		/**
		 * Resolve the plaintext for the copy button, decrypting if needed.
		 *
		 * @return {Promise<string>}
		 */
		async resolvePlain() {
			if (this.plain === null) {
				this.plain = await this.resolve()
			}
			return this.plain
		},
	},
}
</script>

<style scoped>
.keepiq-password-field {
	display: flex;
	align-items: flex-end;
	gap: 4px;
}

.keepiq-password-field :deep(.input-field) {
	flex: 1 1 auto;
}
</style>
