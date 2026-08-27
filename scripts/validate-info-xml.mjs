#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Validate appinfo/info.xml against the Nextcloud App Store schema.
 *
 * The App Store validates info.xml on upload, so a schema-invalid file is only
 * discovered at publish time — the worst possible moment. This runs the same
 * check in CI (the `build-frontend` job) on every push.
 *
 * The schema is vendored at scripts/schema/info.xsd rather than fetched, so the
 * check is deterministic and offline. It is a verbatim copy of the file
 * info.xml itself points at via xsi:noNamespaceSchemaLocation:
 *
 *     https://apps.nextcloud.com/schema/apps/info.xsd
 *
 * Refresh it with `--refresh` when targeting a newer Nextcloud; the upstream
 * schema is stable and rarely changes.
 *
 * Validation runs through xmllint-wasm — libxml2 compiled to WebAssembly, so it
 * needs no system xmllint (not installed on the runners) and no native build.
 *
 * Usage:
 *   node scripts/validate-info-xml.mjs            # validate
 *   node scripts/validate-info-xml.mjs --refresh  # re-download the vendored XSD
 */

import { readFile, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { dirname, join, relative } from 'node:path'
import { validateXML } from 'xmllint-wasm'

const SCHEMA_URL = 'https://apps.nextcloud.com/schema/apps/info.xsd'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const xmlPath = join(root, 'appinfo', 'info.xml')
const xsdPath = join(root, 'scripts', 'schema', 'info.xsd')

const rel = (p) => relative(root, p)

if (process.argv.includes('--refresh')) {
	const res = await fetch(SCHEMA_URL)
	if (!res.ok) {
		console.error(`Failed to fetch ${SCHEMA_URL}: HTTP ${res.status}`)
		process.exit(1)
	}
	await writeFile(xsdPath, await res.text())
	console.log(`Refreshed ${rel(xsdPath)} from ${SCHEMA_URL}`)
	process.exit(0)
}

const [xml, schema] = await Promise.all([
	readFile(xmlPath, 'utf8'),
	readFile(xsdPath, 'utf8'),
])

const result = await validateXML({
	xml: [{ fileName: 'info.xml', contents: xml }],
	schema: [schema],
})

if (result.valid) {
	console.log(`${rel(xmlPath)} validates against the App Store schema.`)
	process.exit(0)
}

// GitHub Actions renders `::error::` lines as annotations on the job.
for (const err of result.errors) {
	const message = (err.rawMessage ?? err.message ?? String(err)).trim()
	console.error(`::error file=${rel(xmlPath)},line=${err.line ?? 1}::${message}`)
}
console.error(
	`\n${rel(xmlPath)} is INVALID against ${rel(xsdPath)} (${SCHEMA_URL}).\n` +
	'The App Store rejects schema-invalid info.xml at upload. Note the schema is\n' +
	'an xs:sequence — child elements must appear in the exact order it declares.',
)
process.exit(1)
