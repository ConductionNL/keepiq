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
				<AccountGroupIcon :size="20" class="group-share-list__icon" />
				<div class="group-share-list__info">
					<span class="group-share-list__group">{{ gs.groupId }}</span>
					<NcDateTime
						v-if="gs.createdAt"
						class="group-share-list__date"
						:timestamp="new Date(gs.createdAt)"
						:relative-time="isRecent(gs.createdAt) ? 'long' : false" />
				</div>
				<NcButton
					v-if="isOwner"
					type="tertiary"
					:aria-label="t('doriath', 'Revoke group share for {group}', { group: gs.groupId })"
					:disabled="revoking === gs.id"
					@click="revoke(gs.id)">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcDateTime, NcLoadingIcon } from '@nextcloud/vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { useShareStore } from '../store/modules/share.js'

export default {
	name: 'GroupShareList',

	components: {
		NcButton,
		NcDateTime,
		NcLoadingIcon,
		AccountGroupIcon,
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
		isRecent(dateString) {
			if (!dateString) return false
			const weekMs = 7 * 24 * 60 * 60 * 1000
			return (Date.now() - new Date(dateString).getTime()) < weekMs
		},
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
}

.group-share-list__item {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 6px 0;
}

.group-share-list__icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
	margin-top: 1px;
}

.group-share-list__info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.group-share-list__group {
	font-size: 0.875rem;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
}

.group-share-list__date {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}
</style>
