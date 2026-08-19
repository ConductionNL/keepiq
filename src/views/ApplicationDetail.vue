<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  ApplicationDetail — manifest-v2 page rendered for `/applications/:id`.

  Shows the application metadata (name, description, type, status,
  registered_by, approved_by, dates), the active EncryptionSuite info
  (certificate snippet, status), the secrets-attributed list (via
  ApplicationSecretsPanel), and admin-only delete + "write secret"
  actions.

  Owner restrictions follow ApplicationService::get(): admin sees any;
  non-admin sees own registrations + active apps. The 403/404 surface
  is forwarded as an inline NcNoteCard rather than a route bounce so
  the manifest-v2 router stays simple.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.2
-->
<template>
	<div class="application-detail" data-testid="application-detail">
		<NcButton
			variant="tertiary"
			class="application-detail__back"
			@click="goBack">
			<template #icon>
				<ArrowLeft :size="20" />
			</template>
			{{ t('doriath', 'Back to applications') }}
		</NcButton>

		<NcLoadingIcon
			v-if="loading"
			:size="32"
			class="application-detail__loading" />

		<NcEmptyContent
			v-else-if="error"
			:name="t('doriath', 'Cannot open application')"
			:description="error">
			<template #icon>
				<AlertCircleOutline />
			</template>
		</NcEmptyContent>

		<div v-else-if="application" class="application-detail__layout">
			<header class="application-detail__header">
				<h2 class="application-detail__title">
					{{ application.name }}
					<span
						class="application-detail__status"
						:class="`application-detail__status--${application.status}`">
						{{ statusLabel }}
					</span>
				</h2>
				<p
					v-if="application.description"
					class="application-detail__description">
					{{ application.description }}
				</p>
			</header>

			<section class="application-detail__meta">
				<dl class="application-detail__grid">
					<dt>{{ t('doriath', 'Type') }}</dt>
					<dd>{{ application.type }}</dd>

					<dt>{{ t('doriath', 'Registered by') }}</dt>
					<dd>
						{{ application.registered_by || t('doriath', 'anonymous') }}
					</dd>

					<dt v-if="application.approved_by">
						{{ t('doriath', 'Approved by') }}
					</dt>
					<dd v-if="application.approved_by">
						{{ application.approved_by }}
					</dd>

					<dt>{{ t('doriath', 'Created at') }}</dt>
					<dd>{{ formatDate(application.created_at) }}</dd>

					<dt v-if="application.approved_at">
						{{ t('doriath', 'Approved at') }}
					</dt>
					<dd v-if="application.approved_at">
						{{ formatDate(application.approved_at) }}
					</dd>
				</dl>
			</section>

			<section class="application-detail__suite">
				<h3>{{ t('doriath', 'Encryption suite') }}</h3>
				<p v-if="suiteLoading">
					{{ t('doriath', 'Loading certificate…') }}
				</p>
				<NcNoteCard v-else-if="!certificate" type="warning">
					{{
						t(
							'doriath',
							'No active encryption suite for this application.',
						)
					}}
				</NcNoteCard>
				<template v-else>
					<p class="application-detail__suite-status">
						{{
							t(
								'doriath',
								'Certificate active. The application decrypts secrets with its private key.',
							)
						}}
					</p>
					<details class="application-detail__cert">
						<summary>{{ t('doriath', 'Show certificate') }}</summary>
						<pre class="application-detail__cert-pem">{{
							certificate
						}}</pre>
					</details>
				</template>
			</section>

			<ApplicationSecretsPanel
				:applicationId="application.id"
				:applicationActive="application.status === 'active'"
				@writeSecret="openWriteDialog" />

			<ApplicationLeasesPanel :applicationId="application.id" />

			<!-- What this application is asking humans for. Until now nobody could
			     see it: the requests carry `created_by = application:<id>`, which no
			     user listing can ever match, and their target Secrets are
			     application-owned so they appear in no vault. Six such requests
			     existed on the development instance, invisible to every person on it.

			     Admin-only, and the endpoint enforces that — this section is simply
			     not rendered for anyone else, so a non-administrator is not shown a
			     panel that would only ever show them an error. -->
			<section
				v-if="isAdmin"
				class="application-detail__requests"
				data-testid="application-requests-section">
				<h3>{{ t('doriath', 'Outstanding secret requests') }}</h3>
				<p class="application-detail__requests-hint">
					{{
						t(
							'doriath',
							'Credentials this application has asked people to fill in. A pending link works for anyone who has it, so revoke any that should no longer be usable.',
						)
					}}
				</p>
				<SecretRequestList :applicationId="application.id" />
			</section>

			<section v-if="canDelete" class="application-detail__actions">
				<NcButton
					variant="error"
					data-testid="delete-button"
					@click="confirmDelete">
					{{ t('doriath', 'Delete application') }}
				</NcButton>
			</section>
		</div>

		<WriteSecretForAppDialog
			v-if="writeDialogOpen"
			:open="writeDialogOpen"
			:applicationId="application?.id"
			:applicationName="application?.name"
			@close="closeWriteDialog"
			@written="onSecretWritten" />

		<ApplicationDeleteDialog
			v-if="deleteDialogOpen"
			:open="deleteDialogOpen"
			@close="deleteDialogOpen = false"
			@confirm="performDelete" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ApplicationLeasesPanel from '../components/application/ApplicationLeasesPanel.vue'
