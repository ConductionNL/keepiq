<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Delete-application confirmation.

  This replaces a native browser confirmation prompt on the application detail
  page. The native prompt is unstyled, cannot be themed, is not reachable by
  the app's own translations beyond its single message string, and is
  suppressed outright in some embedded and kiosk contexts — where it returns
  false, so the delete simply never happened and the user got no feedback.

  Lives in its own file per ADR-004: a dialog written inline in its parent
  couples presentation to the parent's lifecycle and cannot be reused.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
-->
<template>
	<NcDialog
		:name="t('doriath', 'Delete application')"
		:open="open"
		size="normal"
		data-testid="application-delete-dialog"
		@update:open="$emit('close')">
		<NcNoteCard type="warning" data-testid="application-delete-warning">
			{{
				t(
					'doriath',
					'Delete this application? This cascades to its secrets.',
				)
			}}
		</NcNoteCard>
		<template #actions>
			<NcButton
				variant="tertiary"
				data-testid="application-delete-cancel"
				@click="$emit('close')">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				data-testid="application-delete-confirm"
				@click="$emit('confirm')">
				{{ t('doriath', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'ApplicationDeleteDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	props: {
		/** Whether the dialog is visible. */
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm'],
}
</script>
