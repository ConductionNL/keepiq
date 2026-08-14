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
	<div class="doriath-shell">
		<!-- Stale-data banner (offline-readonly-cache §4.3): shown whenever the
		     vault is being served from the offline cache. -->
		<div
			v-if="offlineStore.servedFromCache"
			class="doriath-offline-banner"
			data-testid="offline-stale-banner">
			{{
				t('doriath', 'Offline — read-only. Last synced {when}.', {
					when: syncedLabel,
				})
			}}
		</div>

		<!-- An interrupted compromise recovery leaves the vault write-locked with
		     nothing else in the UI saying why, so this sits at shell level rather
		     than inside any one view. It renders nothing when no migration is in
		     progress. -->
		<MigrationResumeBanner />

		<CnAppRoot
			:aiCompanion="true"
			:manifest="manifest"
			:customComponents="customComponents"
			:pageTypes="pageTypes"
			:registry="registry"
			appId="doriath"
			:translate="translateForApp"
			:permissions="permissions">
			<!-- User-settings dialog body (opened from the manifest's
		     `action: "user-settings"` menu entry via CnAppRoot's
		     cnOpenUserSettings inject). Sections preserve the legacy
		     UserSettings.vue surface unchanged. -->
			<template #user-settings>
				<NcAppSettingsSection id="session" :name="t('doriath', 'Session')">
					<template #icon>
						<TimerIcon :size="20" />
					</template>
					<div class="user-settings__field">
						<NcSelect
							v-model="sessionTimeout"
							:options="timeoutOptions"
							:inputLabel="t('doriath', 'Session timeout')"
							label="label"
							:reduce="(opt) => opt.value"
							@input="saveTimeout" />
					</div>
				</NcAppSettingsSection>

				<NcAppSettingsSection id="security" :name="t('doriath', 'Security')">
					<template #icon>
						<ShieldIcon :size="20" />
					</template>
					<div class="user-settings__field">
						<MasterPasswordForm />
					</div>
					<div class="user-settings__field">
						<PasskeyManager />
					</div>
					<div class="user-settings__field">
						<NcButton
							variant="error"
							@click="showRecovery = !showRecovery">
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
					<div
						v-if="suiteStore.currentSuite"
						class="user-settings__suite-info">
						<p>
							<strong>{{ t('doriath', 'Status') }}:</strong>
							{{ suiteStore.currentSuite.status }}
						</p>
						<p>
							<strong>{{ t('doriath', 'Created') }}:</strong>
							{{ suiteStore.currentSuite.createdAt }}
						</p>
						<p>
							<strong>{{ t('doriath', 'Suite ID') }}:</strong>
							{{ suiteStore.currentSuite.id }}
						</p>

						<template v-if="suiteStore.currentSuite.status === 'active'">
							<NcNoteCard v-if="revokeConfirm" type="warning">
								{{
									t(
										'doriath',
										'Revoking your encryption suite will make all your secrets inaccessible until an administrator reinstates it. This cannot be undone by you.',
									)
								}}
								<div style="margin-top: 0.5rem">
									<NcTextField
										v-model="revokeReason"
										:label="
											t('doriath', 'Reason for revocation')
										"
										:placeholder="
											t(
												'doriath',
												'e.g. Device lost, key compromised',
											)
										" />
								</div>
								<div
									style="
										display: flex;
										gap: 0.5rem;
										margin-top: 0.5rem;
									">
									<NcButton
										variant="error"
										:disabled="!revokeReason || revoking"
										@click="handleRevoke">
										{{
											revoking
												? t('doriath', 'Revoking...')
												: t('doriath', 'Confirm revocation')
										}}
									</NcButton>
									<NcButton
										variant="secondary"
										@click="revokeConfirm = false">
										{{ t('doriath', 'Cancel') }}
									</NcButton>
								</div>
							</NcNoteCard>
							<NcButton
								v-else
								variant="warning"
								@click="revokeConfirm = true">
								{{ t('doriath', 'Revoke encryption suite') }}
							</NcButton>
						</template>

						<NcNoteCard v-if="revokeSuccess" type="success">
							{{
								t(
									'doriath',
									'Encryption suite revoked. Contact an administrator to reinstate it.',
								)
							}}
						</NcNoteCard>
						<NcNoteCard v-if="revokeError" type="error">
							{{ revokeError }}
						</NcNoteCard>
					</div>
					<NcEmptyContent
						v-else
						:name="t('doriath', 'No encryption suite')"
						:description="
							t('doriath', 'Unlock the vault to set up encryption')
						">
						<template #icon>
							<KeyIcon :size="64" />
						</template>
					</NcEmptyContent>
				</NcAppSettingsSection>

				<NcAppSettingsSection
					id="browser-extension"
					:name="t('doriath', 'Browser extension')">
					<template #icon>
						<PuzzleIcon :size="20" />
					</template>
					<p>
						{{
							t(
								'doriath',
								'The Doriath browser extension autofills your logins, provides passkeys, and shows TOTP codes — decrypting everything inside the extension. The server only ever ships encrypted blobs, so your master password and secrets never leave your device.',
							)
						}}
					</p>
					<ol class="user-settings__steps">
						<li>
							{{
								t(
									'doriath',
									'Install the Doriath extension for your browser.',
								)
							}}
						</li>
						<li>
							{{
								t(
									'doriath',
									'Create a dedicated app password in Nextcloud security settings (never use your login password).',
								)
							}}
						</li>
						<li>
							{{
								t(
									'doriath',
									'In the extension, enter this server URL, your username, and the app password, then unlock with your master password.',
								)
							}}
						</li>
					</ol>
					<div class="user-settings__field">
						<NcButton :href="securitySettingsUrl" variant="secondary">
							{{ t('doriath', 'Open Nextcloud security settings') }}
						</NcButton>
					</div>
					<p class="user-settings__hint">
						{{
							t(
								'doriath',
								'Revoke the app password in Nextcloud security settings at any time to disconnect the extension.',
							)
						}}
					</p>
				</NcAppSettingsSection>
			</template>
		</CnAppRoot>
	</div>
