<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Confirmation for renewing the root certificate of the private CA.

  Root renewal is manual by design: it generates a new root and a new
  intermediate and re-signs every active encryption suite. That is wide and
  cannot be rolled back from the screen, so the button in the CA health
  section only opens this dialog; the POST happens on confirm.

  Lives in its own file per ADR-004.

  @spec openspec/specs/encryption-suites/spec.md#requirement-ca-certificate-renewal
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Renew root certificate')"
		:open="open"
		size="small"
		data-testid="ca-renew-root-dialog"
		@update:open="$emit('update:open', $event)">
		<p data-testid="ca-renew-root-warning">
			{{
				t(
					'keepiq',
					'This creates a new root and intermediate certificate. Every active encryption suite is signed again. You cannot undo this.',
				)
			}}
		</p>
		<template #actions>
			<NcButton
				variant="tertiary"
				data-testid="ca-renew-root-cancel"
				@click="$emit('update:open', false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				data-testid="ca-renew-root-confirm"
				@click="$emit('confirm')">
				{{ t('keepiq', 'Renew root') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'CaRenewRootConfirmDialog',

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

		/** True while the renewal request is in flight. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'confirm'],
}
</script>