import ApplicationSecretsPanel from '../components/application/ApplicationSecretsPanel.vue'
import SecretRequestList from '../components/secretRequest/SecretRequestList.vue'
import ApplicationDeleteDialog from '../dialogs/ApplicationDeleteDialog.vue'
import WriteSecretForAppDialog from '../dialogs/WriteSecretForAppDialog.vue'
import { useApplicationStore } from '../store/modules/application.js'
import { useSettingsStore } from '../store/modules/settings.js'

export default {
	name: 'ApplicationDetail',

	components: {
		AlertCircleOutline,
		ApplicationDeleteDialog,
		ApplicationLeasesPanel,
		ApplicationSecretsPanel,
		ArrowLeft,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		SecretRequestList,
		WriteSecretForAppDialog,
	},

	props: {
		id: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			suiteLoading: false,
			certificate: '',
			writeDialogOpen: false,
			deleteDialogOpen: false,
		}
	},

	computed: {
		store() {
			return useApplicationStore()
		},

		/**
		 * Whether the current user is an administrator.
		 *
		 * Gates the outstanding-requests section. This is presentation only — the
		 * endpoint refuses a non-administrator regardless, and the authority must
		 * stay there. Hiding the section is about not showing someone a panel that
		 * could only ever render an error for them.
		 *
		 * Read from the settings store, which resolves it from `/api/settings` at
		 * boot, rather than from the DOM (ADR-004 / gate-10).
		 *
		 * @return {boolean} True for an administrator.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		isAdmin() {
			return useSettingsStore().isAdmin === true
		},

		application() {
			return this.store.currentApplication
		},

		statusLabel() {
			switch (this.application?.status) {
				case 'active':
					return this.t('doriath', 'Active')
				case 'pending':
					return this.t('doriath', 'Pending')
				default:
					return this.application?.status || ''
			}
		},

		canDelete() {
			// Server enforces admin-only delete; the button is only
			// rendered when the row is visible to the current user.
			return this.application?.status !== undefined
		},

		routeId() {
			return this.id || this.$route?.params?.id || ''
		},
	},

	watch: {
		routeId: {
			immediate: true,
			handler(value) {
				if (value) {
					this.load(value)
				}
			},
		},
	},

	methods: {
		async load(id) {
			this.loading = true
			this.error = ''
			try {
				await this.store.fetchApplication(id)
				await this.loadCertificate(id)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					?? e?.message
					?? this.t('doriath', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		async loadCertificate(id) {
			this.suiteLoading = true
			this.certificate = ''
			try {
				this.certificate = await this.store.fetchCertificate(id)
			} catch {
				// 404 / 400 are non-fatal: the section shows the
				// "no active suite" note instead.
				this.certificate = ''
			} finally {
				this.suiteLoading = false
			}
		},

		formatDate(iso) {
			if (!iso) {
				return ''
			}
			try {
				const date = new Date(iso)
				return date.toLocaleString()
			} catch {
				return iso
			}
		},

		goBack() {
			if (this.$router) {
				this.$router.push({ name: 'ApplicationRegister' })
			}
		},

		openWriteDialog() {
			this.writeDialogOpen = true
		},

		closeWriteDialog() {
			this.writeDialogOpen = false
		},

		onSecretWritten() {
			this.writeDialogOpen = false
			// Panel re-fetches its own list via the @written bridge.
			this.$nextTick(() => {
				const panel = this.$el?.querySelector(
					'[data-testid="application-secrets-panel"]',
				)
				if (panel && typeof panel.refresh === 'function') {
					panel.refresh()
				}
			})
		},

		/**
		 * Open the confirmation dialog. The delete itself is performDelete().
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-delete-application
		 */
		confirmDelete() {
			this.deleteDialogOpen = true
		},

		/**
		 * Perform the delete once the user has confirmed in the dialog.
		 *
		 * @spec openspec/specs/application-mgmt/spec.md#requirement-delete-application
		 */
		async performDelete() {
			this.deleteDialogOpen = false
			try {
				await this.store.deleteApplication(this.application.id)
				if (this.$router) {
					this.$router.push({ name: 'ApplicationRegister' })
				}
			} catch (e) {
				this.error =
					e?.response?.data?.message
					?? e?.message
					?? this.t('doriath', 'Delete failed')
			}
		},
	},
}
</script>

<style scoped>
.application-detail {
	padding: 16px;
	max-width: 960px;
	margin: 0 auto;
}

.application-detail__back {
	margin-bottom: 16px;
}

.application-detail__header {
	margin-bottom: 24px;
}

.application-detail__title {
	display: flex;
	gap: 12px;
	align-items: center;
	margin: 0 0 8px 0;
}

.application-detail__status {
	font-size: 0.8em;
	padding: 2px 10px;
	border-radius: 12px;
	background: var(--color-background-darker);
}

.application-detail__status--active {
	background: var(--color-success);
	color: var(--color-success-text);
}

.application-detail__status--pending {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.application-detail__description {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.application-detail__grid {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 8px 16px;
}

.application-detail__grid dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.application-detail__grid dd {
	margin: 0;
}

.application-detail__suite,
.application-detail__meta {
	margin-bottom: 24px;
}

.application-detail__cert-pem {
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: 4px;
	font-size: 0.85em;
	overflow-x: auto;
	max-height: 200px;
}

.application-detail__actions {
	margin-top: 32px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
