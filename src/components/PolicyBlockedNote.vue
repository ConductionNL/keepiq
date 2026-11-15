<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The org password policy's reason a value cannot be saved, shown only while
  the value breaks the policy.
-->
<template>
	<NcNoteCard
		v-if="!verdict.compliant"
		type="warning"
		data-testid="policy-blocked">
		{{ verdict.reason }}
	</NcNoteCard>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'

/**
 * Show why the org password policy blocks the value.
 */
export default {
	name: 'PolicyBlockedNote',

	components: {
		NcNoteCard,
	},

	props: {
		/** The policy verdict ({compliant, reason}). */
		verdict: {
			type: Object,
			required: true,
		},
	},
}
</script>
