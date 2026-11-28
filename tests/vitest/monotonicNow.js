/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A clock that never goes back.
 *
 * Vue drops a DOM event whose timestamp is not later than the moment its
 * listener was attached (runtime-dom's invoker check), and Vue Test Utils
 * stamps every `trigger()` with `Date.now() + 1`. When the wall clock steps
 * back between mount and trigger (NTP or VM clock sync, seen up to 1.4 s on
 * WSL2 under load), the triggered event is silently ignored. The setup file
 * wraps `Date.now` with this, so every trigger lands after the mount it follows.
 *
 * @spec exclude test harness support, not product code
 */

/**
 * Wrap a clock so it returns the highest time it has seen so far.
 *
 * @param {() => number} clock The underlying clock, e.g. the original Date.now.
 * @return {() => number} A clock that never returns less than before.
 */
export function monotonic(clock) {
	let latest = -Infinity
	return () => {
		latest = Math.max(latest, clock())
		return latest
	}
}
