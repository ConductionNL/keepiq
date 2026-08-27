<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Read-only view of one decrypted historic version of a secret.

  The value arrives already decrypted with the in-session key — this component
  never touches ciphertext — and starts MASKED. Revealing is a per-open
  decision: `revealed` is local state, so closing and reopening the dialog
  hides the value again rather than leaving an old version's secret on screen
  for the next person who glances at the tab.

  Lives in its own file per ADR-004: a dialog written inline in its parent
  couples presentation to the parent's lifecycle and cannot be reused.

  @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Version details')"
		:open="version !== null"
		size="normal"
		data-testid="version-details-dialog"
		@update:open="$emit('close')">
		<dl
			v-if="version"
			class="version-history__details"
			data-testid="version-details">
			<div>
				<dt>{{ t('keepiq', 'Name') }}</dt>
				<dd>{{ version.name }}</dd>
			</div>
			<div v-if="version.url">
				<dt>{{ t('keepiq', 'URL') }}</dt>
				<dd>{{ version.url }}</dd>
			</div>
			<div>
				<dt>{{ t('keepiq', 'Value') }}</dt>
				<dd class="version-history__value">
					<span data-testid="version-value">{{
						revealed ? version.key : '••••••••••'
					}}</span>
					<NcButton variant="tertiary" @click="revealed = !revealed">
						{{ revealed ? t('keepiq', 'Hide') : t('keepiq', 'Show') }}
					</NcButton>
				</dd>
			</div>
			<div v-if="version.login">
				<dt>{{ t('keepiq', 'Login') }}</dt>
				<dd>{{ version.login }}</dd>
			</div>
		</dl>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'VersionDetailsDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/**
		 * The decrypted version to show, or `null` when closed. Null-as-closed
		 * matches the parent's own state, so a separate `open` flag could only
		 * ever disagree with it.
		 */
		version: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	data() {
		return {
			/** Masked until the viewer asks; reset every time the dialog opens. */
			revealed: false,
		}
	},

	watch: {
		/**
		 * Re-mask whenever a different version is shown. Without this the
		 * reveal would carry over from the previously inspected version.
		 *
		 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
		 */
		version() {
			this.revealed = false
		},
	},
}
</script>

<!-- Moved verbatim from VersionHistoryPanel.vue with the markup they style. -->
<style scoped>
.version-history__details {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px 12px 12px;
}

.version-history__details dt {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #777);
}

.version-history__value {
	display: flex;
	align-items: center;
	gap: 8px;
	word-break: break-all;
}
</style>
