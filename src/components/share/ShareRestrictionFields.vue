<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The two options of a share or team-folder membership: use-only and an end
  date. Next to use-only it says plainly what use-only does not stop
  (sharing-use-only-and-expiring-shares D6). v-model is
  `{ useOnly: boolean, endDate: 'YYYY-MM-DD' | '' }`.

  @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-the-share-dialog-states-the-limit-of-use-only
-->
<template>
	<fieldset class="keepiq-share-restriction" data-testid="share-restriction">
		<NcCheckboxRadioSwitch
			v-if="!hideUseOnly"
			:modelValue="modelValue.useOnly"
			type="checkbox"
			data-testid="share-restriction-use-only"
			@update:modelValue="update({ useOnly: $event === true })">
			{{ t('keepiq', 'Use only (can sign in, cannot view or copy)') }}
		</NcCheckboxRadioSwitch>
		<p
			v-if="!hideUseOnly"
			class="keepiq-share-restriction__limit"
			data-testid="share-restriction-limit">
			{{
				t(
					'keepiq',
					"Keepiq's apps will not show or copy the password. Someone with technical skill can still read it from their own device. Rotate it when their access ends.",
				)
			}}
		</p>
		<label class="keepiq-share-restriction__end">
			<span>{{ t('keepiq', 'Access ends on (optional)') }}</span>
			<input
				:value="modelValue.endDate"
				type="date"
				:min="today"
				data-testid="share-restriction-end"
				@input="update({ endDate: $event.target.value })" />
		</label>
	</fieldset>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

export default {
	name: 'ShareRestrictionFields',
	components: { NcCheckboxRadioSwitch },
	props: {
		modelValue: {
			type: Object,
			default: () => ({ useOnly: false, endDate: '' }),
		},

		/** Hides use-only (a write grade cannot be use-only). */
		hideUseOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],
	computed: {
		/**
		 * Today as YYYY-MM-DD, the earliest end date the picker offers.
		 *
		 * @return {string}
		 */
		today() {
			const now = new Date()
			const pad = (n) => String(n).padStart(2, '0')
			return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
		},
	},

	methods: {
		/**
		 * Emit the options with one field changed.
		 *
		 * @param {object} change The changed field.
		 * @return {void}
		 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-2.3
		 */
		update(change) {
			this.$emit('update:modelValue', { ...this.modelValue, ...change })
		},
	},
}
</script>

<style scoped>
.keepiq-share-restriction {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 8px 0 0;
	padding: 0;
	border: 0;
}

.keepiq-share-restriction__limit {
	margin: 0 0 4px 36px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.keepiq-share-restriction__end {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
