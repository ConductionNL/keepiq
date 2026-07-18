/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * V2 component registry for doriath (hydra ADR-036).
 *
 * Recognised kinds: page, widget, modal, form-field, cell-renderer.
 *
 * Page resolution path at runtime:
 *   - `pages[].type === 'custom'` → CnPageRenderer resolves
 *     `page.component` against `customComponents` (derived from the
 *     `kind:"page"` entries of this registry in src/main.js).
 *   - Dashboard `widgets[]` entries whose `widgetKey` is not built-in
 *     are looked up against this registry via `CnWidgetGrid` (the
 *     `kind:"widget"` entries below).
 *
 * ZERO custom `kind:"widget"` entries (hydra ADR-049, Decision 5) —
 * the dashboard's banners, KPI tiles, and placeholder panels are
 * built-in manifest widgets (`banner`, `stat`, `text`) configured in
 * src/manifest.json against the summary / migration-status endpoints.
 *
 * Notable entries:
 *   - LockScreen (page) — master-password setup + unlock flow.
 *     `type:"custom"` because the lock owns its own full-page layout
 *     (no app nav / header / sidebar) and the existing lock-redirect
 *     watcher in App.vue must short-circuit normal navigation. No lib
 *     primitive matches yet.
 *
 * @type {Record<string, { kind: string, component: object, defaultSize?: object, minSize?: object, maxSize?: object, allowedSlots?: string[], propsSchema?: object }>}
 */

import LockScreen from './views/LockScreen.vue'
import SecretList from './views/SecretList.vue'
import SecretDetail from './views/SecretDetail.vue'
import SecretRequestFill from './views/SecretRequestFill.vue'
import LinkShareAccess from './views/LinkShareAccess.vue'
import EphemeralSendAccess from './views/EphemeralSendAccess.vue'
import ApplicationRegisterView from './views/ApplicationRegisterView.vue'
import ApplicationDetail from './views/ApplicationDetail.vue'
import PersonalActivityView from './views/PersonalActivityView.vue'
import HealthReportView from './views/HealthReportView.vue'
import EmergencyAccessView from './views/EmergencyAccessView.vue'
import SecretCreateDialog from './dialogs/SecretCreateDialog.vue'
import SecretEditDialog from './dialogs/SecretEditDialog.vue'
import FolderCreateDialog from './dialogs/FolderCreateDialog.vue'
import SecretMoveDialog from './dialogs/SecretMoveDialog.vue'
import SecretShareDialog from './dialogs/SecretShareDialog.vue'
import ApplicationRegisterDialog from './components/application/ApplicationRegisterDialog.vue'
import PrivateKeyDownloadDialog from './components/application/PrivateKeyDownloadDialog.vue'
import ShareDialog from './components/share/ShareDialog.vue'
import ShareList from './components/share/ShareList.vue'
import GroupShareForm from './components/share/GroupShareForm.vue'
import SecretRequestForm from './components/secretRequest/SecretRequestForm.vue'
import SecretRequestList from './components/secretRequest/SecretRequestList.vue'

export default {
	LockScreen: { kind: 'page', component: LockScreen },
	SecretList: { kind: 'page', component: SecretList },
	SecretDetail: { kind: 'page', component: SecretDetail },
	SecretRequestFill: { kind: 'page', component: SecretRequestFill },
	LinkShareAccess: { kind: 'page', component: LinkShareAccess },
	EphemeralSendAccess: { kind: 'page', component: EphemeralSendAccess },
	ApplicationRegisterView: { kind: 'page', component: ApplicationRegisterView },
	ApplicationDetail: { kind: 'page', component: ApplicationDetail },
	PersonalActivityView: { kind: 'page', component: PersonalActivityView },
	HealthReportView: { kind: 'page', component: HealthReportView },
	EmergencyAccessView: { kind: 'page', component: EmergencyAccessView },
	'secret-create': { kind: 'modal', component: SecretCreateDialog, propsSchema: {} },
	'secret-edit': { kind: 'modal', component: SecretEditDialog, propsSchema: {} },
	'folder-create': { kind: 'modal', component: FolderCreateDialog, propsSchema: {} },
	'secret-move': { kind: 'modal', component: SecretMoveDialog, propsSchema: {} },
	'secret-share': { kind: 'modal', component: SecretShareDialog, propsSchema: {} },
	'application-register': { kind: 'modal', component: ApplicationRegisterDialog, propsSchema: {} },
	'private-key-download': { kind: 'modal', component: PrivateKeyDownloadDialog, propsSchema: {} },
	'share-dialog': { kind: 'modal', component: ShareDialog, propsSchema: {} },
	'share-list': { kind: 'form-field', component: ShareList, propsSchema: {} },
	'group-share-form': { kind: 'modal', component: GroupShareForm, propsSchema: {} },
	'secret-request-form': { kind: 'modal', component: SecretRequestForm, propsSchema: {} },
	'secret-request-list': { kind: 'form-field', component: SecretRequestList, propsSchema: {} },
}
