<template>
	<div class="delegation-manager">
		<h4 class="delegation-manager__title">
			{{ t('doriath', 'Delegations') }}
		</h4>

		<NcEmptyContent v-if="delegations.length === 0"
			:name="t('doriath', 'No delegations')"
			:description="t('doriath', 'No co-owners have been delegated for this secret.')" />

		<ul v-else>
			<NcListItem v-for="delegation in delegations"
				:key="delegation.id"
				:name="delegation.delegatedTo"
				:bold="false">
				<template #subname>
					{{ delegation.isPermanent ? t('doriath', 'Permanent (ownership transferred)') : t('doriath', 'Temporary') }}
				</template>
				<template #actions>
					<NcActionButton :disabled="delegation.isPermanent"
						:aria-label="t('doriath', 'Reclaim delegation')"
						@click="onReclaim">
						<template #icon>
							<Undo :size="20" />
						</template>
						{{ t('doriath', 'Reclaim') }}
					</NcActionButton>
				</template>
			</NcListItem>
		</ul>
	</div>
</template>

<script>
import { NcActionButton, NcEmptyContent, NcListItem } from '@nextcloud/vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import { useDelegationStore } from '../store/modules/delegation.js'

export default {
	name: 'DelegationManager',
	components: { NcActionButton, NcEmptyContent, NcListItem, Undo },

	props: {
		/** @type {string} The secret ID delegations belong to */
		secretId: {
			type: String,
			required: true,
		},
		/** @type {object[]} Delegations for the current secret */
		delegations: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['reclaimed'],

	methods: {
		/**
		 * Reclaim all temporary delegations for the secret.
		 */
		async onReclaim() {
			await useDelegationStore().reclaimDelegation(this.secretId)
			this.$emit('reclaimed')
		},
	},
}
</script>

<style scoped>
.delegation-manager__title {
	margin: 8px 0;
	font-weight: bold;
}
</style>
