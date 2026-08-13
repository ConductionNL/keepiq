<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Team-folder sharing dialog (team-folder-sharing §5.1/§5.2). Shares the
  selected folder as a team folder, manages its user/group membership,
  and runs the client fan-out with a cancellable progress bar. All
  encryption happens in the browser; the idempotent server upsert makes
  a cancelled run resumable via the "needs re-share" reconcile pass.

  @spec openspec/changes/team-folder-sharing/tasks.md#5.1
-->
<template>
	<NcDialog
		:name="t('doriath', 'Team folder sharing')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="team-folder-dialog" data-testid="team-folder-dialog">
			<NcNoteCard v-if="error" type="error" data-testid="team-folder-error">
				{{ error }}
			</NcNoteCard>

			<!-- Not shared yet: offer to share the folder. -->
			<div v-if="!teamFolder" class="team-folder-dialog__share">
				<p>
					{{
						t(
							'doriath',
							'Share the folder "{name}" and every secret in it with your team. Members receive their own encrypted copies — no plaintext ever reaches the server.',
							{ name: folderName },
						)
					}}
				</p>
				<NcButton
					variant="primary"
					:disabled="busy"
					data-testid="team-folder-share"
					@click="onShareFolder">
					{{ t('doriath', 'Share this folder') }}
				</NcButton>
			</div>

			<!-- Shared: membership management + fan-out state. -->
			<div v-else class="team-folder-dialog__members">
				<h4>{{ t('doriath', 'Members') }}</h4>
				<ul
					v-if="members.length"
					class="team-folder-dialog__member-list"
					data-testid="team-folder-members">
					<li
						v-for="member in members"
						:key="member.id"
						class="team-folder-dialog__member">
						<component
							:is="
								member.memberType === 'group'
									? 'AccountGroup'
									: 'Account'
							"
							:size="18" />
						<span class="team-folder-dialog__member-name">{{
							member.memberId
						}}</span>
						<!-- Permission grade (folder-permission-grades §4.1):
						     owner-only; a write member may edit folder secrets
						     and fan the change out to the whole team. -->
						<select
							class="team-folder-dialog__grade"
							:value="member.grade || 'read'"
							:disabled="busy"
							:data-testid="`team-folder-grade-${member.memberId}`"
							@change="onGradeChange(member, $event.target.value)">
							<option value="read">{{ t('doriath', 'Read') }}</option>
							<option value="write">
								{{ t('doriath', 'Write') }}
							</option>
						</select>
						<NcButton
							variant="tertiary"
							:aria-label="t('doriath', 'Remove member')"
							:disabled="busy"
							:data-testid="`team-folder-remove-${member.memberId}`"
							@click="onRemoveMember(member)">
							<template #icon>
								<Close :size="18" />
							</template>
						</NcButton>
					</li>
				</ul>
				<p v-else class="team-folder-dialog__empty">
					{{ t('doriath', 'No members yet — add a user or group below.') }}
				</p>

				<div class="team-folder-dialog__add">
					<NcSelect
						v-model="newMemberType"
						:options="memberTypeOptions"
						:reduce="(opt) => opt.value"
						:input-label="t('doriath', 'Member type')"
						:clearable="false" />
					<label class="team-folder-dialog__id-field">
						<span>{{
							newMemberType === 'group'
								? t('doriath', 'Group ID')
								: t('doriath', 'User ID')
						}}</span>
						<input
							v-model.trim="newMemberId"
							type="text"
							autocomplete="off"
							data-testid="team-folder-member-id" />
					</label>
					<NcButton
						variant="secondary"
						:disabled="busy || newMemberId === ''"
						data-testid="team-folder-add-member"
						@click="onAddMember">
						{{ t('doriath', 'Add member') }}
					</NcButton>
				</div>

				<!-- Fan-out progress (§5.1): chunked, cancellable, resumable. -->
				<div
					v-if="fanOut.running || pendingCount > 0"
					class="team-folder-dialog__fanout">
					<NcNoteCard
						v-if="!fanOut.running && pendingCount > 0"
						type="warning"
						data-testid="team-folder-needs-reshare">
						{{
							n(
								'doriath',
								'%n secret copy still needs to be encrypted and shared.',
								'%n secret copies still need to be encrypted and shared.',
								pendingCount,
							)
						}}
					</NcNoteCard>
					<NcProgressBar
						v-if="fanOut.running"
						:value="progressPercent"
						size="medium"
						data-testid="team-folder-progress" />
					<div class="team-folder-dialog__fanout-actions">
						<NcButton
							v-if="fanOut.running"
							variant="tertiary"
							data-testid="team-folder-cancel-fanout"
							@click="store.cancelFanOut()">
							{{ t('doriath', 'Cancel') }}
						</NcButton>
						<NcButton
							v-else
							variant="primary"
							:disabled="busy"
							data-testid="team-folder-run-fanout"
							@click="onRunFanOut">
							{{ t('doriath', 'Encrypt and share now') }}
						</NcButton>
					</div>
				</div>

				<div class="team-folder-dialog__danger">
					<NcButton
						variant="error"
						:disabled="busy"
						data-testid="team-folder-unshare"
						@click="onUnshare">
						{{ t('doriath', 'Stop sharing this folder') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcProgressBar,
	NcSelect,
} from '@nextcloud/vue'
import Account from 'vue-material-design-icons/Account.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Close from 'vue-material-design-icons/Close.vue'
import { useTeamFolderStore } from '../store/modules/teamFolder.js'

export default {
	name: 'TeamFolderDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcProgressBar,
		NcSelect,
		Account,
		AccountGroup,
		Close,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		folderId: {
			type: String,
			default: null,
		},
		folderName: {
			type: String,
			default: '',
		},
	},
	emits: ['update:open'],
	data() {
		return {
			busy: false,
			error: null,
			newMemberType: 'user',
			newMemberId: '',
			pendingCount: 0,
		}
	},
	computed: {
		store() {
			return useTeamFolderStore()
		},
		teamFolder() {
			return this.folderId ? this.store.byFolderId(this.folderId) : null
		},
		members() {
			return this.teamFolder?.members ?? []
		},
		fanOut() {
			return this.store.fanOut
		},
		progressPercent() {
			if (this.fanOut.total === 0) {
				return 0
			}
			return Math.round((this.fanOut.done / this.fanOut.total) * 100)
		},
		memberTypeOptions() {
			return [
				{ label: this.t('doriath', 'User'), value: 'user' },
				{ label: this.t('doriath', 'Group'), value: 'group' },
			]
		},
	},
	watch: {
		open(isOpen) {
			if (isOpen) {
				this.error = null
				this.refresh()
			}
		},
	},
	methods: {
		onUpdateOpen(value) {
			this.$emit('update:open', value)
		},

		/**
		 * Refresh the team-folder list and the pending reconcile count.
		 */
		async refresh() {
			try {
				await this.store.fetchTeamFolders()
				if (this.teamFolder) {
					const state = await this.store.reconcile(this.teamFolder.id)
					this.pendingCount = (state.missing ?? []).length
				}
			} catch (e) {
				this.error =
					e?.message || this.t('doriath', 'Failed to load team folder')
			}
		},

		async onShareFolder() {
			this.busy = true
			this.error = null
			try {
				await this.store.shareFolder(this.folderId)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		async onAddMember() {
			this.busy = true
			this.error = null
			try {
				await this.store.addMember(
					this.teamFolder.id,
					this.newMemberType,
					this.newMemberId,
				)
				this.newMemberId = ''
				await this.refresh()
				// New members mean new missing pairs — run the fan-out now.
				await this.onRunFanOut()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		async onRemoveMember(member) {
			this.busy = true
			this.error = null
			try {
				await this.store.removeMember(this.teamFolder.id, member.id)
				await this.refresh()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Change a membership's permission grade (owner-only; no
		 * ciphertext is touched — folder-permission-grades §4.1).
		 *
		 * @param {object} member The membership row.
		 * @param {string} grade The new grade ('read'|'write').
		 * @return {Promise<void>}
		 */
		async onGradeChange(member, grade) {
			this.busy = true
			this.error = null
			try {
				await this.store.setMemberGrade(this.teamFolder.id, member.id, grade)
				await this.refresh()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		async onRunFanOut() {
			this.error = null
			try {
				await this.store.runFanOut(this.teamFolder.id)
				await this.refresh()
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},

		async onUnshare() {
			this.busy = true
			this.error = null
			try {
				await this.store.unshareFolder(this.teamFolder.id)
				this.$emit('update:open', false)
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
.team-folder-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0 12px;
}

.team-folder-dialog__member-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.team-folder-dialog__member {
	display: flex;
	align-items: center;
	gap: 8px;
}

.team-folder-dialog__member-name {
	flex: 1;
}

.team-folder-dialog__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}

.team-folder-dialog__id-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.team-folder-dialog__id-field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.team-folder-dialog__fanout {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.team-folder-dialog__fanout-actions {
	display: flex;
	justify-content: flex-end;
}

.team-folder-dialog__danger {
	border-top: 1px solid var(--color-border, #ddd);
	padding-top: 12px;
	display: flex;
	justify-content: flex-end;
}

.team-folder-dialog__empty {
	color: var(--color-text-maxcontrast, #777);
}
</style>
