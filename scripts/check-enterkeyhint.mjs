#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'
import { NodeTypes } from '@vue/compiler-dom'
import { parse } from 'vue/compiler-sfc'

const INPUT_TAGS = new Set(['input', 'nctextfield', 'ncinputfield'])
const HINTS = new Set(['enter', 'done', 'go', 'next', 'previous', 'search', 'send'])
const NON_TEXT_TYPES = new Set([
	'button', 'checkbox', 'color', 'date', 'datetime-local', 'file', 'hidden',
	'image', 'month', 'radio', 'range', 'reset', 'submit', 'time', 'week',
])

function literalValue(prop) {
	if (prop.type === NodeTypes.ATTRIBUTE) { return prop.value?.content }
	const ast = prop.exp?.ast
	if (ast?.type === 'StringLiteral') { return ast.value }
	if (ast?.type === 'TemplateLiteral' && ast.expressions.length === 0) {
		return ast.quasis[0].value.cooked
	}
}

function attributeValue(node, name) {
	let value
	for (const prop of node.props) {
		if (prop.type === NodeTypes.ATTRIBUTE && prop.name.toLowerCase() === name) {
			value = literalValue(prop)
		} else if (prop.type === NodeTypes.DIRECTIVE && prop.name === 'bind') {
			if (!prop.arg?.isStatic) { return undefined }
			if (prop.arg.content.toLowerCase() === name) { value = literalValue(prop) }
		}
	}
	return value
}

function handlesEnter(node) {
	return node.props.some(prop => prop.type === NodeTypes.DIRECTIVE
		&& prop.name === 'on'
		&& prop.arg?.isStatic
		&& ['keydown', 'keyup'].includes(prop.arg.content)
		&& prop.modifiers.some(modifier => modifier.content === 'enter'))
}

function optedOut(previous) {
	return previous?.type === NodeTypes.COMMENT
		&& /^\s*enterkeyhint-ok:\s*\S/.test(previous.content)
}

export function scanSource(file, source) {
	let descriptor, errors
	try {
		({ descriptor, errors } = parse(source, { filename: file }))
	} catch (error) {
		return [{ file, line: error.loc?.start?.line ?? 1, message: error.message }]
	}
	if (errors.length) {
		return errors.map(error => ({
			file,
			line: error.loc?.start?.line ?? 1,
			message: error.message ?? String(error),
		}))
	}
	const template = descriptor.template
	if (!template) { return [] }
	if (template.src || (template.lang && template.lang !== 'html')) {
		return [{ file, line: template.loc.start.line, message: 'Cannot check an external or preprocessed template' }]
	}
	const found = []
	function visit(parent) {
		let previous
		for (const node of parent.children ?? []) {
			if (node.type === NodeTypes.ELEMENT) {
				if (INPUT_TAGS.has(node.tag.replaceAll('-', '').toLowerCase()) && handlesEnter(node)
					&& !NON_TEXT_TYPES.has(attributeValue(node, 'type')?.toLowerCase())
					&& !HINTS.has(attributeValue(node, 'enterkeyhint')?.toLowerCase())
					&& !optedOut(previous)) {
					found.push({ file, line: node.loc.start.line, message: `<${node.tag}> handles Enter without a static enterkeyhint` })
				}
				visit(node)
			}
			if (node.type !== NodeTypes.TEXT || node.content.trim()) { previous = node }
		}
	}
	visit(template.ast)
	return found
}

function vueFiles(dir) {
	const found = []
	for (const entry of readdirSync(dir, { withFileTypes: true })) {
		const file = join(dir, entry.name)
		if (entry.isDirectory()) { found.push(...vueFiles(file)) }
		else if (entry.isFile() && file.endsWith('.vue')) { found.push(file) }
	}
	return found.sort()
}

function main() {
	try {
		const found = vueFiles('src').flatMap(file => scanSource(file, readFileSync(file, 'utf8')))
		if (found.length === 0) {
			process.stdout.write('Enter-key hint guard: OK\n')
			return
		}
		for (const item of found) { process.stderr.write(`${item.file}:${item.line} ${item.message}\n`) }
		process.stderr.write('Add enterkeyhint or an immediately preceding <!-- enterkeyhint-ok: reason -->.\n')
	} catch (error) {
		process.stderr.write(`Enter-key hint guard: ${error.message}\n`)
	}
	process.exitCode = 1
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) { main() }
