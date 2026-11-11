<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The top of the folder create and edit forms: the save error, while there
  is one, and the name field, labelled for a vault (top level) or a folder.
-->
<template>
	<NcNoteCard v-if="error" type="error">
		{{ error }}
	</NcNoteCard>

	<NcTextField
		:modelValue="modelValue"
		:label="
			vaultLevel
				? t('keepiq', 'Vault name')
				: t('keepiq', 'Folder name')
		"
		:required="true"
		@update:modelValue="$emit('update:modelValue', $event)" />
</template>

<script>
import { NcNoteCard, NcTextField } from '@nextcloud/vue'

/**
 * Edit a folder's name, with the dialog's save error above it.
 */
export default {
	name: 'FolderNameField',

	components: {
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** The name being entered. */
		modelValue: {
			type: String,
			required: true,
		},

		/** Whether the folder is a top-level vault (the label follows). */
		vaultLevel: {
			type: Boolean,
			default: false,
		},

		/** The save error to show, or empty. */
		error: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue'],
}
</script>
