<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Confirmation for offboarding a user from every team folder.

  The action it guards is irreversible and wide: it revokes ALL of the
  leaving user's team-folder access and transfers every team secret they own
  to a successor. Both names are spelled out in the prompt because the two
  fields sit next to each other in the admin form and are easy to transpose —
  the confirmation is the last place that mistake can still be caught.

  Lives in its own file per ADR-004: a dialog written inline in its parent
  couples presentation to the parent's lifecycle and cannot be reused.

  @spec openspec/specs/team-folder-sharing/spec.md#requirement-admin-offboarding
-->
<template>
	<NcDialog
		:name="t('doriath', 'Confirm offboarding')"
		:open="open"
		size="small"
		data-testid="offboarding-confirm-dialog"
		@update:open="$emit('update:open', $event)">
		<p class="offboarding__confirm">
			{{
				t(
					'doriath',
					'Revoke all team-folder access of "{leaving}" and transfer their owned team secrets to "{successor}"? This cannot be undone.',
					{ leaving: leavingUserId, successor: successorUserId },
				)
			}}
		</p>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('update:open', false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				data-testid="offboarding-confirm"
				@click="$emit('confirm')">
				{{ t('doriath', 'Offboard') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'OffboardingConfirmDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/** Whether the dialog is visible. */
		open: {
			type: Boolean,
			default: false,
		},

		/** UID of the user being offboarded. */
		leavingUserId: {
			type: String,
			required: true,
		},

		/** UID of the successor receiving their team secrets. */
		successorUserId: {
			type: String,
			required: true,
		},
	},

	emits: ['update:open', 'confirm'],
}
</script>

<!-- Moved verbatim from OffboardingSection.vue with the markup it styles. -->
<style scoped>
.offboarding__confirm {
	padding: 0 12px 12px;
}
</style>
