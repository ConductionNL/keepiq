<template>
	<div class="group-share-list">
		<NcLoadingIcon v-if="shareStore.loading" />

		<p v-else-if="shareStore.groupShares.length === 0" class="group-share-list__empty">
			{{ t('doriath', 'Not shared with any groups yet.') }}
		</p>

		<ul v-else class="group-share-list__items">
			<li
				v-for="gs in shareStore.groupShares"
				:key="gs.id"
				class="group-share-list__item">
				<span class="group-share-list__group">{{ gs.groupId }}</span>
				<NcButton
					v-if="isOwner"
					type="tertiary"
					:aria-label="t('doriath', 'Revoke group share')"
					:disabled="revoking === gs.id"
					@click="revoke(gs.id)">
					<template #icon>
						<CloseIcon :size="16" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { useShareStore } from '../store/modules/share.js'

export default {
	name: 'GroupShareList',

	components: {
		NcButton,
		NcLoadingIcon,
		CloseIcon,
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
			revoking: null,
		}
	},

	computed: {
		shareStore() {
			return useShareStore()
		},
	},

	watch: {
		secretId(newId) {
			this.shareStore.fetchGroupShares(newId)
		},
	},

	mounted() {
		this.shareStore.fetchGroupShares(this.secretId)
	},

	methods: {
		async revoke(groupShareId) {
			this.revoking = groupShareId
			try {
				await this.shareStore.revokeGroupShare(groupShareId)
			} finally {
				this.revoking = null
			}
		},
	},
}
</script>

<style scoped>
.group-share-list__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	margin: 0;
}

.group-share-list__items {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.group-share-list__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 4px 0;
}

.group-share-list__group {
	font-size: 0.875rem;
}
</style>