</template>

<script>
// eslint-disable-next-line import/named
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppSettingsSection,
	NcButton,
	NcEmptyContent,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import PuzzleIcon from 'vue-material-design-icons/Puzzle.vue'
import ShieldIcon from 'vue-material-design-icons/Shield.vue'
import TimerIcon from 'vue-material-design-icons/Timer.vue'
import CompromiseRecoveryForm from './components/CompromiseRecoveryForm.vue'
import MasterPasswordForm from './components/MasterPasswordForm.vue'
import MigrationResumeBanner from './components/MigrationResumeBanner.vue'
import PasskeyManager from './components/PasskeyManager.vue'
import { handleLockTransition } from './router/guards.js'
import { useEncryptionSuiteStore } from './store/modules/encryptionSuite.js'
import { useOfflineStore } from './store/modules/offline.js'
import { useSessionStore } from './store/modules/session.js'
import { initializeStores } from './store/store.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		NcAppSettingsSection,
		NcButton,
		NcEmptyContent,
		NcNoteCard,
		NcSelect,
		NcTextField,
		TimerIcon,
		ShieldIcon,
		KeyIcon,
		PuzzleIcon,
		MasterPasswordForm,
		PasskeyManager,
		CompromiseRecoveryForm,
		MigrationResumeBanner,
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
		 * Link to Nextcloud personal security settings, where the extension's
		 * app password is created and revoked (browser-extension-autofill §5.1).
		 *
		 * @return {string}
		 */
		securitySettingsUrl() {
			return generateUrl('/settings/user/security')
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
		 * @spec exclude Store-ref passthrough — returns the Pinia offline store with no domain logic.
		 */
		offlineStore() {
			return useOfflineStore()
		},

		/**
		 * Human last-sync label for the stale banner.
		 *
		 * @return {string}
		 */
		syncedLabel() {
			return this.offlineStore.syncedAt
				? new Date(this.offlineStore.syncedAt).toLocaleString()
				: ncT('doriath', 'unknown')
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
		 * Eviction path: the vault can lock while the user is sitting
		 * still on an already-resolved route (session timeout, or the
		 * "Lock vault" menu entry). No navigation occurs in that case,
		 * so `createVaultGuard` never runs and this watcher owns the
		 * redirect. The guard covers the entry path; this covers the
		 * mid-session path.
		 *
		 * @param {boolean} locked New lock state.
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
		 */
		isLocked(locked) {
			// The decision lives in guards.js so it can be unit-tested without
			// mounting the shell — see handleLockTransition.
			handleLockTransition(locked, this.$route, this.$router)
			// Write-through the encrypted offline snapshot on each ONLINE unlock
			// (offline-readonly-cache §2.3). Fail-soft — never blocks the session.
			if (
				!locked
				&& this.offlineStore.online
				&& !this.offlineStore.servedFromCache
			) {
				this.offlineStore.syncNow().catch(() => {})
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
	 * Boot the app shell: initialise stores, warm the offline cache, and
	 * start the session-timeout poll + listeners.
	 *
	 * The boot-time lock redirect that used to live here is gone. It ran
	 * after `await initializeStores()` — i.e. after a settings round-trip
	 * — by which time the routed page had already mounted and fetched, so
	 * the vault inventory painted before the lock screen replaced it. The
	 * gate is now `createVaultGuard` in src/router/guards.js, registered
	 * as a synchronous `beforeEach` so a locked vault never resolves a
	 * protected route in the first place.
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
	 */
	async created() {
		await initializeStores()
		this.storesReady = true

		// Offline cache bootstrap (offline-readonly-cache §2.4/§3.2): track
		// connectivity, arm the lock-time purge hook, and register the app-shell
		// service worker (feature-detected; a failed registration is a no-op
		// online-only fallback, never a hard error).
		this.offlineStore.bindConnectivity()
		this.offlineStore.ensureLockHook()
		this.registerServiceWorker()
		// If we booted already unlocked and online, warm the snapshot.
		if (!this.sessionStore.isLocked && this.offlineStore.online) {
			this.offlineStore.syncNow().catch(() => {})
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
	beforeUnmount() {
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
		 * Persist the chosen session-timeout preference into the store
		 * (mapping the enum to a millisecond duration).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		saveTimeout() {
			const timeouts = { session: 0, '10min': 600000, '30min': 1800000 }
			this.sessionStore.timeout = timeouts[this.sessionTimeout] || 600000
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
				this.revokeError =
					e.response?.data?.message
					|| e.message
					|| ncT('doriath', 'Failed to revoke suite')
			} finally {
				this.revoking = false
			}
		},

		/**
		 * Register the offline app-shell service worker, feature-detected.
		 * A registration failure (unsupported context, scope rejected) is a
		 * no-op online-only fallback — never a hard error.
		 *
		 * @return {void}
		 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-the-app-shell-loads-offline-via-a-service-worker
		 */
		registerServiceWorker() {
			if (
				typeof navigator === 'undefined'
				|| !('serviceWorker' in navigator)
			) {
				return
			}
			// Served by ServiceWorkerController at the app root so the browser
			// gets the correct JS MIME and the worker's default scope is the
			// whole SPA (offline-readonly-cache §3.2).
			const swUrl = generateUrl('/apps/doriath/serviceworker.js')
			navigator.serviceWorker.register(swUrl).catch(() => {
				// Online-only fallback; the offline cache is simply absent.
			})
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

.doriath-offline-banner {
	position: sticky;
	top: 0;
	z-index: 2000;
	padding: 6px 16px;
	text-align: center;
	font-weight: bold;
	/*
	 * --color-warning is the pale tint (#FFEEC5 in light), so the primary
	 * element foreground — white for the default primary — landed at roughly
	 * 1.3:1 on it. Dark mode was fine (#3D3010), making this the same
	 * one-theme failure as the rest of this change. The paired *-text value is
	 * the one the theme flips alongside the tint.
	 *
	 * This banner is sticky at z-index 2000 and is the app's only signal that
	 * the data on screen is stale and read-only, so it is the last thing that
	 * should be invisible.
	 */
	color: var(--color-warning-text);
	background-color: var(--color-warning);
}
</style>
