<template>
	<NcDialog
		:name="t('doriath', 'Generate key')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="key-generator-modal">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<fieldset
				:disabled="regex.length > 0"
				class="key-generator-modal__basic">
				<NcInputField
					v-model="lengthInput"
					type="number"
					:label="t('doriath', 'Length')"
					:min="minLength"
					:max="maxLength" />
				<p
					v-if="policyFloorActive"
					class="key-generator-modal__policy-hint"
					data-testid="policy-floor-hint">
					{{
						t('doriath', 'Locked by org policy: minimum length {min}', {
							min: minLength,
						})
					}}
				</p>

				<NcCheckboxRadioSwitch
					v-model="includeSpecialCharacters"
					type="switch"
					:disabled="symbolLocked">
					{{
						symbolLocked
							? t(
									'doriath',
									'Include special characters (locked by org policy)',
								)
							: t('doriath', 'Include special characters')
					}}
				</NcCheckboxRadioSwitch>

				<NcInputField
					v-model="excludedCharacters"
					:label="t('doriath', 'Exclude characters')" />
			</fieldset>

			<details class="key-generator-modal__advanced">
				<summary>{{ t('doriath', 'Advanced') }}</summary>
				<NcInputField
					v-model="regex"
					:label="t('doriath', 'Regex pattern')"
					:helper-text="
						t(
							'doriath',
							'When set, overrides length, special characters and exclusions.',
						)
					" />
			</details>

			<div v-if="generatedKey" class="key-generator-modal__preview">
				<!-- v9 models through `modelValue`; `:value` is a dead binding. -->
				<NcInputField
					:model-value="generatedKey"
					:label="t('doriath', 'Generated key')"
					:read-only="true"
					:show-trailing-button="true"
					:trailing-button-label="t('doriath', 'Copy to clipboard')"
					@trailing-button-click="copyToClipboard">
					<template #trailing-button-icon>
						<ContentCopy :size="20" />
					</template>
				</NcInputField>
			</div>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="loading" @click="generate">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Dice5 v-else :size="20" />
				</template>
				{{ t('doriath', 'Generate') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!generatedKey" @click="use">
				{{ t('doriath', 'Use') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Dice5 from 'vue-material-design-icons/Dice5.vue'
import { fetchPolicy } from '../policy/policy.js'

export default {
	name: 'KeyGeneratorModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		ContentCopy,
		Dice5,
	},

	props: {
		/**
		 * Whether the dialog is open.
		 */
		open: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			lengthInput: 16,
			includeSpecialCharacters: true,
			excludedCharacters: '',
			regex: '',
			generatedKey: '',
			loading: false,
			error: null,
			minLength: 8,
			maxLength: 128,
			policyFloorActive: false,
			symbolLocked: false,
		}
	},

	/**
	 * Clamp the controls to the org policy floor (org-password-policies
	 * §5.2) — the server clamp stays authoritative regardless.
	 */
	async mounted() {
		const policy = await fetchPolicy()
		if (policy?.policy_enabled === true) {
			const floor = Number.parseInt(policy.generator_min_length, 10) || 0
			if (floor > this.minLength) {
				this.minLength = floor
				this.policyFloorActive = true
				if (Number(this.lengthInput) < floor) {
					this.lengthInput = floor
				}
			}
			if (policy.generator_require_symbol === true) {
				this.includeSpecialCharacters = true
				this.symbolLocked = true
			}
		}
	},

	methods: {
		/**
		 * Proxy the dialog's open state to the parent and reset on close.
		 *
		 * @param {boolean} value The new open state.
		 */
		onUpdateOpen(value) {
			if (value === false) {
				this.reset()
			}
			this.$emit('update:open', value)
		},

		/**
		 * Reset transient state (preview + error) when the dialog closes.
		 */
		reset() {
			this.generatedKey = ''
			this.error = null
			this.loading = false
		},

		/**
		 * Call the server-side generator and display the result.
		 */
		async generate() {
			this.loading = true
			this.error = null

			try {
				const payload = this.regex
					? { regex: this.regex }
					: {
							length: Number(this.lengthInput),
							includeSpecialCharacters: this.includeSpecialCharacters,
							excludedCharacters: this.excludedCharacters,
						}

				const response = await axios.post(
					generateUrl('/apps/doriath/api/v1/generate-key'),
					payload,
				)
				this.generatedKey = response.data.generatedKey
			} catch (e) {
				this.generatedKey = ''
				this.error =
					e?.response?.data?.message
					|| t('doriath', 'Failed to generate key')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Emit the generated value to the parent and close the dialog.
		 */
		use() {
			if (!this.generatedKey) {
				return
			}
			this.$emit('generated', this.generatedKey)
			this.onUpdateOpen(false)
		},

		/**
		 * Copy the generated key to the clipboard.
		 */
		async copyToClipboard() {
			if (!this.generatedKey || !navigator.clipboard) {
				return
			}
			await navigator.clipboard.writeText(this.generatedKey)
		},
	},
}
</script>

<style scoped>
.key-generator-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 8px 8px;
}

.key-generator-modal__basic {
	display: flex;
	flex-direction: column;
	gap: 12px;
	border: none;
	margin: 0;
	padding: 0;
}

.key-generator-modal__advanced summary {
	cursor: pointer;
	padding: 8px 0;
	font-weight: bold;
}

.key-generator-modal__preview {
	margin-top: 8px;
}

.key-generator-modal__policy-hint {
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
	margin: 0;
}
</style>
