import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import LockScreen from '../views/LockScreen.vue'
import SecretList from '../views/SecretList.vue'
import SecretDetail from '../views/SecretDetail.vue'
import SecretSidebar from '../sidebars/SecretSidebar.vue'

Vue.use(Router)

/**
 * Custom query serializer that keeps the `dir` parameter human-readable.
 * Only &, =, and # are encoded in folder-name segments; slashes stay literal.
 * All other query parameters use standard encodeURIComponent.
 *
 * @param {object} query The query object to serialize
 * @return {string} Serialized query string (including leading '?'), or ''
 */
function stringifyQuery(query) {
	const parts = []
	for (const key of Object.keys(query)) {
		if (query[key] == null) continue
		const val = String(query[key])
		if (key === 'dir') {
			const encoded = val.split('/').map(s =>
				s.replace(/&/g, '%26').replace(/=/g, '%3D').replace(/#/g, '%23'),
			).join('/')
			parts.push(`dir=${encoded}`)
		} else {
			parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(val)}`)
		}
	}
	return parts.length ? '?' + parts.join('&') : ''
}

const router = new Router({
	mode: 'history',
	base: generateUrl('/apps/doriath'),
	stringifyQuery,
	routes: [
		{ path: '/', name: 'Dashboard', components: { default: Dashboard } },
		{ path: '/lock', name: 'Lock', components: { default: LockScreen } },
		{
			path: '/secrets',
			name: 'SecretList',
			components: { default: SecretList, sidebar: SecretSidebar },
		},
		{
			path: '/secrets/:id',
			name: 'SecretDetail',
			components: { default: SecretDetail },
			props: { default: route => ({ secretId: route.params.id }) },
		},
		{
			path: '/folders',
			name: 'FolderView',
			components: { default: SecretList, sidebar: SecretSidebar },
			props: { default: route => ({ dirPath: route.query.dir || '/' }) },
		},
		{ path: '*', redirect: '/' },
	],
})

// Lock screen navigation guard.
// Note: useSessionStore() is imported lazily inside the guard because Pinia
// is not yet active when this module is first loaded (Vue 2 + PiniaVuePlugin
// requires the Vue instance to exist before store access).
let sessionStore = null

router.beforeEach((to, from, next) => {
	// Lazy-load the session store on first navigation.
	if (sessionStore === null) {
		try {
			const { useSessionStore } = require('../store/modules/session.js')
			sessionStore = useSessionStore()
		} catch {
			// Pinia not ready yet — allow navigation (App.vue will handle loading state).
			return next()
		}
	}

	// Public routes skip the lock screen.
	if (to.meta?.public === true || to.name === 'Lock') {
		if (to.name === 'Lock' && !sessionStore.isLocked) {
			return next({ name: 'Dashboard' })
		}
		return next()
	}

	// If locked, redirect to lock screen with return URL.
	if (sessionStore.isLocked) {
		return next({
			name: 'Lock',
			query: { returnUrl: to.fullPath },
		})
	}

	// Update activity on route change.
	sessionStore.updateActivity()
	next()
})

export default router
