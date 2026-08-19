<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Revoke-an-application's-secret-request confirmation.

  This action is not the same as a user revoking their own request, and it should
  not feel like it. An administrator revoking here is reaching into software they
  did not write, whose fill link may be in someone's inbox right now: the link
  stops working, the empty placeholder Secret is deleted, and if the application
  was waiting on that credential its integration stops mid-flow. That is the
  intended power — a circulating bearer credential needs an off switch that does
  not depend on the application cooperating — but it deserves a beat of
  deliberation rather than a single click on a row.

  Names the requested fields in the warning, because "revoke request" tells an
  administrator nothing about what they are interrupting, while "this application
  is waiting for key, login" does.

  Lives in its own file per ADR-004 (and hydra gate-13): a dialog written inline
  in its parent couples presentation to the parent's lifecycle and cannot be
  reused.

  @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
-->
<template>
	<NcDialog
		:name="t('doriath', 'Revoke this request?')"
		:open="open"
		size="normal"
		data-testid="application-request-revoke-dialog"
		@update:open="$emit('close')">
		<NcNoteCard type="warning" data-testid="application-request-revoke-warning">
			{{
				t(
					'doriath',
					'The fill link stops working immediately, even if someone already has it. If the application is waiting for this credential, its integration may stop working.',
				)
			}}
		</NcNoteCard>

		<p
			v-if="requestedFields.length > 0"
			data-testid="application-request-revoke-fields">
			{{
				t('doriath', 'This application asked for: {fields}', {
					fields: requestedFields.join(', '),
				})
			}}
		</p>

		<template #actions>
			<NcButton
				variant="tertiary"
				data-testid="application-request-revoke-cancel"
				@click="$emit('close')">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				data-testid="application-request-revoke-confirm"
				@click="$emit('confirm')">
				{{ t('doriath', 'Revoke the request') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'ApplicationRequestRevokeDialog',

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

		/**
		 * The field names the request is asking for.
		 *
		 * Plaintext metadata by design (the Requestable Fields requirement puts the
		 * names on the request, deliberately never on the Secret), so naming them
		 * here discloses nothing the audit log does not already record.
		 */
		requestedFields: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'confirm'],
}
</script>
