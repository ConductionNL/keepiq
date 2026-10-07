// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.send

/**
 * Reads the server's ISO 8601 times (PHP `format('c')`:
 * `2026-10-04T12:00:00+00:00`, also with `Z` or fractions) as epoch
 * milliseconds. Common code has no date library; null when unreadable.
 */
object IsoTime {
    private val pattern = Regex("""^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?(Z|[+-]\d{2}:?\d{2})?$""")

    fun parseMillis(text: String?): Long? {
        val m = pattern.matchEntire(text?.trim() ?: return null) ?: return null
        val g = m.groupValues
        val year = g[1].toInt()
        val month = g[2].toInt()
        val day = g[3].toInt()
        if (month !in 1..12 || day !in 1..31) return null
        val seconds = g[6].ifEmpty { "0" }.toInt()
        val millis = g[7].padEnd(3, '0').take(3).ifEmpty { "0" }.toInt()
        val zone = g[8]
        val offsetMinutes = when {
            zone.isEmpty() || zone == "Z" -> 0
            else -> {
                val digits = zone.substring(1).replace(":", "")
                val minutes = digits.substring(0, 2).toInt() * 60 + digits.substring(2, 4).toInt()
                if (zone[0] == '-') -minutes else minutes
            }
        }
        val days = daysFromCivil(year, month, day)
        val local = ((days * 24 + g[4].toInt()) * 60 + g[5].toInt()) * 60 + seconds
        return (local - offsetMinutes * 60L) * 1000 + millis
    }

    /** Epoch milliseconds as JavaScript's toISOString writes them: `2026-10-04T12:00:00.000Z`. */
    fun format(millis: Long): String {
        val days = millis.floorDiv(86_400_000L)
        val rest = millis - days * 86_400_000L
        val (year, month, day) = civilFromDays(days)
        fun two(n: Long) = n.toString().padStart(2, '0')
        return "${year.toString().padStart(4, '0')}-${two(month)}-${two(day)}T${two(rest / 3_600_000)}:${two(rest / 60_000 % 60)}:" +
            "${two(rest / 1000 % 60)}.${(rest % 1000).toString().padStart(3, '0')}Z"
    }

    /** The proleptic Gregorian date of a day count since 1970-01-01 (H. Hinnant's algorithm). */
    private fun civilFromDays(days: Long): Triple<Long, Long, Long> {
        val z = days + 719468
        val era = (if (z >= 0) z else z - 146096) / 146097
        val doe = z - era * 146097
        val yoe = (doe - doe / 1460 + doe / 36524 - doe / 146096) / 365
        val doy = doe - (365 * yoe + yoe / 4 - yoe / 100)
        val mp = (5 * doy + 2) / 153
        val day = doy - (153 * mp + 2) / 5 + 1
        val month = if (mp < 10) mp + 3 else mp - 9
        return Triple(yoe + era * 400 + (if (month <= 2) 1 else 0), month, day)
    }

    /** Days since 1970-01-01 for a proleptic Gregorian date (H. Hinnant's algorithm). */
    private fun daysFromCivil(year: Int, month: Int, day: Int): Long {
        val y = (if (month <= 2) year - 1 else year).toLong()
        val era = (if (y >= 0) y else y - 399) / 400
        val yoe = y - era * 400
        val mp = (month + 9) % 12
        val doy = (153 * mp + 2) / 5 + day - 1
        val doe = yoe * 365 + yoe / 4 - yoe / 100 + doy
        return era * 146097 + doe - 719468
    }
}
