<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to Kanso are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
This file is generated from Conventional Commits by semantic-release — do not edit by hand.

## [0.22.1](https://github.com/aktasfatih/kanso/compare/v0.22.0...v0.22.1) (2026-09-02)


### Bug Fixes

* ask before leaving a card with unsaved description, title or comment text ([633c991](https://github.com/aktasfatih/kanso/commit/633c991b1279aa60091234d814f4ea7b57f93601))
* keep columns and checklist items in a stable order between reloads ([6729445](https://github.com/aktasfatih/kanso/commit/6729445cc8474083526eb94e2626caa167ab5895)), closes [#6148](https://github.com/aktasfatih/kanso/issues/6148)
* let touch users open the command palette from the More menu ([337409c](https://github.com/aktasfatih/kanso/commit/337409c3ac7305fe86c76feeda34fd988196a8cc)), closes [#10066](https://github.com/aktasfatih/kanso/issues/10066)
* stop archived boards from showing up in views, search and other lists ([d442d4f](https://github.com/aktasfatih/kanso/commit/d442d4f9c0194e38e3fe2d87a1eba176fa3c7ff1))
* stop Clear filters on a View from wiping the view's own saved filter ([05e1e02](https://github.com/aktasfatih/kanso/commit/05e1e0294385870fb0ea4a8083b48e3bc0913bc2)), closes [#10091](https://github.com/aktasfatih/kanso/issues/10091)
* stop the recurrence editor from wiping a custom repeat schedule on save ([f800a49](https://github.com/aktasfatih/kanso/commit/f800a4923d0897f41acf2d7ebeadd2e1b8888eab))


### Performance Improvements

* **views:** abort a superseded View feed refetch in the browser ([7c65a20](https://github.com/aktasfatih/kanso/commit/7c65a20f58ea4e9f00efd422b8e514358bd3482e)), closes [#10010](https://github.com/aktasfatih/kanso/issues/10010)

# [0.22.0](https://github.com/aktasfatih/kanso/compare/v0.21.0...v0.22.0) (2026-09-01)


### Bug Fixes

* **card:** show whether a card is archived with a checkbox in its menu ([05a3382](https://github.com/aktasfatih/kanso/commit/05a3382a384cf7224db4ff671cc337edac36bf84))
* **cards:** keep a card with unapproved reviews from being marked done by any route ([5685eac](https://github.com/aktasfatih/kanso/commit/5685eac07fccb6000cf4b3a8bfcede2625faa435)), closes [#10070](https://github.com/aktasfatih/kanso/issues/10070)
* **export:** include card attachments in board exports and scheduled backups ([415e51d](https://github.com/aktasfatih/kanso/commit/415e51d206a37de940b307db9914b6276503dcd5))
* **i18n:** show "5 projects" instead of "5 project", and count correctly in every language ([31aec89](https://github.com/aktasfatih/kanso/commit/31aec89321d373c957ba287960d6c387c6a8d53c))
* **import:** restore an exported board with its attachments ([78a040b](https://github.com/aktasfatih/kanso/commit/78a040b3bec80f896a68cf54944a6e3c4458e100)), closes [#10071](https://github.com/aktasfatih/kanso/issues/10071)
* **my-tasks:** make rows keyboard-usable and stop hiding the 200-task cap ([6354e3c](https://github.com/aktasfatih/kanso/commit/6354e3c95e484b2cb3d0873d4079b1fbc7d5e947))
* **views:** hide archived cards in views, with a filter to show them again ([f68f9bc](https://github.com/aktasfatih/kanso/commit/f68f9bc3dea49615f0489ba5f3cf04f4e9964a4a))


### Features

* **card:** find where an open card sits on the board, from its ⋯ menu ([f5a4829](https://github.com/aktasfatih/kanso/commit/f5a48299b9e8dc1bd56db69d612ee5230e45cae6))
* **card:** show the card's column in the breadcrumb, and change it from the status chip ([1cf28c0](https://github.com/aktasfatih/kanso/commit/1cf28c0d0026d12e6a6564386a38ff445b3f7321)), closes [#10064](https://github.com/aktasfatih/kanso/issues/10064)
* **mcp:** find cards by text from an AI assistant, across every board ([876e004](https://github.com/aktasfatih/kanso/commit/876e004ad75deae3c5ea9d078c1240ebbb3ea13f))
* **my-tasks:** show your recently completed tasks, on request ([37af7de](https://github.com/aktasfatih/kanso/commit/37af7de9ae97478908426cd9e3aedb601ec30fbf))

# [0.21.0](https://github.com/aktasfatih/kanso/compare/v0.20.0...v0.21.0) (2026-09-01)


### Bug Fixes

* **l10n:** correct three mislabelled German controls ([e8114b2](https://github.com/aktasfatih/kanso/commit/e8114b235b6ad881cbf63ef07a3eafb56343ce35))
* **l10n:** show the German interface in German again for 121 untranslated strings ([4ade32a](https://github.com/aktasfatih/kanso/commit/4ade32af7f1dea06fac9c441bf52b7f7144dc14e))
* **l10n:** use one consistent German word for each concept ([6f5340a](https://github.com/aktasfatih/kanso/commit/6f5340a92ae10d40f79e581e3e83e385f4ea0b9b))


### Features

* **l10n:** add a complete Brazilian Portuguese translation of the Kanso interface ([5235a49](https://github.com/aktasfatih/kanso/commit/5235a498da1cd9229dedebd863a1911f86f2826e))
* **l10n:** add a complete Dutch translation of the Kanso interface ([ea90ef7](https://github.com/aktasfatih/kanso/commit/ea90ef7a5a37cd0279e3268dd2eab2d13ef52f37))
* **l10n:** add a complete French translation of the Kanso interface ([cce0c1b](https://github.com/aktasfatih/kanso/commit/cce0c1b1bc190eff97bbab401fc65c8db9229fbb))
* **l10n:** add a complete Italian translation of the Kanso interface ([06ca4ac](https://github.com/aktasfatih/kanso/commit/06ca4acf35ac82ec21e43d05f5aaf2207227dd00))
* **l10n:** add a complete Polish translation of the Kanso interface ([af09dec](https://github.com/aktasfatih/kanso/commit/af09decf36f64fd6c6241357d03d7eccdc394ffa))
* **l10n:** add a complete Russian translation of the Kanso interface ([ca35b3c](https://github.com/aktasfatih/kanso/commit/ca35b3cb01fd924cc00d8fd6c8df1a4605c8f9c7))
* **l10n:** add a complete Simplified Chinese translation of the Kanso interface ([dc5df5d](https://github.com/aktasfatih/kanso/commit/dc5df5dd23c0105a36da2ce67d5e57f1b0ecfaea))
* **l10n:** add a complete Spanish translation of the Kanso interface ([012a9f4](https://github.com/aktasfatih/kanso/commit/012a9f47a76557e344ffcec49171ef4afd95f6c2))
* **l10n:** add a complete Turkish translation of the Kanso interface ([501a36a](https://github.com/aktasfatih/kanso/commit/501a36a50a51fb32cb8b45b2e7d3b2bb668e6410))

# [0.20.0](https://github.com/aktasfatih/kanso/compare/v0.19.1...v0.20.0) (2026-08-31)


### Bug Fixes

* a description edit can no longer be lost to a simultaneous save ([7015462](https://github.com/aktasfatih/kanso/commit/7015462b195bc1f80f9d92027bb13c421cc969fb))
* a rejected GitHub webhook delivery no longer blames your content type ([cec9c15](https://github.com/aktasfatih/kanso/commit/cec9c15bd13318d76eb6b8306e5551285b334989))
* an unreadable repeat schedule in an imported board is skipped, not stored ([24da3f3](https://github.com/aktasfatih/kanso/commit/24da3f32619eeeff212771f9d32ed82c1c598985))
* importing a board no longer fails on a repeat rule with no frequency ([5224209](https://github.com/aktasfatih/kanso/commit/5224209fb3bc1b9844fbe002bd2f7987a507be77))
* make the installable PWA load reliably ([6be4363](https://github.com/aktasfatih/kanso/commit/6be43638b27fd79db7aa0ab6bac11c85f5848ce8))
* pointing a repeat at another card now reschedules it from that card ([926fccb](https://github.com/aktasfatih/kanso/commit/926fccbacab889b20b78c9d79463206fbfb10d27))
* **recurrence:** a repeat set to stop after N times now actually stops ([634448d](https://github.com/aktasfatih/kanso/commit/634448d33ffe47307256135334817565bdbbed8b)), closes [#10002](https://github.com/aktasfatih/kanso/issues/10002)
* **recurrence:** explain what a repeating card's schedule anchors to ([644f11c](https://github.com/aktasfatih/kanso/commit/644f11ca0ed24b92d15ae1a770ee8afa7e5af4c5))
* render the app icon so the PWA can be installed ([2b58d0e](https://github.com/aktasfatih/kanso/commit/2b58d0eb6d28889b2badf5cb2a6a9d2aca177055))
* setting a repeat to end after N times now gives you N more cards ([c497667](https://github.com/aktasfatih/kanso/commit/c49766713e3b4bf05fd6480750521341562c83e9))
* tell you the GitHub webhook needs the JSON content type ([76e11ed](https://github.com/aktasfatih/kanso/commit/76e11edec9acf2cf699d505a1ec1757bb708ad1f))


### Features

* installable, offline-capable mobile PWA ([4410617](https://github.com/aktasfatih/kanso/commit/4410617962630e91003a1ecf54b146dbc0b35872))

## [0.19.1](https://github.com/aktasfatih/kanso/compare/v0.19.0...v0.19.1) (2026-08-30)


### Bug Fixes

* **board:** a failed card move keeps its explanation until you act again ([5f1e776](https://github.com/aktasfatih/kanso/commit/5f1e776a104dd78acd608bf73b277f5b05b38987))
* **board:** the done and priority keyboard shortcuts no longer fire for read-only members ([e73af17](https://github.com/aktasfatih/kanso/commit/e73af1760135e6b3a03530118b141f81b7120920))
* **board:** the error banner clears itself once the next action succeeds ([41a9637](https://github.com/aktasfatih/kanso/commit/41a963705985db9ea8afab0c4f4742476d52891f))
* hide column actions, Delete column and card drag from read-only members ([ff518ef](https://github.com/aktasfatih/kanso/commit/ff518efde915f1c87d1737cea042234e700bc7f3)), closes [#9897](https://github.com/aktasfatih/kanso/issues/9897)
* import a CSV into a long-lived column instead of failing with a rebalance error ([73005a1](https://github.com/aktasfatih/kanso/commit/73005a1712c334302f2a54a084902f4087f08aee)), closes [#6180](https://github.com/aktasfatih/kanso/issues/6180)
* keep numeric usernames in a View's assignee and owner filters ([da47cbc](https://github.com/aktasfatih/kanso/commit/da47cbc39e55557e4c2f7648b065bc1de73a0840))
* refresh Views and My Work when a checklist item or the priority shortcut changes a card ([c3df899](https://github.com/aktasfatih/kanso/commit/c3df8994dd78f5ac7c128e31a6118dd508d997cd))
* search every readable card when filtering a View, not just the first 5000 ([c50be5f](https://github.com/aktasfatih/kanso/commit/c50be5f72c9e3bbf347d808e006e27acc5470ec3))
* **views:** rapid edits in a card opened from a View refresh the feed once, not once per edit ([4d863df](https://github.com/aktasfatih/kanso/commit/4d863dfb754b8503d7c5137073b7ac29405066bf)), closes [#9981](https://github.com/aktasfatih/kanso/issues/9981)

# [0.19.0](https://github.com/aktasfatih/kanso/compare/v0.18.0...v0.19.0) (2026-08-28)


### Bug Fixes

* **deps:** bump the npm-production group across 1 directory with 7 updates ([e4df2ce](https://github.com/aktasfatih/kanso/commit/e4df2ce84039ae13c97919630a534954c8199ec1))


### Features

* create and manage repeating cards from the MCP server ([3d45141](https://github.com/aktasfatih/kanso/commit/3d451412fbdc5a034d03fbd86095ad10c27995b2))
* pin a repeating card's schedule to a chosen timezone ([c626083](https://github.com/aktasfatih/kanso/commit/c626083fe1e5d67c05912161774de2d5ddfe1c56))

# [0.18.0](https://github.com/aktasfatih/kanso/compare/v0.17.0...v0.18.0) (2026-08-28)


### Bug Fixes

* **board:** hide the add-card composer from members with read-only access ([d9744ec](https://github.com/aktasfatih/kanso/commit/d9744eccc49f8d6eb289e3af37f1008e51f3b554))
* **board:** stop offering "Add column" in views that have nowhere to put one ([44955a1](https://github.com/aktasfatih/kanso/commit/44955a10851694c1fca613e5f26c6b71202896b6))
* **mcp:** hide archived cards from the MCP board tool unless you ask for them ([0e30189](https://github.com/aktasfatih/kanso/commit/0e30189ad3032b6c0d6125e3ad77d82cfb9c4447))
* **timeline:** show the track, gridlines and bars for every row, not just the first screenful ([e8033f4](https://github.com/aktasfatih/kanso/commit/e8033f4f182a63889bb1f168eed46b0ada496fc0))
* **views:** show card edits in a view straight away instead of after a minute ([d475205](https://github.com/aktasfatih/kanso/commit/d475205f333bc6912ad452abe6797941601702db))


### Features

* **board:** drag a sub-card out of its parent to make it top-level again ([35e9153](https://github.com/aktasfatih/kanso/commit/35e9153e33cd6ec5d882415da646ae687387ab55))
* **card:** collapse the discussion panel to give a card the full width ([b6f395c](https://github.com/aktasfatih/kanso/commit/b6f395c6fdfd5096b2c665bece1462f7f46b63e3))
* **github:** link a pull request to a card by its card reference in the PR title ([3c00dce](https://github.com/aktasfatih/kanso/commit/3c00dceb80232bdb867ddb325dae6aae25a0edea))
* **list-view:** add a column without switching back to the board ([9fc0287](https://github.com/aktasfatih/kanso/commit/9fc02876dae00af4493f2875364ad9d7ad6641d4))
* **views:** sort a saved view by due date, priority, title, board or date ([5391145](https://github.com/aktasfatih/kanso/commit/539114561c79a99127ccb44069241786f11b2181))

# [0.17.0](https://github.com/aktasfatih/kanso/compare/v0.16.0...v0.17.0) (2026-08-27)


### Bug Fixes

* **appinfo:** make info.xml valid against the App Store schema ([d49a644](https://github.com/aktasfatih/kanso/commit/d49a644259c6e4e7a5d9374613cbe2014f94831e))
* **card:** editing a description no longer silently overwrites someone else ([9d34fca](https://github.com/aktasfatih/kanso/commit/9d34fca68f6feba89892ee9416b19af7ac8382de))
* **list-view:** allow dragging a card into an empty column in list view ([b5edae1](https://github.com/aktasfatih/kanso/commit/b5edae1640497f496a2ab1d4a4855eb9991d9481))


### Features

* **appinfo:** add German and French App Store summary and description ([ee53980](https://github.com/aktasfatih/kanso/commit/ee53980a25668bb9fe6ab0fd79a92163c782173f))
* **board:** drop a card onto another card's centre to make it a sub-card ([52a66e6](https://github.com/aktasfatih/kanso/commit/52a66e627ad1fecdd0c740ed1700ed25a9ed0305))
* **board:** let board managers hide card sections their team never uses ([125e1e6](https://github.com/aktasfatih/kanso/commit/125e1e689526e04dbbbddbb158ea57ea5733d251))
* **timeline:** draw blocking dependencies as arrows, flagging violated ones ([1594740](https://github.com/aktasfatih/kanso/commit/159474075b629caa5194fbf0635595daec9e93aa))

# [0.16.0](https://github.com/aktasfatih/kanso/compare/v0.15.0...v0.16.0) (2026-08-25)


### Bug Fixes

* add Fit mode to timeline so cards outside the default window are always reachable ([0f47093](https://github.com/aktasfatih/kanso/commit/0f470937554882e9b5e6c1d28ca0a26321ba84c5))
* give the card date popover room so its fields and repeat options fit ([6324eb9](https://github.com/aktasfatih/kanso/commit/6324eb90eed6ccc78708fae70991a20f30241f5d))
* harden the recurrence re-arm and the all-day date popover (review follow-up) ([9a8ff12](https://github.com/aktasfatih/kanso/commit/9a8ff123ad54bad16ed5ee474ff7b2fe9248ef98))
* import Deck boards that assign a label or member to a card twice ([0bd3d94](https://github.com/aktasfatih/kanso/commit/0bd3d9429ea39c6b22924bf006c8c7f374f534c3))
* keep the Timeline fast when a card spans a huge date range ([e15c6a0](https://github.com/aktasfatih/kanso/commit/e15c6a009c5e607fc9ec000596195d003f0cf47b))
* order card dates start-before-end and simplify all-day to a single date ([5156326](https://github.com/aktasfatih/kanso/commit/51563260acae8c4db337f281fbe2e82c3abe3ee6))
* prevent new or edited recurring cards from resetting their date immediately ([c7f5cce](https://github.com/aktasfatih/kanso/commit/c7f5ccefd2f5426180898250aa54c2319bff8f26)), closes [#80](https://github.com/aktasfatih/kanso/issues/80) [#65](https://github.com/aktasfatih/kanso/issues/65)
* update the card date/repeat tips to describe the window behaviour ([72578ac](https://github.com/aktasfatih/kanso/commit/72578acc8307f71f56c0fab1451daf63bf95ef57))


### Features

* editing a repeating card's start or end date re-points its schedule ([284d8da](https://github.com/aktasfatih/kanso/commit/284d8da7b9cd9a9a141f2a551b2d6310260fb635))
* explain the start date, due date and repeat fields with inline tips ([9b0e439](https://github.com/aktasfatih/kanso/commit/9b0e439979491a0f1e320323dd0eeb3bdd6c56ad))
* recurring cards slide their whole start–end date window forward ([4586c1f](https://github.com/aktasfatih/kanso/commit/4586c1faa5c6c5d72ba8ba35a008f67e0de4ca6b))
* reject an end date earlier than the start date ([1e2598c](https://github.com/aktasfatih/kanso/commit/1e2598c695fea31614c5922403e1a0fa4ff81a3d))
* rename the card's "Due date" to "End date" across the app ([f01950c](https://github.com/aktasfatih/kanso/commit/f01950c4e89fa4f4ab8b3b44d84f009976f7207d))


### Reverts

* rename the card's "Due date" to "End date" ([35e000b](https://github.com/aktasfatih/kanso/commit/35e000b343bf40dfc85e48385c646497d6f3c3db))

# [0.15.0](https://github.com/aktasfatih/kanso/compare/v0.14.0...v0.15.0) (2026-08-23)


### Bug Fixes

* clean up the recurring-card banner and delete prompt on the card view ([3e3ece6](https://github.com/aktasfatih/kanso/commit/3e3ece6c66b69b0e03ae7d1bb79f20b3ed1f3648))
* make list-view subtasks clearly indented with a nesting guide ([1c61d6e](https://github.com/aktasfatih/kanso/commit/1c61d6ead48893b4306e2d6b8b0d469cef88fada))
* reflect the timer-start automation in real time ([9868f69](https://github.com/aktasfatih/kanso/commit/9868f69f670a93a2fb05ae6763e9a86112e687cc))
* tidy the recurring-cards list in board settings ([faa0f89](https://github.com/aktasfatih/kanso/commit/faa0f89a34a4089890754e2456081181f64a070e))


### Features

* add a quick-add card composer to each column in the list view ([9f94d03](https://github.com/aktasfatih/kanso/commit/9f94d033a238e499ffbe1f8da8a559e19a6580a2))
* drag and drop cards to reorder and move them in the list view ([bffc664](https://github.com/aktasfatih/kanso/commit/bffc6645dc63e3df23a53add16bf2357b1667a00))
* open a recurring card straight from its rule in board settings ([ff35732](https://github.com/aktasfatih/kanso/commit/ff3573215dc4870c97fae19023e9d948bda05689))
* recurring-cards UX — reset/clone choice, source marker, rule editing, delete guard ([67bbdb7](https://github.com/aktasfatih/kanso/commit/67bbdb7da86dee6bc56d5e85c0f2851a12b27d5e))
* show a running-timer indicator on cards while the timer is active ([c959582](https://github.com/aktasfatih/kanso/commit/c959582eca6b2d1670ebfbf86313f0a8e1ab90b9))
* show subtasks as an indented, collapsible tree in the list view ([1566c0b](https://github.com/aktasfatih/kanso/commit/1566c0bc392217988127efe4eeb48e4875fe2f02))
* start and stop the card timer automatically when a card enters a column ([4aa41d2](https://github.com/aktasfatih/kanso/commit/4aa41d2783ac3ad964ab433c1f6fa2475dc836ee))

# [0.14.0](https://github.com/aktasfatih/kanso/compare/v0.13.0...v0.14.0) (2026-08-23)


### Bug Fixes

* add the missing space between the actor name and the change text in the card activity feed ([b530d38](https://github.com/aktasfatih/kanso/commit/b530d38cc069fa3f801b8d12114aeb72011e8560))
* clicking an [@mention](https://github.com/mention) in a card now opens that user's profile ([4860c0d](https://github.com/aktasfatih/kanso/commit/4860c0d1dad4cf38f2b1a996df8d82d1198d100e))
* markdown editor fills its width and the [@mention](https://github.com/mention) list follows the caret ([b524e02](https://github.com/aktasfatih/kanso/commit/b524e021c1d25bbf2d10cb1933e9469ee319d5b5))
* prevent Escape from closing the modal mid-description-edit and fix scroll-to-comment timing ([7d27c99](https://github.com/aktasfatih/kanso/commit/7d27c99f53864b30e0bf8f633244d2f9479f4e5d))
* recurring-card rule settings are legible in dark mode and show the correct next-run date ([807b899](https://github.com/aktasfatih/kanso/commit/807b8991f6407d8973697b2feb2660d36fc94329))
* reply to any comment in a card thread, not just the first ([7809ae1](https://github.com/aktasfatih/kanso/commit/7809ae1ad3a334bd4a723e129558d59f7e1237a9))
* restore image paste by registering the Image node in the Tiptap editor ([34142fc](https://github.com/aktasfatih/kanso/commit/34142fcb99060c524f77ae4486e2dbde1fee1f8a))


### Features

* add a toggleable formatting toolbar to the editor ([7e091b6](https://github.com/aktasfatih/kanso/commit/7e091b661ed8b2a5fa0c2936cde6468428d17ef6))
* app settings — show/hide sidebar sections (GH [#69](https://github.com/aktasfatih/kanso/issues/69), minimal slice) ([1c3195c](https://github.com/aktasfatih/kanso/commit/1c3195cec8edbba4bb07a11f09fc0a7c8311bfcf))
* fluid board column widths that fill wide screens (GH [#68](https://github.com/aktasfatih/kanso/issues/68)) ([9f8d047](https://github.com/aktasfatih/kanso/commit/9f8d047412ad656265123dba1936aa0705f3b4c3))
* granular card activity log — field-specific entries instead of "updated this card" (GH [#70](https://github.com/aktasfatih/kanso/issues/70)) ([56b3963](https://github.com/aktasfatih/kanso/commit/56b39639559d9f157910d1e7026d28038b85c81c))
* inline markdown (WYSIWYG) editor for card descriptions and comments ([ffdf400](https://github.com/aktasfatih/kanso/commit/ffdf40010db39c71fc2d75fc4fd42cd86fdc38eb))
* show the before/after diff for description edits in the card activity feed ([34e0c55](https://github.com/aktasfatih/kanso/commit/34e0c5592962fab24abdd7d9a892bd7225d06bd0))
* show what changed in the card activity feed (moves, labels, assignees, field values) ([f4348f5](https://github.com/aktasfatih/kanso/commit/f4348f521706a4bb95c3c5d9b2468aeb2a38ea1f))

# [0.13.0](https://github.com/aktasfatih/kanso/compare/v0.12.0...v0.13.0) (2026-08-21)


### Bug Fixes

* a deleted author's account id no longer shows on a public comment thread ([6f1448e](https://github.com/aktasfatih/kanso/commit/6f1448e528fcee7374b0a9d41d54007811b9ce10)), closes [#4079](https://github.com/aktasfatih/kanso/issues/4079)
* all-day due and start dates no longer show the previous day for users outside UTC ([c93749a](https://github.com/aktasfatih/kanso/commit/c93749ae8129a873bfd4284938f49a2139fefc24)), closes [#4122](https://github.com/aktasfatih/kanso/issues/4122)
* card dates save when you leave the field, not mid-typing ([#64](https://github.com/aktasfatih/kanso/issues/64)) ([d7f3e74](https://github.com/aktasfatih/kanso/commit/d7f3e74a01ff6bda83221a6edab3e230ade5c880)), closes [#4126](https://github.com/aktasfatih/kanso/issues/4126)
* green card badges stay legible in dark mode ([d9fda18](https://github.com/aktasfatih/kanso/commit/d9fda18106092ce15a1d57ef73cc5a381c901b15))
* green status pills are now legible in dark mode ([abca4f2](https://github.com/aktasfatih/kanso/commit/abca4f220ac1ead4443a0e3eccbd37d32ff0219e)), closes [#3fb950](https://github.com/aktasfatih/kanso/issues/3fb950)
* import boards with very large lists without a "rebalance_required" error ([ab3e09e](https://github.com/aktasfatih/kanso/commit/ab3e09e95ae8de49080a78c595a10cf7a3a24de2))
* keep card attribute popovers on-screen on mobile ([bc5799e](https://github.com/aktasfatih/kanso/commit/bc5799e3c735b4371e65edc40f57d1f509e6acbc)), closes [#4058](https://github.com/aktasfatih/kanso/issues/4058)
* keep the card view header readable on mobile ([828d7e2](https://github.com/aktasfatih/kanso/commit/828d7e232cc424e83e76dda85a60c5ab00ea617b)), closes [#60](https://github.com/aktasfatih/kanso/issues/60)
* on mobile, card attributes stay at the top and wrap instead of scrolling ([aed1708](https://github.com/aktasfatih/kanso/commit/aed17083b1cfad926924ef708949d58798efb118))
* priority, type and review chips stay legible in dark mode ([d4bf36f](https://github.com/aktasfatih/kanso/commit/d4bf36ffc9a201b90519e985394d2409062aa2b2)), closes [888/#7f8c8d](https://github.com/aktasfatih/kanso/issues/7f8c8d) [#e07b00](https://github.com/aktasfatih/kanso/issues/e07b00) [#e74c3c](https://github.com/aktasfatih/kanso/issues/e74c3c)
* put a space between the Discussion tab label and its comment count ([57e4c27](https://github.com/aktasfatih/kanso/commit/57e4c2784ae5b8edd34f6f8984e6093e36f464bb))
* recurring cards no longer spawn from a trashed template or lose the all-day flag ([38b23ca](https://github.com/aktasfatih/kanso/commit/38b23caf87120eef8adf2739736f35c56ee58802))
* stop recurring cards from duplicating when their schedule is edited ([3c0afa6](https://github.com/aktasfatih/kanso/commit/3c0afa69d8173bb3bc82c89589e4cfd67061e2bd)), closes [#4107](https://github.com/aktasfatih/kanso/issues/4107) [#65](https://github.com/aktasfatih/kanso/issues/65)
* stop recurring-card cron errors after permanently deleting a template card ([60a50e0](https://github.com/aktasfatih/kanso/commit/60a50e06cc5196e8aafb369e6bb63f2325331639))
* typing a date by keyboard in the card no longer drops digits or jumps the cursor ([c8edd11](https://github.com/aktasfatih/kanso/commit/c8edd113c9cf63acd05313795482eed42eddb709)), closes [#64](https://github.com/aktasfatih/kanso/issues/64)


### Features

* multi-select cards can now be marked done in one action ([adc7868](https://github.com/aktasfatih/kanso/commit/adc7868495d89bc66bbe5ffc80857b3273b52f0e))
* public links can now opt in to showing read-only comments ([a2e73a0](https://github.com/aktasfatih/kanso/commit/a2e73a0d134f18973c02903fac03c981093ea7b6))
* recurring cards now show a repeat icon on the board ([1948072](https://github.com/aktasfatih/kanso/commit/1948072a23e77cffa527cf2fcc7d56381ccf2acd)), closes [#4052](https://github.com/aktasfatih/kanso/issues/4052) [#61](https://github.com/aktasfatih/kanso/issues/61)
* the open card's due date now shows a repeat icon for recurring cards ([675968f](https://github.com/aktasfatih/kanso/commit/675968f35b1143ea3ba043c77b03bf3357cd0a84)), closes [#61](https://github.com/aktasfatih/kanso/issues/61)

# [0.12.0](https://github.com/aktasfatih/kanso/compare/v0.11.0...v0.12.0) (2026-08-20)


### Bug Fixes

* don't fetch card recurrence before the board id resolves ([ea4d1bb](https://github.com/aktasfatih/kanso/commit/ea4d1bb67d33860abb065175e1b51d22e58ed030)), closes [#3817](https://github.com/aktasfatih/kanso/issues/3817)
* **l10n:** translate analytics chart accessibility labels ([8c6213d](https://github.com/aktasfatih/kanso/commit/8c6213d7c12a4d032b3e9414e1abd3c2764daf91))
* let calendar clients toggle the visibility of a Kanso board calendar ([04d74fd](https://github.com/aktasfatih/kanso/commit/04d74fd071f6abd93a0e8643d31d5756f7dfa60e))
* move a card into its workflow column when you change its status ([5948bda](https://github.com/aktasfatih/kanso/commit/5948bdaa90fc1e7059f2d8684f63de4dbebce9a0)), closes [#54](https://github.com/aktasfatih/kanso/issues/54)
* show release notes on the Nextcloud app store ([8a787a4](https://github.com/aktasfatih/kanso/commit/8a787a40c7c111a1714000ec424f25ed900e0295)), closes [#57](https://github.com/aktasfatih/kanso/issues/57)


### Features

* choose which boards appear in your calendar ([d0bc743](https://github.com/aktasfatih/kanso/commit/d0bc743fe0a829db2fab9ebcb271b409e81be92f))
* **l10n:** translate the interface into your Nextcloud language, starting with German ([1d706a1](https://github.com/aktasfatih/kanso/commit/1d706a1d873e2ab04e9230f18b880753924c160e))
* make a card repeat straight from its due-date menu ([8c81d4f](https://github.com/aktasfatih/kanso/commit/8c81d4ffa44947e8573e4bad234f3e2cf5fb14fc)), closes [#55](https://github.com/aktasfatih/kanso/issues/55)
* show cards with due dates in your calendar and sync them to your phone ([407c6de](https://github.com/aktasfatih/kanso/commit/407c6deb465a316c7715a878abe6a8baddbc04ca))
* the card status control now offers the board's workflow columns as stages ([4ccd4a2](https://github.com/aktasfatih/kanso/commit/4ccd4a2c9b418bc5a5aeb5a2b83daeece91e1cff)), closes [#54](https://github.com/aktasfatih/kanso/issues/54)

# [0.11.0](https://github.com/aktasfatih/kanso/compare/v0.10.0...v0.11.0) (2026-08-19)


### Bug Fixes

* **board:** align colour swatches with their labels in the column options menu ([a86ebe0](https://github.com/aktasfatih/kanso/commit/a86ebe0f93d0ec22ccb69a3add126f70966abe46))
* **import:** bring over both kinds of Deck card attachment ([12a079b](https://github.com/aktasfatih/kanso/commit/12a079be336d1a4073fcfdd659e27c374efde14d))
* **public:** make shared board scroll and open cards for full details ([2fcbcd9](https://github.com/aktasfatih/kanso/commit/2fcbcd9053da2008c7081c5ff9e37befb474cc56))
* **views:** open cards in the View as an overlay and match board tiles ([ffd3c37](https://github.com/aktasfatih/kanso/commit/ffd3c378652c9e82067e985287b98e9032c561ec))


### Features

* **public:** richer read-only public card detail (cover, dates, estimate, markdown) ([a6f0c96](https://github.com/aktasfatih/kanso/commit/a6f0c96ac67ab0487d8f71931c17948934a87413))
* **views:** add a Kanban display that groups a saved View into columns ([3a936e3](https://github.com/aktasfatih/kanso/commit/3a936e30bb54bbcfa9b288e2070ab5f485fd9568))

# [0.10.0](https://github.com/aktasfatih/kanso/compare/v0.9.38...v0.10.0) (2026-08-19)


### Bug Fixes

* **views:** cap the cross-board Views feed and flag truncation ([0448650](https://github.com/aktasfatih/kanso/commit/0448650dfe4390f44b1d0814b86f808f180c82d1))


### Features

* **board:** quick-look preview follows keyboard selection ([acad1d0](https://github.com/aktasfatih/kanso/commit/acad1d01335f187ec870d7bdee2e86d2947d664b))
* **card:** reminder links scroll to and highlight the exact comment ([37ba244](https://github.com/aktasfatih/kanso/commit/37ba2441e91f0318109ed7e5015cbcebe7bf7084))
* **mcp:** add, toggle and list card checklist items over MCP ([b1d39e9](https://github.com/aktasfatih/kanso/commit/b1d39e941fce16e80dd57d3dc0b78dcfbb3c9b14))
* **mcp:** expose all card fields (status, type, visibility, cover) over MCP ([3935072](https://github.com/aktasfatih/kanso/commit/3935072af02b9545fa61eb05e1848632922ea781))
* **mcp:** list a board's assignable members to enable user assignment ([37f84d3](https://github.com/aktasfatih/kanso/commit/37f84d3660cf21dfaab584c53dd614d419a78f3a))
* **mcp:** manage card relations and subtask parents over MCP ([5363477](https://github.com/aktasfatih/kanso/commit/5363477b0da048ca8dea3d8a954f2b8c40eeeffd))
* **mcp:** read and post card comments over MCP ([d957141](https://github.com/aktasfatih/kanso/commit/d957141805778f20879be339b82d89c56db684a9))
* **nav:** add a help menu to file an issue or set up MCP ([bea4935](https://github.com/aktasfatih/kanso/commit/bea493542e8fdaf33da40482947fbf5c1d97aa85))

## [0.9.38](https://github.com/aktasfatih/kanso/compare/v0.9.37...v0.9.38) (2026-08-18)


### Bug Fixes

* **import:** survive long/edge-case titles in Deck import ([8b05072](https://github.com/aktasfatih/kanso/commit/8b05072834ec07024e8872b3b1d7a7055e604f95)), closes [#40](https://github.com/aktasfatih/kanso/issues/40)
* **ui:** legible error-red on card tiles in dark mode ([fa6d864](https://github.com/aktasfatih/kanso/commit/fa6d864f90ed86b72eac543cbd553b15fa0c5dd6)), closes [#40](https://github.com/aktasfatih/kanso/issues/40)

## [0.9.37] - 2026-08-17

### Added

- **Cross-board saved Views (phase 1: List & Timeline).** A *View* is a named saved
  filter that spans **every board you can read**. Create one from the **New view**
  entry in the left-nav *Views* section, then set its filter, **group-by** (Status,
  Priority, Assignee, Board, Type, Review, Due date, or Owner) and **display** (List
  or Timeline) and rename it in place from the view header (or from the nav's
  rename/delete). Views appear in a collapsible *Views* section in the left nav like
  your boards; opening one shows a board-like surface over the matching cards from
  all your boards at once. Views reuse the same filter engine as the board filter
  bar — including the full dimension set (labels, assignees, priority, type,
  estimate, owner, review state, due/start date, done, client status, blocked,
  checklist, sub-cards, and comments) — and only ever read boards you have access to,
  so a View never surfaces cards from a board you can't see. The same richer filter
  dimensions are now available on the board filter bar too. (Kanban display arrives
  in a later phase.)

### Added

- **Personal "Remind me" on cards and comments.** You can now set a private,
  one-shot reminder for yourself on a card — from the card's overflow menu or from
  a specific comment (the *Remind me* action in the comment's menu). Pick a preset
  (*Later today*, *Tomorrow*, *Next week*) or a custom date and time. At the chosen
  time you get a Nextcloud notification (bell + push) that deep-links to the card
  (and, for a comment reminder, carries the comment). Reminders are personal: only
  you see or receive yours, another member on the same card never does. Your pending
  reminders show on the card with a one-click cancel, firing happens once and catches
  up if the reminder is overdue, and a reminder on a card that has become invisible
  to you is silently skipped (no leak).
- **Open a card as a full page.** A card can now be viewed on its own full-width
  page at `/card/<id>` instead of only as a dialog overlay. An expand button in the
  card dialog's header switches to the full-page view, which shows a breadcrumb for
  the card's board and a *Back to board* affordance. The full page and the dialog
  render from the same component, so they behave identically — the existing
  click-a-card-to-open-a-dialog flow is unchanged.

### Changed

- **Progressive filter (toolbar redesign, phase 2).** The board *Filter* control is
  now a progressive drill-in popover instead of dumping every value at once:
  the top level lists the dimensions (Labels, Assignees, Priority, Type, Estimate,
  Due date, Status, Client status) each with an active-value summary and a count
  badge, and clicking one drills into only that dimension's values with a back
  arrow. Saved views (apply / save / delete a named view, plus *Default (no filter)*)
  now live inside the Filter control, so the separate *Saved* header button is gone
  — the toolbar is now just `[Display] [Filter] [⋯]`. Filtering behaviour, the
  shareable URL, and saved views are unchanged.

### Fixed

- **Clearing a Type, Estimate, or Client-status filter now cleans up the shareable
  URL.** Previously, clearing one of those filters left a stale `ft=`/`fe=`/`fw=`
  parameter dangling in the address bar (so a copied link still carried the removed
  filter). Clearing any filter dimension now strips its parameter from the URL.

## [0.9.36] - 2026-08-12

### Added

- **Sort a board by estimate.** The display-sort menu gains an *Estimate* option
  (shown on boards that have an estimation scale) — cards order by their position
  in the board's scale (so `13 > 8 > … > 2` and `XL > … > XS`, not string order),
  with unestimated cards last. View-only, per-user, like the other sorts.
- **Ascending / descending sort direction.** Every sort (Priority, Due date,
  Title, Estimate) now has an Ascending/Descending toggle in the sort menu, with a
  ↑/↓ indicator on the toolbar. Selecting a sort starts in its natural direction
  (urgent-first, soonest-first, A→Z, biggest-first); missing values (no due date /
  no estimate) always sort last regardless of direction. Persisted per user.
- **Filter a board by estimate.** The filter bar gains an *Estimate* facet: pick
  one or more scale tokens, or *Unestimated*, to narrow the board. Combines with
  the other filter dimensions and round-trips through the shareable URL and saved
  views.
- **"Default (no filter)" entry in the Saved-filters menu.** A one-click way back
  to the unfiltered board — an easy exit from an applied saved view or any ad-hoc
  filter.
- **Rename a board.** Board Settings → General now has a *Board name* field
  (managers only); saving updates the header, the app-navigation sidebar, the
  command palette and the boards grid live.
- **"Add column" moved into the ⋯ More menu.** The board no longer carries a
  persistent "Add stack" text box taking up a column of space; adding a column is
  now an action in the ⋯ menu that reveals a focused composer on demand (a
  brand-new empty board still shows an inline first-column prompt). The composer
  and the menu action are shown to editors only.

### Changed

- **Board toolbar consolidated behind a "Display" control.** How the board is
  arranged — view (Board / List / Timeline), Group by (swimlanes), Sort (+
  direction) and Density — now lives in a single *Display* popover instead of
  several separate toolbar menus. This declutters the header and
  makes it fit narrow / mobile widths without pushing controls off-screen; on a
  narrow header the back, Display and ⋯ buttons go icon-only and the search box
  collapses to a magnifier.
- **Importers no longer cap how many cards you can bring in.** The CSV /
  spreadsheet import row limit is raised from 2,000 to 200,000 and its file-size
  cap from 5 MiB to 64 MiB; the Deck and Trello whole-board import file-size caps
  go from 12 MiB to 32 MiB. The CSV importer now streams rows off the file one at
  a time instead of loading them all into memory, so peak memory tracks the file
  size rather than the card count. The remaining caps are generous backstops
  against a pathological upload, not a ceiling on a real board.

### Fixed

- **Downloading (and inline-previewing) card attachments no longer fails with a
  CSRF error.** The attachment `download` and `inline` endpoints are plain GET
  requests reached by a browser navigation / `<img>` load, which cannot carry a
  CSRF token — they now declare `NoCSRFRequired` (auth is still enforced by the
  session and board-read permission check), so attachments download and preview
  reliably again.
- **Sort / view / swimlane menus now show the active option and switch on one
  click.** The radio menus (Sort, board view mode, Swimlanes) previously rendered
  nothing as selected when reopened and needed a double click to change — they now
  highlight the current choice and switch with a single click.
- **Changing a board's estimation scale no longer strands card estimates.**
  Switching scales (or turning estimation off) now clears any card estimate that
  doesn't fit the new scale — previously an off-scale value (e.g. a Fibonacci `8`
  after a switch to T-shirt) lingered on the card and could neither be re-selected
  nor cleared. The board settings dialog warns how many cards are affected and
  asks to confirm before clearing.
- **My Work pages stay current without a manual reload.** My Tasks, My Reviews
  and Inbox now refresh when you change something (e.g. assign yourself a card),
  when you navigate to them, and on a live poll while the page is open —
  previously they could keep showing data from earlier in the session until a
  browser refresh.
- **Open cards update in realtime across tabs and sessions.** Edits to a card's
  title, description, comments or checklist now appear in an already-open card
  in another tab as the change arrives (near-instant when notify_push is
  available, on the delta poll otherwise), without overwriting an edit you have
  in progress.
- **Large CSV imports no longer fail with a sort-key overflow.** The CSV importer
  assigned each imported card a sort key by chaining `after()` off the previous
  one, which grew the key by a character every ~two dozen cards and overflowed the
  64-character `sort_key` column at roughly 2,000 rows — the hidden reason the row
  cap sat there. Imported cards now get a single bounded, evenly-spaced key block
  (new `SortKeyService::appendSequence`), so a block of any realistic size stays
  well within the column and preserves file order.

## [0.9.35] - 2026-08-09

Consolidates the 2026-08-09 sprint. Versions 0.9.32–0.9.34 were internal
per-feature version bumps on the release branch and were never published; their
changes all land here.

### Added

- **Card visibility: public / internal / private.** Every card now carries a
  visibility level. *Public* (the default, and the behavior of all existing
  cards) is visible to every board member; *internal* is visible only to the
  creator's side of the board (see member roles below) — symmetric, so a
  client-side internal card is equally hidden from the provider side, with no
  owner or manager backdoor; *private* is visible to its creator alone. The
  rule is enforced in SQL on every read path — board payload, delta sync,
  search, My Work dashboards, stats and tile counts, trash, export, duplicate,
  the public share snapshot and the calendar feed — and a hidden card behaves
  exactly like a missing one on card-addressed endpoints (404, never a 403
  existence oracle). Background emissions honour it too: due reminders,
  comment/mention fan-outs, watcher notifications, activity and webhook egress
  never reach (or name the card to) a user outside its visibility, stale bell
  entries stop rendering if a card narrows after they were queued, and
  deferred (stage-gated) review-request notifications re-check visibility at
  fire time.
- **Board member roles: internal vs external.** A board member is now shared
  in as either *internal* (your own team) or *external* (the client/partner
  side). The role feeds the internal card-visibility fence and freezes onto
  what each member creates, and can be changed later by a board manager.
- **Rich checklist steps.** A checklist item can now carry an assignee (with
  their board side frozen at assignment time), a due date with overdue
  styling, and a done-at stamp — plus a cross-board **My steps** feed
  (`/api/my-steps`) of every open step assigned to you, visibility-scoped like
  every other feed.
- **Derived "waiting on client" status.** A card with at least one open step
  parked on the external side shows a "waiting since …" chip on its tile and
  can be filtered on — computed live from step state, never stored, so it can
  not drift.
- **GitHub issue intake (opt-in).** The board's GitHub webhook can now react
  to issue events and — when explicitly enabled — auto-create a linked card
  when an issue opens, using the existing HMAC-verified endpoint. The webhook
  response body never names anything beyond public cards.
- **Board tile menu.** The boards grid tile gains a context menu: duplicate,
  export, archive and delete a board without opening it.
- **Per-board project chat link.** A board can carry a chat URL (Talk, Slack,
  …) surfaced as a toolbar deep-link button.
- **Admin setup check.** Nextcloud's admin overview now warns when background
  jobs run on AJAX cron or `overwrite.cli.url` is unset — both degrade
  reminders, recurrence and webhook delivery.

### Fixed

- **Public share snapshot queries are public-only end to end.** The anonymous
  board snapshot now restricts its label-association query to public cards in
  SQL (as the checklist counts already were) instead of fetching every card's
  labels and discarding the hidden ones in PHP.

## [0.9.31] - 2026-08-08

First tagged beta, published as a pre-built tarball on
[GitHub Releases](https://github.com/aktasfatih/kanso/releases/tag/v0.9.31).

### Added

- **Import cards from a CSV / spreadsheet.** The board-list Import menu now has a
  working "CSV file" entry (#3678): upload or paste a CSV, map its columns
  (title required; optional description, due date, comma-separated labels and
  assignees) with sensible header auto-detection, then add the rows as cards to a
  board and column you choose. Labels are matched-or-created on the target board;
  assignees are matched-or-dropped, filtered by READ so an import never
  references someone who cannot see the board. The whole import is a single
  all-or-nothing transaction (byte-capped before parsing, row-capped, long titles
  truncated), EDIT on the target board is required, and the cards append to the
  chosen stack with a single realtime push. This is the "add my spreadsheet of
  tasks to an existing board" case only — creating a whole new board from a CSV
  is out of scope (the Deck/Trello importers cover whole-board creation).
- **iCal / ICS calendar feed of card due dates.** A board manager can now expose
  a read-only calendar feed of the board's card due dates (Board settings →
  Automation → Calendar feed), subscribable in any calendar client (Nextcloud
  Calendar, Thunderbird, phone). It is OFF by default; enabling mints a long,
  unguessable token, and the feed URL is revocable (disable) and rotatable
  (rotate mints a fresh one, invalidating the old URL immediately). The feed is
  deliberately minimal: one event per card that has a due date, carrying only the
  card title, the due date (honouring the all-day flag) and a link back to the
  card — never descriptions, assignees or any other data, and never cards from
  another board. It is read-only (no write-back); full two-way CalDAV sync is a
  separate feature. The public feed endpoint is brute-force throttled so tokens
  can't be enumerated.

- **Public / read-only board share links.** A board manager can now mint a
  public, unauthenticated, read-only link to a whole board (Board settings →
  Automation → Public link). It is OFF by default; enabling mints a long,
  unguessable token, and the link is revocable (disable clears it) and rotatable
  (rotate mints a fresh one, invalidating the old link immediately). The public
  view is deliberately stripped: it shows only the board title, columns and
  per-card title, description, labels, due date, checklist progress, priority,
  status and human id — never assignees, comments, activity, members, owners or
  any other people/internal data. The public endpoints are brute-force throttled
  so tokens can't be enumerated.

- **File attachments on cards.** A card now has an Attachments section: upload a
  file (picker), see it listed with its name and size, download it, and delete it
  (EDIT-gated). Files are stored in Kanso's own app-data — not in anyone's
  personal Files — and served through a board-permission-gated endpoint (READ to
  view/download, EDIT to upload/delete). The on-disk name is server-generated
  (the client filename is kept only as a display label, so path traversal is
  impossible), uploads are size-capped, and downloads are always forced as an
  attachment with `nosniff` so an uploaded HTML/SVG can never render inline. The
  card detail payload carries an attachment count, and add/delete emit a
  change-log row so delta-sync and ETags stay correct.

- **Project discussion log (owner-only comments on a project).** The project page
  now carries a comment thread — post, edit, delete, with one-level replies and
  the same markdown toolbar + rendering as card comments. Because projects are
  owner-only (no sharing), it is a private per-owner log: every operation is
  owner-gated and there is no @mention/notify. New table `kanso_project_comments`
  and endpoints under `/api/projects/{id}/comments`.

- **Board export / import (full data portability).** Every board can be exported
  to a single Kanso JSON document (board settings → Export board) carrying its
  whole graph — stacks, cards, labels, review types, checklist items, comments
  (with threading), card↔label / assignee links, archive rules and recur rules.
  Uploading that file from the board list (Import → Kanso export) recreates the
  whole board with fresh ids under the importer, remapping every internal
  reference and preserving sort keys. The import is all-or-nothing, size-capped,
  and rejects unknown/future export versions. This is Kanso's own round-trippable
  format, distinct from the one-click Deck importer.

### Changed

- **Nextcloud 34 and PHP 8.3 support.** The supported range is now Nextcloud
  30–34 on PHP 8.2–8.3 (previously 30–32). Verified against `nextcloud:34`:
  clean install/upgrade, all migrations apply, full PHPUnit suite and the e2e
  smoke set pass on PHP 8.3, and realtime push (notify_push) works. Existing
  NC 30–33 installs are unaffected.

- **Cross-version install/migration CI matrix.** CI now boots a throwaway
  Nextcloud on every supported major (30, 31, 32, 33, 34) across SQLite and
  PostgreSQL — plus MariaDB on NC 34 — enables Kanso, runs `occ upgrade`, and
  asserts every migration applied and the schema was physically created (the
  check that guards against the class of migration bug where an over-long,
  default-named index silently creates zero tables). PHPUnit continues to run in
  the dedicated `unit-php` job across PHP 8.2 and 8.3; the full Playwright e2e
  suite continues to run on NC 34 + PostgreSQL only. The dev stack
  (`dev/setup.sh`) is now version/DB-parametrized via `NC_VERSION` and
  `KANSO_DB`.

### Fixed

- **Install on Nextcloud 30–32 (over-long primary-key names).** Several tables
  relied on the database's default-generated PRIMARY KEY name, which on NC 30–32
  can exceed Nextcloud's 23-character index-name limit and abort app install
  before any table is created (NC 33/34 relaxed the check, so this only affected
  older versions). Each affected table now declares an explicit short PK name.
  Surfaced by the new cross-version CI matrix. Existing installs are unaffected
  (the table-creation migrations are `hasTable`-guarded).

## [0.9.2] - 2026-08-01

### Changed

- **Faster "My tasks" and label deletion on large instances.** Added two
  hot-path database indexes: `kanso_card_assignees(participant, type)` so the
  cross-board assigned-cards dashboard query is a range seek instead of a full
  scan of the assignee table per user, and `kanso_card_labels(label_id)` so
  deleting a label targets its rows instead of full-scanning and lock-holding
  the label join table. Additive schema migration, no data changes.

## [0.9.1] - 2026-08-01

### Fixed

- **Recurring cards catch up on missed occurrences.** A delayed or downed cron
  now spawns one card per missed occurrence (e.g. a server off for three days
  backfills the three cards it owed), instead of a single card per run. Catch-up
  is bounded per run so a long-dormant rule can't flood a board; the remainder
  continue on the next run.
- **Recurring schedules are timezone-stable across DST.** A rule now carries an
  IANA timezone (defaulting to the owner's Nextcloud personal timezone, server
  default as fallback) and is expanded as floating wall-clock time (RFC 5545 /
  CalDAV): "daily at 09:00" fires 09:00 local on both sides of a daylight-saving
  transition. Existing rules with no timezone fall back to the server timezone.

## [0.9.0] - 2026-07-30

First public release. Targets Nextcloud 30–32 and PHP 8.2+.

### Added

- **Boards, stacks and cards** with instant, optimistic drag & drop. A card move
  is a single-row update backed by fractional sort keys, never a bulk renumber.
- **Large-board performance**: summary-only board payloads, `ETag` /
  `If-None-Match` caching, and virtualized columns that stay smooth past
  2,000+ cards.
- **Rich cards**: sanitized markdown descriptions, labels, due dates, assignees,
  priorities, checklists / sub-tasks, and parent ↔ child cards (a parent
  auto-completes when all its children are done).
- **Comments** with threaded replies, **@mentions**, and **watchers** on cards,
  comment threads, or a whole board.
- **Board sharing** with per-user and per-group access control.
- **Review workflow**: request a review, then approve or request changes;
  customizable review types (QA, Code, Legal, …); and an optional done-gate that
  blocks a card from leaving a review column until every review is approved.
- **My Work hub**: a cross-board view of My tasks (cards assigned to you),
  Reviews (waiting on you), and an Inbox of mentions and watched-card activity,
  filterable to a single board.
- **Projects**: cross-board card collections with markdown descriptions and
  per-project analytics.
- **Analytics** (per-board and per-project): velocity (cards/points per week with
  trend), cycle time (median/average days to done), throughput, plus breakdowns
  by stack, priority, assignee and label, and overdue / aging /
  checklist-progress signals.
- **Stack roles and WIP limits**: moving a card into an "in progress" column
  auto-starts it and a "done" column stamps it done; status can also be set
  directly on the card.
- **Recurring cards** on RRULE schedules and **auto-archive** rules for done
  cards.
- **Board, List and Timeline (Gantt) views**, remembered per user, plus a
  view-only display sort (by priority, due date or title) that preserves the
  manual drag order.
- **Command palette** (`Ctrl` / `Cmd` + `K`) and full-text search across cards
  and comments.
- **Trash with restore** and undo toasts for destructive actions.
- **GitHub links**: attach PRs/issues with live open/merged/closed badges and a
  ready-made `kanso-<id>` branch name, plus an HMAC-verified **GitHub webhook**
  that moves a card to your Review column when its PR opens and to Done when it
  merges.
- **Import from Deck**: one click copies a Deck board (stacks, cards, labels,
  assignees) into a new Kanso board you own, leaving your Deck boards untouched.
- **Realtime updates** via `notify_push` (High Performance Backend) when
  available, with an automatic light polling fallback everywhere else.

[Unreleased]: https://github.com/aktasfatih/kanso/compare/v0.9.36...HEAD
[0.9.36]: https://github.com/aktasfatih/kanso/compare/v0.9.31...v0.9.36
[0.9.31]: https://github.com/aktasfatih/kanso/compare/v0.9.2...v0.9.31
[0.9.2]: https://github.com/aktasfatih/kanso/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/aktasfatih/kanso/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/aktasfatih/kanso/releases/tag/v0.9.0
