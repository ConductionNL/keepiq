/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Throwaway vault users for the multi-user workflow specs (device approval,
 * account recovery, team-folder manager, expiring shares).
 *
 * WHY THROWAWAY USERS
 * -------------------
 * Those flows need people who own a vault the BROWSER created: admin's seeded
 * suite is written by PHP and a second person has to receive shares, so the
 * fixed `admin`/`alice` fixtures are not enough. Every run creates fresh
 * accounts with a run-unique name, so a run never inherits a half-finished
 * vault, a pending approval or a used recovery request from an earlier one.
 * The accounts are deleted again in `deleteUsers()`.
 *
 * Accounts are created through the OCS provisioning API as the admin, with
 * the credentials `global-setup.ts` signs in with (see `adminApi()`).
 */
import type {
	APIRequestContext,
	Browser,
	BrowserContext,
	Page,
} from '@playwright/test'

import { expect, request as playwrightRequest } from '@playwright/test'
import { randomInt } from 'node:crypto'
import * as path from 'path'
import { APP_BASE } from './_workflow-helpers.ts'

/** The admin session `global-setup.ts` stored. */
export const ADMIN_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

/**
 * An API context that authenticates the admin with basic auth.
 *
 * Not the stored session: creating an account, and Keepiq's recovery
 * settings, ask for a recent password confirmation, which a session loses
 * after 30 minutes and basic auth carries on every request. The credentials
 * are the ones `global-setup.ts` signs in with.
 *
 * @param baseURL The instance.
 * @return The context; dispose it when done.
 */
export async function adminApi(
	baseURL: string | undefined,
): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL,
		// No stored session: a session cookie would win over basic auth and
		// bring back the expired confirmation.
		storageState: { cookies: [], origins: [] },
		httpCredentials: {
			username: process.env.NC_ADMIN_USER ?? 'admin',
			password: process.env.NC_ADMIN_PASS ?? 'admin',
			// Without this the header waits for a 401 challenge, and Nextcloud
			// answers an anonymous OCS call with 403 instead.
			send: 'always',
		},
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
}

/** A login password long and mixed enough for any password policy. */
const LOGIN_PASSWORD_SUFFIX = 'Login-pass-2026!'

/** A master password that passes the 12-character setup floor. */
export const MASTER_PASSWORD_SUFFIX = 'master-pass-2026!'

/** One throwaway account. */
export interface VaultUser {
	uid: string
	password: string
	masterPassword: string
}

/**
 * A run-unique account name: `<prefix>-<base36 time><random>`.
 *
 * @param prefix A short readable prefix, such as `olga`.
 * @return The account details (not created yet).
 */
export function newVaultUser(prefix: string): VaultUser {
	const tag = `${Date.now().toString(36)}${randomInt(1296).toString(36)}`
	const uid = `${prefix}-${tag}`
	return {
		uid,
		password: `${uid}-${LOGIN_PASSWORD_SUFFIX}`,
		masterPassword: `${prefix}-${MASTER_PASSWORD_SUFFIX}`,
	}
}

/**
 * Create the accounts through the OCS provisioning API, as admin.
 *
 * @param request An API context carrying the admin session.
 * @param users The accounts to create.
 */
export async function createUsers(
	request: APIRequestContext,
	users: VaultUser[],
): Promise<void> {
	for (const user of users) {
		const response = await request.post('/ocs/v2.php/cloud/users?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
			form: {
				userid: user.uid,
				password: user.password,
				displayName: user.uid,
			},
		})
		expect(
			response.status(),
			`create ${user.uid}: ${await response.text()}`,
		).toBe(200)
	}
}

/**
 * Delete the accounts again; a failure here never fails the test.
 *
 * @param request An API context carrying the admin session.
 * @param users The accounts to delete.
 */
export async function deleteUsers(
	request: APIRequestContext,
	users: VaultUser[],
): Promise<void> {
	for (const user of users) {
		await request
			.delete(`/ocs/v2.php/cloud/users/${encodeURIComponent(user.uid)}`, {
				headers: { 'OCS-APIRequest': 'true' },
			})
			.catch(() => undefined)
	}
}

/**
 * A fresh browser context, signed in as the account.
 *
 * @param browser The Playwright browser.
 * @param user The account.
 * @return The context and its first page.
 */
