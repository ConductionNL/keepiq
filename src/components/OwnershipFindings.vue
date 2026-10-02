<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  "Not in a team folder" list for the health report (admin-vault-policies
  D6). When the ownership policy applies, it lists the user's own secrets of
  a covered type that sit outside a team folder, and moves one into a team
  folder: into one the user owns by changing its folder (then sharing it with
  the members), or into one they can write to by contributing a copy and
  then removing the personal one. Nothing is moved by the server.

  @spec openspec/changes/admin-vault-policies/tasks.md#4.4
-->
<template>
	<section
		v-if="findings.length > 0"
		class="ownership-findings"
		data-testid="ownership-findings">
		<h3>{{ t('keepiq', 'Not in a team folder') }}</h3>
		<p class="ownership-findings__hint">
			{{
				t(
					'keepiq',
					'Your organisation keeps these secrets in a team folder. Move each one into a team folder.',
				)
			}}
		</p>
		<NcNoteCard v-if="error" type="error" data-testid="ownership-findings-error">
			{{ error }}
		</NcNoteCard>
		<ul class="ownership-findings__list">
			<li
				v-for="finding in findings"
				:key="finding.id"
				class="ownership-findings__row"
				:data-testid="`ownership-finding-${finding.id}`">
				<span class="ownership-findings__name">{{ finding.name }}</span>
				<NcSelect
					v-model="targets[finding.id]"
					:options="targetOptions"
					label="label"
					:inputLabel="t('keepiq', 'Team folder')"
					:data-testid="`ownership-target-${finding.id}`" />
				<NcButton
					variant="secondary"
					:disabled="!targets[finding.id] || busy"
					:data-testid="`ownership-move-${finding.id}`"
					@click="move(finding)">
					{{ t('keepiq', 'Move to a team folder') }}
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { fetchPolicy } from '../policy/policy.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useTeamFolderStore } from '../store/modules/teamFolder.js'

export default {
	name: 'OwnershipFindings',
	components: { NcButton, NcNoteCard, NcSelect },

	data() {
		return {
			findings: [],
			ownTargets: [],
			contributable: [],
			targets: {},
			busy: false,
			error: null,
		}
	},

	computed: {
		/**
		 * Team folders a finding can move to: owned ones first, then the
		 * ones this user can write to.
		 *
		 * @return {Array<{label: string, kind: string, teamFolderId: string, folderId: string}>}
		 * @spec openspec/changes/admin-vault-policies/tasks.md#4.4
		 */
		targetOptions() {
			return [
				...this.ownTargets.map((teamFolder) => ({
					label: teamFolder.folderName,
					kind: 'owner',
					teamFolderId: teamFolder.id,
					folderId: teamFolder.folderId,
				})),
				...this.contributable.map((teamFolder) => ({
					label: teamFolder.folderName,
					kind: 'contribute',
					teamFolderId: teamFolder.teamFolderId,
					folderId: teamFolder.folderId,
				})),
			]
		},
	},

	/**
	 * Load the findings and the possible targets when the policy applies.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/admin-vault-policies/tasks.md#4.4
	 */
	async created() {
		const policy = await fetchPolicy()
		if (policy?.vault_org_ownership !== true) {
			return
		}
		try {
			const [findings, contributable] = await Promise.all([
				axios.get(
					generateUrl(
						'/apps/keepiq/api/v1/team-folders/ownership-findings',
					),
				),
				axios.get(
					generateUrl('/apps/keepiq/api/v1/team-folders/contributable'),
				),
			])
			const teamFolderStore = useTeamFolderStore()
			await teamFolderStore.fetchTeamFolders()
			this.findings = findings.data ?? []
			this.contributable = contributable.data ?? []
			this.ownTargets = teamFolderStore.owned
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Move one finding into the chosen team folder.
		 *
		 * @param {object} finding The finding row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-vault-policies/tasks.md#4.4
		 */
		async move(finding) {
			const target = this.targets[finding.id]
			if (!target) {
				return
			}
			this.busy = true
			this.error = null
			const secretStore = useSecretStore()
			try {
				if (target.kind === 'owner') {
					// Own team folder: a move, then share with its members.
					await secretStore.updateSecret(finding.id, {
						folderId: target.folderId,
					})
					await useTeamFolderStore().runFanOut(target.teamFolderId)
				} else {
					// Someone else's team folder: contribute a copy, and only
					// then remove the personal one.
					const raw = await axios.get(
						generateUrl(`/apps/keepiq/api/v1/secrets/${finding.id}`),
					)
					const plain = await secretStore.decryptSecret(raw.data)
					await secretStore.contributeSecret(target.teamFolderId, {
						name: plain.name,
						url: plain.url ?? null,
						typeId: plain.typeId,
						key: plain.key ?? '',
						login: plain.login ?? '',
						additionalFields: plain.additionalFields ?? null,
					})
					await secretStore.deleteSecret(finding.id)
				}
				this.findings = this.findings.filter((row) => row.id !== finding.id)
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
.ownership-findings__hint {
	color: var(--color-text-maxcontrast);
}

.ownership-findings__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ownership-findings__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.ownership-findings__name {
	min-width: 160px;
	font-weight: bold;
}
</style>
