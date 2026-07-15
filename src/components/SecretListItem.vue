<template>
	<div class="secret-list-item"
		role="button"
		tabindex="0"
		:aria-label="t('doriath', 'Open {name}', { name: secret.name })"
		:class="{ 'secret-list-item--blocked': secret.blocked }"
		@click="$emit('open', secret.id)"
		@keydown.enter="onRowActivate"
		@keydown.space.prevent="onRowActivate">
		<span class="secret-list-item__icon">
			<img v-if="faviconUrl && !faviconFailed"
				:src="faviconUrl"
				:alt="''"
				width="24"
				height="24"
				@error="faviconFailed = true">
			<component :is="iconComponent" v-else :size="24" />
		</span>

		<span class="secret-list-item__main">
			<span class="secret-list-item__name">
				{{ secret.name }}
				<StrengthBadge v-if="!secret.blocked" :secret-id="secret.id" />
			</span>
			<span v-if="secret.url" class="secret-list-item__url">{{ secret.url }}</span>
			<span v-if="secret.tombstonedAt" class="secret-list-item__tombstone">
				{{ t('doriath', 'Shared by a deleted account — no longer synced') }}
			</span>
		</span>

		<span v-if="secret.blocked" class="secret-list-item__blocked">
			<Lock :size="16" />
			{{ t('doriath', 'Locked — suite revoked') }}
		</span>

		<span v-else
			class="secret-list-item__actions"
			@click.stop
			@keydown.enter.stop
			@keydown.space.stop>
			<CopyButton :resolve="resolveKey"
				:label="t('doriath', 'Copy password')"
				@copied="$emit('copied')" />
		</span>
	</div>
</template>

<script>
import Lock from 'vue-material-design-icons/Lock.vue'
import Key from 'vue-material-design-icons/Key.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import Console from 'vue-material-design-icons/Console.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import NoteText from 'vue-material-design-icons/NoteText.vue'
import Database from 'vue-material-design-icons/Database.vue'
import CopyButton from './CopyButton.vue'
import StrengthBadge from './StrengthBadge.vue'
import { resolveFaviconUrl, typeIconName } from '../utils/favicon.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

/**
 * A single secret row: favicon (or type icon), name, url, and a copy button.
 * Copy triggers on-demand decryption of the key via the secret store.
 */
export default {
	name: 'SecretListItem',

	components: {
		Lock,
		Key,
		CodeTags,
		Console,
		ShieldCheck,
		NoteText,
		Database,
		CopyButton,
		StrengthBadge,
	},

	props: {
		/** The secret (metadata + ciphertext, or blocked metadata). */
		secret: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			faviconFailed: false,
		}
	},

	computed: {
		faviconUrl() {
			return resolveFaviconUrl(this.secret.url)
		},
		iconComponent() {
			const typeStore = useSecretTypeStore()
			const type = typeStore.typesById[this.secret.typeId]
			return typeIconName(type ? type.name : 'login')
		},
	},

	methods: {
		t,

		/**
		 * Open the secret when the row itself is activated via keyboard.
		 *
		 * Guards against keyboard activation of an inner control (e.g. the
		 * copy-password button) bubbling up and triggering row navigation:
		 * only the row element itself, when focused, opens the secret.
		 *
		 * @param {KeyboardEvent} event The keydown event.
		 * @return {void}
		 */
		onRowActivate(event) {
			if (event.target !== event.currentTarget) {
				return
			}
			this.$emit('open', this.secret.id)
		},

		/**
		 * Resolve the decrypted key for the copy button.
		 *
		 * @return {Promise<string>}
		 */
		async resolveKey() {
			const secretStore = useSecretStore()
			const full = await secretStore.fetchSecret(this.secret.id)
			return full.key || ''
		},
	},
}
</script>

<style scoped>
.secret-list-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.secret-list-item:hover {
	background-color: var(--color-background-hover);
}

.secret-list-item:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
	background-color: var(--color-background-hover);
}

.secret-list-item__main {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-width: 0;
}

.secret-list-item__name {
	font-weight: bold;
}

.secret-list-item__url {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.secret-list-item__blocked {
	display: flex;
	align-items: center;
	gap: 4px;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}
</style>
