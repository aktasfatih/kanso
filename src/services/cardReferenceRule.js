// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

const CARD_REF_RE = /(?<!\w)([A-Z][A-Z0-9]*-\d+)(?!\w)/g

export function cardReferenceRule(state) {
	for (const block of state.tokens) {
		if (block.type !== 'inline' || !block.children) continue
		const nodes = []
		let linkDepth = 0
		for (const token of block.children) {
			if (token.type === 'link_open') linkDepth++
			if (token.type !== 'text' || linkDepth > 0) {
				nodes.push(token)
			} else {
				let offset = 0
				for (const match of token.content.matchAll(CARD_REF_RE)) {
					if (match.index > offset) {
						const text = new state.Token('text', '', 0)
						text.content = token.content.slice(offset, match.index)
						nodes.push(text)
					}
					const reference = new state.Token('kanso_cardref', '', 0)
					reference.content = reference.markup = match[1]
					nodes.push(reference)
					offset = match.index + match[0].length
				}
				if (offset < token.content.length) {
					const text = new state.Token('text', '', 0)
					text.content = token.content.slice(offset)
					nodes.push(text)
				}
			}
			if (token.type === 'link_close') linkDepth--
		}
		block.children = nodes
	}
}

export function collectCardReferences(tokens) {
	return [...new Set(tokens.flatMap((token) => (token.children ?? [])
		.filter((child) => child.type === 'kanso_cardref').map((child) => child.content)))]
}
