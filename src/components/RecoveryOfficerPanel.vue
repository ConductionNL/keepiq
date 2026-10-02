<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The recovery officer's view (crypto-organisation-account-recovery D2 to
  D4): create the recovery key, and for each request compare the words with
  the user, approve with the master password, decline, or hand the key over
  once enough officers approved.

  @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
-->
<template>
	<div
		v-if="officer && officer.officer"
		class="recovery-officer"
		data-testid="recovery-officer">
		<h4>{{ t('keepiq', 'Recovery officer') }}</h4>
		<template v-if="!officer.key">
			<p>
				{{
					t(
						'keepiq',
						'Create the recovery key. Your browser makes it and gives each officer a copy only they can open.',
					)
				}}
			</p>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="recovery-create-key"
				@click="run(() => store.createKey())">
				{{ t('keepiq', 'Create the recovery key') }}
			</NcButton>
		</template>
		<p v-else-if="officer.requests.length === 0">
			{{ t('keepiq', 'No one is asking to recover their account.') }}
		</p>
		<ul class="recovery-officer__requests">
			<li
				v-for="request in officer.requests"
				:key="request.id"
				:data-testid="`recovery-request-${request.id}`">
				<p v-if="request.purpose === 'device'">
					{{
						t(
							'keepiq',
							'{user} asks to unlock a new device once. They keep their master password.',
							{ user: request.userId },
						)
					}}
				</p>
				<p>
					<strong>{{ request.userId }}</strong>
					{{
						t('keepiq', '{approvals} of {threshold} approvals', {
							approvals: request.approvals,
							threshold: request.threshold,
						})
					}}
				</p>
				<p>
					{{
						t(
							'keepiq',
							'Ask {user} which words they see, by phone or in person. They must be:',
							{ user: request.userId },
						)
					}}
				</p>
				<p class="recovery-officer__phrase">
					{{ request.phrase }}
				</p>
				<template
					v-if="request.status === 'pending' && !request.approvedByMe">
					<NcPasswordField
						v-model="passwords[request.id]"
						:label="t('keepiq', 'Your master password')"
						autocomplete="current-password"
						:disabled="busy" />
					<NcButton
						variant="primary"
						:disabled="busy || !passwords[request.id]"
						:data-testid="`recovery-approve-${request.id}`"
						@click="
							run(() => store.approve(request, passwords[request.id]))
						">
						{{ t('keepiq', 'The words match, approve') }}
					</NcButton>
				</template>
				<NcButton
					v-if="
						request.status === 'approved'
						&& request.approvedByMe
						&& !request.handedOff
					"
					variant="primary"
					:disabled="busy"
					:data-testid="`recovery-handoff-${request.id}`"
					@click="run(() => store.handOff(request))">
					{{ t('keepiq', 'Hand the key over') }}
				</NcButton>
				<NcButton
					v-if="request.status === 'pending'"
					variant="tertiary"
					:disabled="busy"
					:data-testid="`recovery-decline-${request.id}`"
					@click="run(() => store.decline(request.id))">
					{{ t('keepiq', 'Decline') }}
				</NcButton>
			</li>
		</ul>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useAccountRecoveryStore } from '../store/modules/accountRecovery.js'

export default {
	name: 'RecoveryOfficerPanel',
	components: { NcButton, NcNoteCard, NcPasswordField },

	data() {
		return {
			passwords: {},
			busy: false,
			error: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
		 */
		store() {
			return useAccountRecoveryStore()
		},

		/**
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
		 */
		officer() {
			return this.store.officer
		},
	},

	/**
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	async created() {
		try {
			await this.store.fetchOfficer()
		} catch {
			// Not an officer, or not reachable: nothing to show.
		}
	},

	methods: {
		/**
		 * Run one officer action and show its failure.
		 *
		 * @param {Function} action The action.
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
		 */
		async run(action) {
			this.busy = true
			this.error = null
			try {
				await action()
				this.passwords = {}
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
.recovery-officer__requests {
	list-style: none;
	padding: 0;
}

.recovery-officer__phrase {
	font-size: 18px;
	font-weight: 600;
}
</style>
