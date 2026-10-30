import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { homedir } from 'node:os'
import { join } from 'node:path'

const CHROMIUM = /chrom|brave|vivaldi|edge|opera|thorium|yandex/i
const FIREFOX = /firefox|librewolf|zen|waterfox|floorp/i

/**
 * The system default browser's binary, per WXT target, so `wxt dev` opens what the
 * developer already uses. Linux and Windows; elsewhere WXT's own detection applies.
 */
export function defaultBrowserBinaries(): { chrome?: string; firefox?: string } {
	let binary: string | undefined
	try {
		if (process.platform === 'linux') binary = linuxDefaultBrowser()
		if (process.platform === 'win32') binary = windowsDefaultBrowser()
	} catch {
		return {}
	}
	if (!binary) return {}
	if (CHROMIUM.test(binary)) return { chrome: binary }
	if (FIREFOX.test(binary)) return { firefox: binary }
	return {}
}

function linuxDefaultBrowser(): string | undefined {
	const desktopFile = execFileSync('xdg-settings', ['get', 'default-web-browser'], { encoding: 'utf8' }).trim()
	const dataDirs = [
		process.env.XDG_DATA_HOME ?? join(homedir(), '.local/share'),
		...(process.env.XDG_DATA_DIRS ?? '/usr/local/share:/usr/share').split(':'),
	]
	const path = dataDirs.map((dir) => join(dir, 'applications', desktopFile)).find((p) => existsSync(p))
	if (!path) return undefined
	const command = firstToken(readFileSync(path, 'utf8').match(/^Exec=(.*)$/m)?.[1])
	// A flatpak or snap launcher needs extra arguments web-ext cannot pass.
	if (!command || /(^|\/)(flatpak|snap)$/.test(command)) return undefined
	return command.startsWith('/') ? command : execFileSync('which', [command], { encoding: 'utf8' }).trim()
}

function windowsDefaultBrowser(): string | undefined {
	const progId = regValue(
		'HKCU\\Software\\Microsoft\\Windows\\Shell\\Associations\\UrlAssociations\\http\\UserChoice',
		'ProgId',
	)
	if (!progId) return undefined
	const command = firstToken(regValue(`HKCR\\${progId}\\shell\\open\\command`))
	return command?.replace(/%([^%]+)%/g, (match, name: string) => process.env[name] ?? match)
}

/** One value from `reg query`; without a name, the key's default value. */
function regValue(key: string, name?: string): string | undefined {
	const args = ['query', key, ...(name ? ['/v', name] : ['/ve'])]
	const output = execFileSync('reg', args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] })
	return output.match(/REG_(?:EXPAND_)?SZ\s+(.*)$/m)?.[1]?.trim()
}

/** The executable of a command line, with or without quotes. */
function firstToken(commandLine: string | undefined): string | undefined {
	return commandLine?.trim().match(/^"([^"]+)"|^(\S+)/)?.slice(1).find(Boolean)
}
