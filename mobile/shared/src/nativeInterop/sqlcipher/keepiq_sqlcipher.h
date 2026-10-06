/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cinterop for SQLCipher exists to bundle libsqlcipher.a into the klib;
 * SQLiter binds the SQLite API itself. This header gives it one call.
 */
const char *sqlite3_libversion(void);
