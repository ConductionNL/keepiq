<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Renewal checklist for an externally-issued stored certificate.

  Keepiq's own CA can renew what it issued; a certificate that arrived from
  outside cannot be renewed here at all, so the only useful answer is the
  manual procedure. The steps come from the certificate store, which derives
  them from the parsed certificate.

  Lives in its own file per ADR-004: a dialog written inline in its parent
  couples presentation to the parent's lifecycle and cannot be reused.

  @spec openspec/specs/certificate-lifecycle/spec.md#requirement-guided-renewal-by-certificate-origin
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Renew certificate')"
		:open="checklist !== null"
		size="normal"
		data-testid="cert-checklist-dialog"
		@update:open="$emit('close')">
		<div v-if="checklist" data-testid="cert-checklist">
			<p>
				{{
					t(
						'keepiq',
						'This certificate was issued outside Keepiq, so it cannot be renewed here. Follow these steps:',
					)
				}}
			</p>
			<ol>
				<li v-for="(step, index) in checklist.steps" :key="index">
					{{ step }}
				</li>
			</ol>
		</div>
		<template #actions>
			<NcButton variant="primary" @click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'CertificateRenewalChecklistDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/**
		 * The checklist to show, or `null` when the dialog is closed.
		 * Null-as-closed matches the parent's own state: there is nothing to
		 * render without a checklist, so a separate `open` flag could only
		 * ever disagree with it.
		 */
		checklist: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],
}
</script>
