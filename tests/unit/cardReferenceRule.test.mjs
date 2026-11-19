// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import MarkdownIt from 'markdown-it'
import { cardReferenceRule, collectCardReferences } from '../../src/services/cardReferenceRule.js'

const md = new MarkdownIt({ linkify: true })
md.core.ruler.push('kanso_cardref', cardReferenceRule)

test('references come only from rendered text and retain token boundaries', () => {
	const tokens = md.parse('See KAN-1, **KAN-2**, (OLD-3), and KAN-1.\n'
		+ '`KAN-4` [KAN-5](https://example.com) ![KAN-6](https://example.com/a.png)\n'
		+ 'https://example.com/KAN-7 KAN-8A foo_KAN-9\n\n```\nKAN-10\n```', {})
	assert.deepEqual(collectCardReferences(tokens), ['KAN-1', 'KAN-2', 'OLD-3'])
})

test('ordinary links are kept intact while surrounding references are tokenized', () => {
	const tokens = md.parse('KAN-1 [KAN-2 **KAN-3**](https://example.com) KAN-4', {})
	const children = tokens.find((token) => token.type === 'inline').children
	assert.deepEqual(collectCardReferences(tokens), ['KAN-1', 'KAN-4'])
	assert.equal(children.filter((token) => token.type === 'link_open').length, 1)
	assert.equal(children.find((token) => token.type === 'link_open').attrGet('href'), 'https://example.com')
	assert.ok(children.some((token) => token.type === 'text' && token.content === 'KAN-2 '))
})
