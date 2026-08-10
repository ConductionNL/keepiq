<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Restore confirmation for one historic version of a secret.

  The prompt spells out both consequences because neither is obvious from the
  button: the CURRENT value is not discarded but kept as a new version, and
  the restore propagates to everyone the secret is shared with via
  sync-on-update. An owner restoring an old password is changing what their
  recipients hold, not just their own copy.

  Lives in its own file per ADR-004: a dialog written inline in its parent
  couples presentation to the parent's lifecycle and cannot be reused.

  @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
  @spec openspec/specs/secret-version-history/spec.md#requirement-restores-are-auditable
-->
<template>
	<NcDialog :name="t('doriath', 'Restore version')"
		:open="version !== null"
		size="small"
		data-testid="version-restore-dialog"
		@update:open="$emit('close')">
		<p class="version-history__confirm">
			{{ t('doriath', 'Restore version {number}? The current value is kept as a new version, and shared recipients receive the restored value.', { number: version ? version.versionNumber : 0 }) }}
		</p>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" data-testid="version-restore-confirm" @click="$emit('confirm')">
				{{ t('doriath', 'Restore') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'VersionRestoreDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/**
		 * The version awaiting confirmation, or `null` when closed.
		 * Null-as-closed matches the parent's own state.
		 */
		version: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'confirm'],
}
</script>

<!-- Moved verbatim from VersionHistoryPanel.vue with the markup it styles. -->
<style scoped>
.version-history__confirm {
	padding: 0 12px 12px;
}
</style>
