<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin offboarding section (team-folder-sharing §5.3). One action: given
  a leaving user and a successor, revoke the leaver's team-folder-derived
  access and transfer their owned team secrets via the existing
  permanent-delegation mechanics. The result summary reports revoked,
  transferred, and skipped counts (skipped = successor holds no copy yet;
  add the successor to the folder and re-run).

  @spec openspec/changes/team-folder-sharing/tasks.md#5.3
-->
<template>
	<CnSettingsSection
		:name="t('doriath', 'Team offboarding')"
		:description="
			t(
				'doriath',
				'Revoke a leaving employee\'s team-folder access and transfer their owned team secrets to a successor.',
			)
		">
		<div class="offboarding" data-testid="offboarding-section">
			<NcNoteCard v-if="error" type="error" data-testid="offboarding-error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard
				v-if="summary"
				:type="summary.skipped.length ? 'warning' : 'success'"
				data-testid="offboarding-summary">
				{{ summaryText }}
			</NcNoteCard>

			<div class="offboarding__fields">
				<label class="offboarding__field">
					<span>{{ t('doriath', 'Leaving user ID') }}</span>
					<input
						v-model.trim="leavingUserId"
						type="text"
						autocomplete="off"
						data-testid="offboarding-leaving" />
				</label>
				<label class="offboarding__field">
					<span>{{ t('doriath', 'Successor user ID') }}</span>
					<input
						v-model.trim="successorUserId"
						type="text"
						autocomplete="off"
						data-testid="offboarding-successor" />
				</label>
			</div>

			<div class="offboarding__actions">
				<NcButton
					variant="error"
					:disabled="
						busy
						|| leavingUserId === ''
						|| successorUserId === ''
						|| leavingUserId === successorUserId
					"
					data-testid="offboarding-run"
					@click="confirmOpen = true">
					{{ t('doriath', 'Offboard user') }}
				</NcButton>
			</div>

			<OffboardingConfirmDialog
				:open="confirmOpen"
				:leaving-user-id="leavingUserId"
				:successor-user-id="successorUserId"
				@update:open="confirmOpen = $event"
				@confirm="run" />
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import OffboardingConfirmDialog from '../../dialogs/OffboardingConfirmDialog.vue'
import { useTeamFolderStore } from '../../store/modules/teamFolder.js'

export default {
	name: 'OffboardingSection',
	components: {
		CnSettingsSection,
		NcButton,
		NcNoteCard,
		OffboardingConfirmDialog,
	},

	data() {
		return {
			leavingUserId: '',
			successorUserId: '',
			busy: false,
			confirmOpen: false,
			error: null,
			summary: null,
		}
	},

	computed: {
		summaryText() {
			if (!this.summary) {
				return ''
			}
			const base = this.t(
				'doriath',
				'Revoked {revoked} shares, transferred {transferred} secrets.',
				{
					revoked: this.summary.revoked,
					transferred: this.summary.transferred,
				},
			)
			if (this.summary.skipped.length === 0) {
				return base
			}
			return (
				base
				+ ' '
				+ this.n(
					'doriath',
					'%n secret was skipped because the successor holds no copy yet — add the successor to the folder and re-run.',
					'%n secrets were skipped because the successor holds no copy yet — add the successor to the folder and re-run.',
					this.summary.skipped.length,
				)
			)
		},
	},

	methods: {
		/**
		 * Run the offboarding action after the typed confirmation dialog.
		 */
		async run() {
			this.confirmOpen = false
			this.busy = true
			this.error = null
			this.summary = null
			try {
				this.summary = await useTeamFolderStore().offboard(
					this.leavingUserId,
					this.successorUserId,
				)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.offboarding {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

.offboarding__fields {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.offboarding__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.offboarding__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.offboarding__actions {
	display: flex;
	justify-content: flex-start;
}
</style>
