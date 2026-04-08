<template>
	<div class="recipient-list">
		<NcLoadingIcon v-if="shareStore.loading" />

		<p v-else-if="shareStore.shares.length === 0" class="recipient-list__empty">
			{{ t('doriath', 'Not shared with anyone yet.') }}
		</p>

		<ul v-else class="recipient-list__items">
			<li
				v-for="share in shareStore.shares"
				:key="share.id"
				class="recipient-list__item">
				<AccountIcon :size="20" class="recipient-list__icon" />
				<div class="recipient-list__info">
					<span class="recipient-list__user">{{ share.targetUserId }}</span>
					<NcDateTime
						v-if="share.createdAt"
						class="recipient-list__date"
						:timestamp="new Date(share.createdAt)"
						:relative-time="isRecent(share.createdAt) ? 'long' : false" />
				</div>
				<NcButton
					v-if="isOwner"
					type="tertiary"
					:aria-label="t('doriath', 'Revoke share for {user}', { user: share.targetUserId })"
					:disabled="revoking === share.id"
					@click="revoke(share.id)">
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
import AccountIcon from 'vue-material-design-icons/Account.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { useShareStore } from '../store/modules/share.js'

export default {
	name: 'RecipientList',

	components: {
		NcButton,
		NcDateTime,
		NcLoadingIcon,
		AccountIcon,
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
			this.shareStore.fetchShares(newId)
		},
	},

	mounted() {
		this.shareStore.fetchShares(this.secretId)
	},

	methods: {
		isRecent(dateString) {
			if (!dateString) return false
			const weekMs = 7 * 24 * 60 * 60 * 1000
			return (Date.now() - new Date(dateString).getTime()) < weekMs
		},
		async revoke(shareId) {
			this.revoking = shareId
			try {
				await this.shareStore.revokeShare(shareId)
			} finally {
				this.revoking = null
			}
		},
	},
}
</script>

<style scoped>
.recipient-list__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	margin: 0;
}

.recipient-list__items {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
}

.recipient-list__item {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 6px 0;
}

.recipient-list__icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
	margin-top: 1px;
}

.recipient-list__info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.recipient-list__user {
	font-size: 0.875rem;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
}

.recipient-list__date {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}
</style>
