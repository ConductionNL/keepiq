<template>
	<div class="delegation-manager">
		<NcLoadingIcon v-if="delegationStore.loading" />

		<template v-else>
			<p v-if="delegationStore.delegations.length === 0" class="delegation-manager__empty">
				{{ t('doriath', 'No delegations.') }}
			</p>

			<ul v-else class="delegation-manager__items">
				<li
					v-for="delegation in delegationStore.delegations"
					:key="delegation.id"
					class="delegation-manager__item">
					<span class="delegation-manager__user">{{ delegation.delegateTo }}</span>
				</li>
			</ul>

			<template v-if="isOwner">
				<div v-if="error" class="delegation-manager__error">
					<NcNoteCard type="error">
						{{ error }}
					</NcNoteCard>
				</div>

				<div class="delegation-manager__form">
					<NcInputField
						v-model="delegateToUserId"
						:label="t('doriath', 'Delegate to user ID')"
						:disabled="creating"
						:placeholder="t('doriath', 'e.g. jane.doe')" />
					<NcButton
						type="secondary"
						:disabled="!delegateToUserId.trim() || creating"
						@click="createDelegation">
						{{ creating ? t('doriath', 'Delegating…') : t('doriath', 'Delegate') }}
					</NcButton>
				</div>

				<NcButton
					v-if="delegationStore.delegations.length > 0"
					type="error"
					:disabled="reclaiming"
					@click="reclaim">
					{{ reclaiming ? t('doriath', 'Reclaiming…') : t('doriath', 'Reclaim secret') }}
				</NcButton>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcInputField, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { useDelegationStore } from '../store/modules/delegation.js'

export default {
	name: 'DelegationManager',

	components: {
		NcButton,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		secretId: {
			type: String,
			required: true,
		},
		isOwner: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			delegateToUserId: '',
			creating: false,
			reclaiming: false,
			error: null,
		}
	},

	computed: {
		delegationStore() {
			return useDelegationStore()
		},
	},

	watch: {
		secretId(newId) {
			this.delegationStore.fetchDelegations(newId)
		},
	},

	mounted() {
		this.delegationStore.fetchDelegations(this.secretId)
	},

	methods: {
		async createDelegation() {
			const userId = this.delegateToUserId.trim()
			if (!userId) return

			this.creating = true
			this.error = null
			try {
				await this.delegationStore.createDelegation(this.secretId, userId)
				this.delegateToUserId = ''
			} catch (e) {
				this.error = e.response?.data?.message || e.message || t('doriath', 'Failed to create delegation')
			} finally {
				this.creating = false
			}
		},

		async reclaim() {
			this.reclaiming = true
			this.error = null
			try {
				await this.delegationStore.reclaimDelegation(this.secretId)
			} catch (e) {
				this.error = e.response?.data?.message || e.message || t('doriath', 'Failed to reclaim secret')
			} finally {
				this.reclaiming = false
			}
		},
	},
}
</script>

<style scoped>
.delegation-manager {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.delegation-manager__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	margin: 0;
}

.delegation-manager__items {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.delegation-manager__item {
	padding: 4px 0;
}

.delegation-manager__user {
	font-size: 0.875rem;
}

.delegation-manager__form {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.delegation-manager__form > :first-child {
	flex: 1;
}
</style>
