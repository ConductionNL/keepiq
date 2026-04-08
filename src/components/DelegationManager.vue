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
					<AccountArrowRightIcon :size="20" class="delegation-manager__icon" />
					<div class="delegation-manager__info">
						<span class="delegation-manager__user">{{ delegation.delegatedTo }}</span>
						<span class="delegation-manager__detail">
							<NcDateTime
								:timestamp="new Date(delegation.delegatedAt)"
								:relative-time="isRecent(delegation.delegatedAt) ? 'long' : false" />
							<template v-if="delegation.isPermanent">
								&middot; {{ t('doriath', 'Permanent') }}
							</template>
						</span>
					</div>
				</li>
			</ul>

			<template v-if="isOwner">
				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>

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
import { NcButton, NcDateTime, NcInputField, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AccountArrowRightIcon from 'vue-material-design-icons/AccountArrowRight.vue'
import { useDelegationStore } from '../store/modules/delegation.js'

export default {
	name: 'DelegationManager',

	components: {
		NcButton,
		NcDateTime,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		AccountArrowRightIcon,
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
		isRecent(dateString) {
			if (!dateString) return false
			const weekMs = 7 * 24 * 60 * 60 * 1000
			return (Date.now() - new Date(dateString).getTime()) < weekMs
		},

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
}

.delegation-manager__item {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 6px 0;
}

.delegation-manager__icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
	margin-top: 1px;
}

.delegation-manager__info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.delegation-manager__user {
	font-size: 0.875rem;
	font-weight: 500;
}

.delegation-manager__detail {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	gap: 4px;
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
