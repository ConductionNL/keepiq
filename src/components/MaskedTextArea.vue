<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  A text area for a multi-line secret, such as a private key or a note, that
  starts masked when it holds a value: the value is not in the page until
  Show is pressed. An empty one starts open, so a key can be pasted at once.
  A public value, such as a certificate, is never masked.

  @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
-->
<template>
	<div class="masked-text-area">
		<NcTextArea
			v-if="shown"
			v-bind="$attrs"
			class="masked-text-area__field"
			:modelValue="modelValue"
			:label="label"
			:rows="rows"
			@update:modelValue="onInput" />
		<NcTextField
			v-else
			class="masked-text-area__field"
			modelValue="••••••••••"
			:label="label"
			:error="$attrs.error"
			:helperText="$attrs.helperText"
			readonly
			data-testid="masked-text-area-mask" />
		<NcButton
			v-if="!public"
			variant="tertiary"
			:aria-label="shown ? t('keepiq', 'Hide') : t('keepiq', 'Show')"
			:title="shown ? t('keepiq', 'Hide') : t('keepiq', 'Show')"
			data-testid="masked-text-area-toggle"
			@click="revealed = !shown">
			<template #icon>
				<EyeOff v-if="shown" :size="20" />
				<Eye v-else :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextArea, NcTextField } from '@nextcloud/vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'

export default {
	name: 'MaskedTextArea',

	components: { Eye, EyeOff, NcButton, NcTextArea, NcTextField },

	inheritAttrs: false,

	props: {
		/** The value. */
		modelValue: {
			type: String,
			default: '',
		},

		/** The field's label. */
		label: {
			type: String,
			required: true,
		},

		/** The visible lines. */
		rows: {
			type: [Number, String],
			default: 6,
		},

		/** Whether the value is public, as a certificate is, so never masked. */
		public: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			revealed: this.modelValue === '',
			typed: false,
		}
	},

	computed: {
		/**
		 * Whether the text area shows.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
		 */
		shown() {
			return this.public || this.revealed
		},
	},

	watch: {
		/**
		 * Keep a value that was in view in view when it stops being public,
		 * as a certificate does once a private key is pasted into it.
		 *
		 * @param {boolean} isPublic Whether it is public now.
		 * @param {boolean} wasPublic Whether it was.
		 * @return {void}
		 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
		 */
		public(isPublic, wasPublic) {
			if (wasPublic && !isPublic) {
				this.revealed = true
			}
		},

		/**
		 * Mask a value that arrives after the field opened empty, as a
		 * loaded secret does, unless the user typed it.
		 *
		 * @param {string} value The new value.
		 * @param {string} previous The old value.
		 * @return {void}
		 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
		 */
		modelValue(value, previous) {
			if (!this.typed && previous === '' && value !== '') {
				this.revealed = false
			}
		},
	},

	methods: {
		t,

		/**
		 * Pass a typed value on.
		 *
		 * @param {string} value The value.
		 * @return {void}
		 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
		 */
		onInput(value) {
			this.typed = true
			this.$emit('update:modelValue', value)
		},
	},
}
</script>

<style scoped>
.masked-text-area {
	display: flex;
	align-items: flex-start;
	gap: 4px;
}

.masked-text-area__field {
	flex: 1 1 auto;
	min-width: 0;
}
</style>
