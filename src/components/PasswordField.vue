<template>
	<div class="keepiq-password-field">
		<!-- v9 NcInputField models through `modelValue` and has NO `value` prop.
		     A `:value` binding leaves `modelValue` undefined (it is declared
		     `required`), the component throws
		     "Cannot read properties of undefined (reading 'toString')", and the
		     <input> never renders — the wrapper div is still there, so it looks
		     present. No lint rule catches this. -->
		<!-- A revealed multi-line value, such as a certificate or a private
		     key, keeps its line breaks: a one-line input would join them. -->
		<NcTextArea
			v-if="revealed && multiline"
			class="keepiq-password-field__multiline"
			:modelValue="displayValue"
			:label="label"
			:rows="rows"
			readonly
			spellcheck="false" />
		<NcInputField
			v-else
			:modelValue="displayValue"
			:label="label"
			:type="revealed ? 'text' : 'password'"
			:readOnly="true" />
		<NcButton
			v-if="!useOnly && !alwaysShown"
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
import { NcButton, NcInputField, NcTextArea } from '@nextcloud/vue'
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
		NcTextArea,
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

		/** The value spans several lines, such as a certificate or a key. */
		multiline: {
			type: Boolean,
			default: false,
		},

		/**
		 * The value is public by design, such as a certificate: it is shown
		 * at once and has no show/hide toggle.
		 */
		alwaysShown: {
			type: Boolean,
			default: false,
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

		/**
		 * The text area's height: the value's lines plus one, at least ten
		 * so a short key or certificate still reads as a block, at most
		 * twenty.
		 *
		 * @spec exclude Presentation sizing: no domain behaviour.
		 */
		rows() {
			const lines = (this.plain ?? '').split('\n').length + 1
			return Math.min(Math.max(lines, 10), 20)
		},
	},

	/**
	 * Show a public value at once; a use-only value is never revealed.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-the-detail-sidebar-shows-the-fields-of-the-type
	 */
	async mounted() {
		if (this.alwaysShown && !this.useOnly) {
			this.plain = await this.resolve()
			this.revealed = true
		}
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

/* The buttons keep their room; the field takes what is left and may shrink
   below its content's width, so a long line never pushes them out. */
.keepiq-password-field > * {
	flex: 0 0 auto;
}

.keepiq-password-field :deep(.input-field),
.keepiq-password-field__multiline {
	flex: 1 1 0;
	min-width: 0;
}

/* Only taller, never wider: a wider text area would run off the screen. */
.keepiq-password-field__multiline :deep(textarea) {
	max-width: 100%;
	font-family: monospace;
	font-size: 13px;
	resize: vertical;
}
</style>