export async function signedInContext(
	browser: Browser,
	user: VaultUser,
): Promise<{ context: BrowserContext; page: Page }> {
	const context = await browser.newContext({
		storageState: { cookies: [], origins: [] },
		viewport: { width: 1600, height: 1000 },
	})
	const page = await context.newPage()
	await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
	const userField = page.locator('input[name="user"]')
	await userField.waitFor({ state: 'visible', timeout: 40_000 })
	await userField.fill(user.uid)
	await page.locator('input[name="password"]').fill(user.password)
	await page.locator('form[name="login"] button[type="submit"]').click()
	await expect(page).not.toHaveURL(/\/login(\?|$|\/)/, { timeout: 40_000 })
	return { context, page }
}

/**
 * Click the first button whose text matches, with a native click.
 *
 * The themed NcButton can swallow Playwright's synthetic click (see
 * `unlockVault()` in _workflow-helpers.ts), so this dispatches
 * `HTMLButtonElement.click()` in the page.
 *
 * @param page The page.
 * @param label A case-insensitive regular expression source.
 */
export async function clickButton(page: Page, label: string): Promise<void> {
	const clicked = await page.evaluate((pattern) => {
		const re = new RegExp(pattern, 'i')
		const button = Array.from(document.querySelectorAll('button')).find(
			(b) =>
				re.test((b.textContent || '').trim())
				&& !(b as HTMLButtonElement).disabled,
		)
		if (button) {
			;(button as HTMLButtonElement).click()
			return true
		}
		return false
	}, label)
	expect(clicked, `a button labelled /${label}/`).toBe(true)
}

/**
 * Set up the account's vault through the lock screen, in the browser.
 *
 * @param page A page signed in as the account, which owns no vault yet.
 * @param user The account.
 */
export async function setUpVault(page: Page, user: VaultUser): Promise<void> {
	await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
	const fields = page.locator('.lock-screen input[type="password"]')
	await expect(fields).toHaveCount(2, { timeout: 30_000 })
	await fields.nth(0).fill(user.masterPassword, { force: true })
	await fields.nth(1).fill(user.masterPassword, { force: true })
	await page.waitForTimeout(400)
	await clickButton(page, '^Set up vault$')
	await expect(page.locator('.lock-screen')).toHaveCount(0, { timeout: 60_000 })
}

/**
 * Unlock the account's existing vault through the lock screen.
 *
 * @param page A page signed in as the account.
 * @param user The account.
 */
export async function unlockAs(page: Page, user: VaultUser): Promise<void> {
	await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
	const field = page.locator('.lock-screen input[type="password"]').first()
	await field.waitFor({ state: 'visible', timeout: 30_000 })
	await field.fill(user.masterPassword, { force: true })
	await page.waitForTimeout(300)
	await page
		.getByTestId('unlock-with-password')
		.evaluate((el: HTMLElement) => el.click())
	await expect(page.locator('.lock-screen')).toHaveCount(0, { timeout: 60_000 })
}

/**
 * The Nextcloud request token of the page, for API calls from the page.
 *
 * @param page The page.
 * @return The token.
 */
export async function requestToken(page: Page): Promise<string> {
	return page.evaluate(() => {
		const head = document.querySelector('head[data-requesttoken]')
		return (
			head?.getAttribute('data-requesttoken')
			|| (window as unknown as { OC?: { requestToken?: string } }).OC
				?.requestToken
			|| ''
		)
	})
}

/**
 * Call the Keepiq API from the page, with its session and request token.
 *
 * @param page The page.
 * @param method The HTTP method.
 * @param route The route below `/apps/keepiq/api/v1`, such as `/secrets`.
 * @param data An optional JSON body.
 * @return The status and the parsed body.
 */
export async function api(
	page: Page,
	method: string,
	route: string,
	data?: unknown,
): Promise<{ status: number; body: any }> {
	const token = await requestToken(page)
	const response = await page.request.fetch(`${APP_BASE}/api/v1${route}`, {
		method,
		headers: { requesttoken: token, Accept: 'application/json' },
		data,
	})
	const text = await response.text()
	let body: unknown = text
	try {
		body = JSON.parse(text)
	} catch {
		// Not JSON: keep the text.
	}
	return { status: response.status(), body }
}
