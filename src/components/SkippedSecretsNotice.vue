<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The notice on secrets that could not be decrypted and are left out of an
  export or transfer: how many, and a checkbox to continue without them.
  The dialog holds its export or send until the box is ticked (keepiq#794).

  @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-nothing-is-left-out-of-an-export-in-silence
-->
<template>
	<div :data-testid="`${name}-skipped-warning`">
		<NcNoteCard type="warning">
			{{
				n(
					'keepiq',
					'%n secret could not be decrypted and is not in this export.',
					'%n secrets could not be decrypted and are not in this export.',
					skipped,
				)
			}}
		</NcNoteCard>
		<NcCheckboxRadioSwitch
			:modelValue="acknowledged"
			:data-testid="`${name}-skipped-ack`"
			@update:modelValue="$emit('update:acknowledged', $event)">
			{{
				t(
					'keepiq',
					'Continue without the secrets that could not be decrypted',
				)
			}}
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'SkippedSecretsNotice',

	components: { NcCheckboxRadioSwitch, NcNoteCard },

	props: {
		/** The dialog's test id prefix: export, gdpr or cxp. */
		name: {
			type: String,
			required: true,
		},

		/** How many secrets are left out. */
		skipped: {
			type: Number,
			required: true,
		},

		/** Whether the user chose to continue without them. */
		acknowledged: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:acknowledged'],
}
</script>
