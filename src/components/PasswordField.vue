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
			v-if="!useOnly"
			variant="tertiary"
			:aria-label="revealed ? t('keepiq', 'Hide') : t('keepiq', 'Show')"
			:title="revealed ? t('keepiq', 'Hide') : t('keepiq', 'Show')"
			@click="toggle">
			<template #icon>
				<EyeOff v-if="revealed" :size="20" />
				<Eye v-else :size="20" />
			</template>
		</NcButton>
		<CopyButton
			:resolve="resolvePlain"
			:useOnly="useOnly"
			:label="t('keepiq', 'Copy password')" />
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
			/**
			 * @return {string} The translated fallback label.
			 *
			 * @spec exclude A Vue prop `default()` factory returning a translated
			 *   fallback string — it declares a default value, not behaviour. The
			 *   masked-reveal behaviour this labels is specified in
			 *   openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal.
			 */
			default() {
				return t('keepiq', 'Password')
			},
		},

		/**
		 * A use-only value stays masked: no reveal toggle, no copy, and the
		 * resolver is never called (sharing-use-only-and-expiring-shares D3).
		 */
		useOnly: {
			type: Boolean,
			default: false,
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
		/**
		 * @spec exclude Presentation state: picks the masked or revealed string for display.
		 */
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
		 * @spec openspec/specs/use-only-shares/spec.md#requirement-keepiqs-clients-never-reveal-a-use-only-value
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		async toggle() {
			if (this.useOnly) {
				return
			}
			if (!this.revealed && this.plain === null) {
				this.plain = await this.resolve()
			}
			this.revealed = !this.revealed
		},

		/**
		 * Resolve the plaintext for the copy button, decrypting if needed.
		 *
		 * @return {Promise<string>}
		 * @spec openspec/specs/use-only-shares/spec.md#requirement-keepiqs-clients-never-reveal-a-use-only-value
		 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
		 */
		async resolvePlain() {
			if (this.useOnly) {
				throw new Error(
					t(
						'keepiq',
						'This secret is use-only. Sign in through the Keepiq browser extension.',
					),
				)
			}
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
