<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  ApplicationSecretsPanel — sidebar panel that lists the secrets
  attributed to an application. Rendered inside ApplicationDetail and
  exposes a "Write secret" action that emits `@write-secret` to the
  parent (which opens WriteSecretForAppDialog).

  Rendering ciphertext is intentional: only the application itself
  (holding the matching private key) can decrypt. The panel shows
  metadata only (name, type, created_at).

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.5
-->
<template>
	<section
		class="application-secrets-panel"
		data-testid="application-secrets-panel">
		<header class="application-secrets-panel__header">
			<h3>{{ t('doriath', 'Application secrets') }}</h3>
			<NcButton
				v-if="applicationActive"
				variant="primary"
				data-testid="write-secret-button"
				@click="$emit('write-secret')">
				{{ t('doriath', 'Write secret') }}
			</NcButton>
		</header>

		<p v-if="loading" class="application-secrets-panel__loading">
			{{ t('doriath', 'Loading secrets…') }}
		</p>

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-else-if="!applicationActive" class="application-secrets-panel__empty">
			{{
				t(
					'doriath',
					'Approve this application before writing secrets to it.',
				)
			}}
		</p>

		<p v-else-if="secrets.length === 0" class="application-secrets-panel__empty">
			{{
				t(
					'doriath',
					'No secrets have been written for this application yet.',
				)
			}}
		</p>

		<ul v-else class="application-secrets-panel__list">
			<li
				v-for="secret in secrets"
				:key="secret.id"
				class="application-secrets-panel__item"
				data-testid="application-secret-row">
				<div class="application-secrets-panel__meta">
					<strong>{{ secret.name }}</strong>
					<small v-if="secret.url">{{ secret.url }}</small>
				</div>
				<small class="application-secrets-panel__date">
					{{ formatDate(secret.created_at) }}
				</small>
			</li>
		</ul>
	</section>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { useApplicationStore } from '../../store/modules/application.js'

export default {
	name: 'ApplicationSecretsPanel',

	components: {
		NcButton,
		NcNoteCard,
	},

	props: {
		applicationId: {
			type: String,
			required: true,
		},

		applicationActive: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['write-secret'],

	data() {
		return {
			secrets: [],
			loading: false,
			error: '',
		}
	},

	computed: {
		store() {
			return useApplicationStore()
		},
	},

	watch: {
		applicationId: {
			immediate: true,
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		async refresh() {
			if (!this.applicationId || !this.applicationActive) {
				this.secrets = []
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.secrets = await this.store.listApplicationSecrets(
					this.applicationId,
				)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					?? e?.message
					?? this.t('doriath', 'Failed to load secrets')
			} finally {
				this.loading = false
			}
		},

		formatDate(iso) {
			if (!iso) {
				return ''
			}
			try {
				return new Date(iso).toLocaleDateString()
			} catch {
				return iso
			}
		},
	},
}
</script>

<style scoped>
.application-secrets-panel {
	margin-top: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.application-secrets-panel__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.application-secrets-panel__header h3 {
	margin: 0;
}

.application-secrets-panel__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.application-secrets-panel__item {
	display: flex;
	justify-content: space-between;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.application-secrets-panel__item:last-child {
	border-bottom: none;
}

.application-secrets-panel__meta {
	display: flex;
	flex-direction: column;
}

.application-secrets-panel__meta small {
	color: var(--color-text-maxcontrast);
}

.application-secrets-panel__date {
	color: var(--color-text-maxcontrast);
}

.application-secrets-panel__empty,
.application-secrets-panel__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
