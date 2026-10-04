<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin offboarding section (team-folder-sharing §5.3). One action: given
  a leaving user and a successor, revoke the leaver's team-folder-derived
  access and transfer their owned team secrets via the existing
  permanent-delegation mechanics. The result summary reports revoked,
  transferred, and skipped counts (skipped = successor holds no copy yet;
  add the successor to the folder and re-run), the removed direct
  memberships and the groups that still cover the leaver. Both users are
  picked from the admin member endpoint, and a Members row can hand over
  the leaving user (admin-member-overview-and-offboarding §1.5, §3.2, §3.3).

  @spec openspec/changes/team-folder-sharing/tasks.md#5.3
  @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Team offboarding')"
		:description="
			t(
				'keepiq',
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

			<NcNoteCard
				v-if="summary && coveringGroups.length > 0"
				type="warning"
				data-testid="offboarding-covering-groups">
				{{ coveringGroupsText }}
			</NcNoteCard>

			<div class="offboarding__fields">
				<NcSelect
					v-model="leavingUser"
					class="offboarding__field"
					:options="userOptions"
					label="displayName"
					:filterable="false"
					:loading="searching"
					:inputLabel="t('keepiq', 'Leaving user')"
					data-testid="offboarding-leaving"
					@search="onSearch" />
				<NcSelect
					v-model="successorUser"
					class="offboarding__field"
					:options="userOptions"
					label="displayName"
					:filterable="false"
					:loading="searching"
					:inputLabel="t('keepiq', 'Successor')"
					data-testid="offboarding-successor"
					@search="onSearch" />
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
					{{ t('keepiq', 'Offboard user') }}
				</NcButton>
			</div>

			<OffboardingConfirmDialog
				:open="confirmOpen"
				:leavingUserId="leavingUserId"
				:successorUserId="successorUserId"
				@update:open="confirmOpen = $event"
				@confirm="run" />
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import OffboardingConfirmDialog from '../../dialogs/OffboardingConfirmDialog.vue'
import { useMemberOverviewStore } from '../../store/modules/memberOverview.js'
import { useTeamFolderStore } from '../../store/modules/teamFolder.js'

export default {
	name: 'OffboardingSection',
	components: {
		CnSettingsSection,
		NcButton,
		NcNoteCard,
		NcSelect,
		OffboardingConfirmDialog,
	},

	data() {
		return {
			leavingUser: null,
			successorUser: null,
			userOptions: [],
			searching: false,
			busy: false,
			confirmOpen: false,
			error: null,
			summary: null,
		}
	},

	computed: {
		/**
		 * The member overview store, which carries the prefill from a row.
		 *
		 * @return {object}
		 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
		 */
		memberStore() {
			return useMemberOverviewStore()
		},

		/**
		 * The leaving user's id, '' until one is picked.
		 *
		 * @return {string}
		 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
		 */
		leavingUserId() {
			return this.leavingUser?.userId ?? ''
		},

		/**
		 * The successor's id, '' until one is picked.
		 *
		 * @return {string}
		 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
		 */
		successorUserId() {
			return this.successorUser?.userId ?? ''
		},

		/**
		 * Group memberships that still cover the leaver after the run.
		 *
		 * @return {Array<{teamFolderId: string, groupId: string}>}
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-offboarding-removes-the-leavers-direct-team-folder-memberships
		 */
		coveringGroups() {
			return this.summary?.stillCoveredByGroups ?? []
		},

		/**
		 * The warning for groups that still cover the leaver: they keep
		 * team folder access through the group until removed from it.
		 *
		 * @return {string}
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-offboarding-removes-the-leavers-direct-team-folder-memberships
		 */
		coveringGroupsText() {
			const groups = [
				...new Set(this.coveringGroups.map((row) => row.groupId)),
			]
			return this.n(
				'keepiq',
				'The user is still in group {groups}, which is a member of a team folder. Remove them from the group or disable the account.',
				'The user is still in groups {groups}, which are members of team folders. Remove them from the groups or disable the account.',
				groups.length,
				{ groups: groups.join(', ') },
			)
		},

		/**
		 * The post-run summary sentence: revoked/transferred counts plus the
		 * skipped-secrets caveat the admin has to act on.
		 *
		 * @return {string}
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-admin-offboarding
		 */
		summaryText() {
			if (!this.summary) {
				return ''
			}
			let base = this.t(
				'keepiq',
				'Revoked {revoked} shares, transferred {transferred} secrets.',
				{
					revoked: this.summary.revoked,
					transferred: this.summary.transferred,
				},
			)
			if (this.summary.membershipsRemoved > 0) {
				base +=
					' '
					+ this.t(
						'keepiq',
						'Removed the user from {count} team folders.',
						{ count: this.summary.membershipsRemoved },
					)
			}
			if (this.summary.skipped.length === 0) {
				return base
			}
			return (
				base
				+ ' '
				+ this.n(
					'keepiq',
					'%n secret was skipped because the successor holds no copy yet — add the successor to the folder and re-run.',
					'%n secrets were skipped because the successor holds no copy yet — add the successor to the folder and re-run.',
					this.summary.skipped.length,
				)
			)
		},
	},

	watch: {
		/**
		 * A Members row chose "Offboard": put that user in the leaving field.
		 *
		 * @param {string} userId The user handed over by the list.
		 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
		 */
		'memberStore.offboardUserId': function (userId) {
			if (!userId) {
				return
			}
			const row = this.memberStore.members.find(
				(member) => member.userId === userId,
			)
			this.leavingUser = { userId, displayName: row?.displayName || userId }
		},
	},

	methods: {
		/**
		 * Fill the user pickers from the admin member endpoint.
		 *
		 * @param {string} query The typed search.
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
		 */
		async onSearch(query) {
			this.searching = true
			try {
				this.userOptions = await this.memberStore.searchUsers(query ?? '')
			} catch {
				this.userOptions = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Run the offboarding action after the typed confirmation dialog.
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-admin-offboarding
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
	min-width: 220px;
}

.offboarding__actions {
	display: flex;
	justify-content: flex-start;
}
</style>
