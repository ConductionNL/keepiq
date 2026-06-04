<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Doriath app shell — thin CnAppRoot wrapper (hydra ADR-024 / ADR-036).

  CnAppRoot handles the dependency check, manifest-driven CnAppNav,
  and per-route page dispatch (manifest.pages[].type →
  Cn{Dashboard,Settings,Index,Detail,…}Page). For doriath the dashboard
  is rendered via the v2 widget grid (the manifest's Dashboard page
  carries `widgets[]` targeting the `body` slot, so CnPageRenderer
  short-circuits the default CnDashboardPage component and mounts
  CnWidgetGrid directly — matches the shillinq#166 pattern).

  Doriath-specific concerns the shell still owns:
  - Pinia stores boot once (settings + object configure against
    OpenRegister).
  - Lock-screen gating: whenever the session locks, redirect to the
    Lock route. When the user navigates to `/lock` while unlocked
    (e.g. via the "Lock vault" footer menu entry), call
    `session.lock()` so the screen renders the unlock form rather
    than bouncing back to Dashboard.
  - Session-timeout polling + visibility/beforeunload key clearing.
  - User-settings dialog: the legacy UserSettings.vue contents are
    injected into CnAppRoot's `#user-settings` slot so the manifest
    entry `action: "user-settings"` opens the same Session / Security
    / Encryption sections the previous MainMenu footer item did.
-->
<template>
	<CnAppRoot
		:manifest="manifest"
		:custom-components="customComponents"
		:page-types="pageTypes"
		:registry="registry"
		app-id="doriath"
		:translate="translateForApp"
		:permissions="permissions">
		<!-- User-settings dialog body (opened from the manifest's
		     `action: "user-settings"` menu entry via CnAppRoot's
		     cnOpenUserSettings inject). Sections preserve the legacy
		     UserSettings.vue surface unchanged. -->
		<template #user-settings>
			<NcAppSettingsSection
				id="session"
				:name="t('doriath', 'Session')">
				<template #icon>
					<TimerIcon :size="20" />
				</template>
				<div class="user-settings__field">
					<NcSelect
						v-model="sessionTimeout"
						:options="timeoutOptions"
						:input-label="t('doriath', 'Session timeout')"
						label="label"
						:reduce="opt => opt.value"
						@input="saveTimeout" />
				</div>
			</NcAppSettingsSection>

			<NcAppSettingsSection
				id="notifications"
				:name="t('doriath', 'Notifications')">
				<template #icon>
					<BellIcon :size="20" />
				</template>
				<div class="user-settings__field">
					<NcCheckboxRadioSwitch
						:checked="notifyShares"
						type="switch"
						@update:checked="updateNotify('notify_shares', $event)">
						{{ t('doriath', 'Notify me when a secret is shared with me') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="user-settings__field">
					<NcCheckboxRadioSwitch
						:checked="notifyRequests"
						type="switch"
						@update:checked="updateNotify('notify_requests', $event)">
						{{ t('doriath', 'Notify me about access requests') }}
					</NcCheckboxRadioSwitch>
				</div>
			</NcAppSettingsSection>

			<NcAppSettingsSection
				id="security"
				:name="t('doriath', 'Security')">
				<template #icon>
					<ShieldIcon :size="20" />
				</template>
				<div class="user-settings__field">
					<MasterPasswordForm />
				</div>
				<div class="user-settings__field">
					<NcButton type="error" @click="showRecovery = !showRecovery">
						{{ t('doriath', 'My master password was compromised') }}
					</NcButton>
					<CompromiseRecoveryForm v-if="showRecovery" />
				</div>
			</NcAppSettingsSection>

			<NcAppSettingsSection
				id="encryption"
				:name="t('doriath', 'Encryption')">
				<template #icon>
					<KeyIcon :size="20" />
				</template>
				<div v-if="suiteStore.currentSuite" class="user-settings__suite-info">
					<p><strong>{{ t('doriath', 'Status') }}:</strong> {{ suiteStore.currentSuite.status }}</p>
					<p><strong>{{ t('doriath', 'Created') }}:</strong> {{ suiteStore.currentSuite.createdAt }}</p>
					<p><strong>{{ t('doriath', 'Suite ID') }}:</strong> {{ suiteStore.currentSuite.id }}</p>

					<template v-if="suiteStore.currentSuite.status === 'active'">
						<NcNoteCard v-if="revokeConfirm" type="warning">
							{{ t('doriath', 'Revoking your encryption suite will make all your secrets inaccessible until an administrator reinstates it. This cannot be undone by you.') }}
							<div style="margin-top: 0.5rem;">
								<NcTextField
									v-model="revokeReason"
									:label="t('doriath', 'Reason for revocation')"
									:placeholder="t('doriath', 'e.g. Device lost, key compromised')" />
							</div>
							<div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
								<NcButton
									type="error"
									:disabled="!revokeReason || revoking"
									@click="handleRevoke">
									{{ revoking ? t('doriath', 'Revoking...') : t('doriath', 'Confirm revocation') }}
								</NcButton>
								<NcButton type="secondary" @click="revokeConfirm = false">
									{{ t('doriath', 'Cancel') }}
								</NcButton>
							</div>
						</NcNoteCard>
						<NcButton
							v-else
							type="warning"
							@click="revokeConfirm = true">
							{{ t('doriath', 'Revoke encryption suite') }}
						</NcButton>
					</template>

					<NcNoteCard v-if="revokeSuccess" type="success">
						{{ t('doriath', 'Encryption suite revoked. Contact an administrator to reinstate it.') }}
					</NcNoteCard>
					<NcNoteCard v-if="revokeError" type="error">
						{{ revokeError }}
					</NcNoteCard>
				</div>
				<NcEmptyContent
					v-else
					:name="t('doriath', 'No encryption suite')"
					:description="t('doriath', 'Unlock the vault to set up encryption')">
					<template #icon>
						<KeyIcon :size="64" />
					</template>
				</NcEmptyContent>
			</NcAppSettingsSection>
		</template>
	</CnAppRoot>
</template>

<script>
import { translate as ncT } from '@nextcloud/l10n'
// eslint-disable-next-line import/named
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { NcAppSettingsSection, NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import TimerIcon from 'vue-material-design-icons/Timer.vue'
import ShieldIcon from 'vue-material-design-icons/Shield.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import MasterPasswordForm from './components/MasterPasswordForm.vue'
import CompromiseRecoveryForm from './components/CompromiseRecoveryForm.vue'
import { initializeStores } from './store/store.js'
import { useSessionStore } from './store/modules/session.js'
import { useEncryptionSuiteStore } from './store/modules/encryptionSuite.js'
import { useSettingsStore } from './store/modules/settings.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		NcAppSettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcNoteCard,
		NcSelect,
		NcTextField,
		TimerIcon,
		ShieldIcon,
		KeyIcon,
		BellIcon,
		MasterPasswordForm,
		CompromiseRecoveryForm,
	},

	props: {
		/**
		 * Manifest object — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},
		/**
		 * Consumer-injected components used by `type: "custom"` pages
		 * (page.component). Derived from the `kind:"page"` entries of
		 * src/registry.js in src/main.js.
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 * Wired through to descendant `CnPageRenderer` instances.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
		/**
		 * 5-kind component registry for v2 manifests (hydra ADR-036).
		 * Map of registry key → `{ kind, component, ...metadata }`.
		 * See src/registry.js for the doriath entries.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			storesReady: false,
			timeoutInterval: null,
			sessionTimeout: 'session',
			notifyShares: true,
			notifyRequests: true,
			showRecovery: false,
			revokeConfirm: false,
			revokeReason: '',
			revoking: false,
			revokeSuccess: false,
			revokeError: null,
			timeoutOptions: [
				{ value: 'session', label: ncT('doriath', 'Nextcloud session') },
				{ value: '10min', label: ncT('doriath', '10 minutes') },
				{ value: '30min', label: ncT('doriath', '30 minutes') },
			],
		}
	},

	computed: {
		/**
		 * Current Nextcloud user permissions, surfaced to the app shell.
		 *
		 * @return {Array} Permission list (empty when unauthenticated).
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},
		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia session store with no domain logic.
		 */
		sessionStore() {
			return useSessionStore()
		},
		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia encryption-suite store with no domain logic.
		 */
		suiteStore() {
			return useEncryptionSuiteStore()
		},
		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia settings store with no domain logic.
		 */
		settingsStore() {
			return useSettingsStore()
		},
		/**
		 * Whether the vault is locked (no in-memory CryptoKey).
		 *
		 * @return {boolean} True when locked.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		isLocked() {
			return this.sessionStore.isLocked
		},
	},

	watch: {
		/**
		 * Lock-screen gating: whenever the session locks, force the
		 * user to the Lock route. Mirrors the legacy router guard.
		 *
		 * @param {boolean} locked New lock state.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		isLocked(locked) {
			if (locked && this.$route?.name !== 'Lock') {
				this.$router.replace({
					name: 'Lock',
					query: { returnUrl: this.$route?.fullPath },
				})
			}
		},
		/**
		 * Inverse direction: if the user navigates to `/lock` while
		 * still unlocked (the "Lock vault" footer menu entry), call
		 * `session.lock()` so LockScreen renders the unlock form
		 * rather than the redirect-back path. Skipped when there's a
		 * returnUrl query (that path is the post-lock redirect we
		 * just emitted above and the session is already locked).
		 *
		 * @param {object} to Target route.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		$route(to) {
			if (
				to?.name === 'Lock'
				&& !this.sessionStore.isLocked
				&& !to.query?.returnUrl
				&& this.suiteStore.currentSuite
			) {
				this.sessionStore.lock()
			}
		},
	},

	/**
	 * Boot the app shell: initialise stores, redirect to the lock screen
	 * when already locked, and start the session-timeout poll + listeners.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
	 */
	async created() {
		await initializeStores()
		this.storesReady = true

		// Hydrate the user-settings dialog from the persisted per-user
		// preferences (session timeout + notification toggles). Falls
		// back to the in-data defaults when the fetch yields nothing.
		const prefs = await this.settingsStore.fetchUserPreferences()
		if (prefs !== null) {
			this.sessionTimeout = prefs.session_timeout ?? this.sessionTimeout
			this.notifyShares = prefs.notify_shares ?? this.notifyShares
			this.notifyRequests = prefs.notify_requests ?? this.notifyRequests
			this.applyTimeout()
		}

		// Mirror the legacy App.vue boot: on first paint, if the
		// session is already locked, push to /lock with the current
		// path as the return URL. The router-level guard from the
		// pre-Tier-4 router is gone (vue-router is now built from
		// the manifest), so the App.vue lifecycle owns the redirect.
		if (this.sessionStore.isLocked && this.$route?.name !== 'Lock') {
			this.$router.replace({
				name: 'Lock',
				query: { returnUrl: this.$route?.fullPath },
			})
		}

		// Poll every 10 s for session-timeout expiry.
		this.timeoutInterval = setInterval(() => {
			this.sessionStore.checkTimeout()
		}, 10000)

		// Re-check on tab visibility change (covers laptop-suspend cases).
		document.addEventListener('visibilitychange', this.handleVisibilityChange)

		// Best-effort key clear on tab close.
		window.addEventListener('beforeunload', this.handleBeforeUnload)
	},

	/**
	 * Tear down the session-timeout poll and event listeners on unmount.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
	 */
	beforeDestroy() {
		if (this.timeoutInterval) {
			clearInterval(this.timeoutInterval)
		}
		document.removeEventListener('visibilitychange', this.handleVisibilityChange)
		window.removeEventListener('beforeunload', this.handleBeforeUnload)
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import
		 * so the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec exclude Pure i18n wrapper around Nextcloud translate — no domain logic.
		 */
		translateForApp(key) {
			return ncT('doriath', key)
		},

		/**
		 * Re-check the session timeout when the tab regains focus
		 * (covers laptop-suspend cases where timers stall).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		handleVisibilityChange() {
			if (document.visibilityState === 'visible') {
				this.sessionStore.checkTimeout()
			}
		},

		/**
		 * Best-effort clear of the in-memory key when the tab closes.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		handleBeforeUnload() {
			this.sessionStore.lock()
		},

		/**
		 * Map the session-timeout enum to a millisecond duration on the
		 * session store (client-side timeout enforcement).
		 *
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-5.1
		 */
		applyTimeout() {
			const timeouts = { session: 0, '10min': 600000, '30min': 1800000 }
			this.sessionStore.timeout = timeouts[this.sessionTimeout] || 600000
		},

		/**
		 * Persist the chosen session-timeout preference: apply it to the
		 * session store for immediate effect and write it through to the
		 * per-user settings API so it survives reloads / other devices.
		 *
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-5.1
		 */
		async saveTimeout() {
			this.applyTimeout()
			await this.settingsStore.saveUserPreferences({ session_timeout: this.sessionTimeout })
		},

		/**
		 * Persist a notification-toggle change to the per-user settings API
		 * and reflect it locally.
		 *
		 * @param {string} key The notification preference key.
		 * @param {boolean} value The new toggle value.
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-5.2
		 */
		async updateNotify(key, value) {
			if (key === 'notify_shares') {
				this.notifyShares = value
			} else if (key === 'notify_requests') {
				this.notifyRequests = value
			}
			await this.settingsStore.saveUserPreferences({ [key]: value })
		},

		/**
		 * Revoke the current user's encryption suite from the app shell,
		 * surfacing success/error state to the UI.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async handleRevoke() {
			this.revoking = true
			this.revokeError = null
			this.revokeSuccess = false

			try {
				await this.suiteStore.revokeSuite(this.revokeReason)
				this.revokeSuccess = true
				this.revokeConfirm = false
				this.revokeReason = ''
			} catch (e) {
				this.revokeError = e.response?.data?.message || e.message || ncT('doriath', 'Failed to revoke suite')
			} finally {
				this.revoking = false
			}
		},
	},
}
</script>

<style scoped>
.user-settings__field {
	margin-bottom: 1rem;
}

.user-settings__suite-info p {
	margin: 0.25rem 0;
}
</style>
