/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Import parser registration (secret-import D1, tasks §2).
 *
 * Adopts the parser registry shipped by secret-export-gdpr (src/import/
 * parserRegistry.js) — the `secret-import` change is the registry's intended
 * consumer (see parserRegistry.js header). Importing this module registers
 * every format parser so the wizard discovers them via listParsers(); the
 * backup-restore parser registers itself on import of backupParser.js.
 */

import { registerParser } from '../parserRegistry.js'
import { csvParser } from './csv.js'
import { bitwardenParser } from './bitwarden.js'
import { keepassXmlParser } from './keepassXml.js'
import { ncPasswordsParser } from './ncPasswords.js'
import { cxfParser } from './cxf.js'
// Importing the backup parser registers the `.doriath-backup` restore path.
import { backupParser } from '../backupParser.js'

registerParser(csvParser)
registerParser(bitwardenParser)
registerParser(keepassXmlParser)
registerParser(ncPasswordsParser)
registerParser(cxfParser)

export {
	csvParser,
	bitwardenParser,
	keepassXmlParser,
	ncPasswordsParser,
	cxfParser,
	backupParser,
}
