// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for doriath (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import Autorenew from 'vue-material-design-icons/Autorenew.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CertificateOutline from 'vue-material-design-icons/CertificateOutline.vue'
import ClipboardList from 'vue-material-design-icons/ClipboardList.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import Pulse from 'vue-material-design-icons/Pulse.vue'
import ShieldKeyOutline from 'vue-material-design-icons/ShieldKeyOutline.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'

export default {
	AccountGroup,
	ApplicationOutline,
	Autorenew,
	BookOpenVariantOutline,
	CertificateOutline,
	ClipboardList,
	CogOutline,
	FileDocumentOutline,
	FolderOutline,
	LockOutline,
	MapMarkerPath,
	Pulse,
	ShieldKeyOutline,
	ViewDashboardOutline,
}
