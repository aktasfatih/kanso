#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Seed a Kanso instance with realistic pre-existing data, and verify that same
# data still reads back later. Two modes:
#
#   ./seed.sh seed     create the data, remember the ids in a state file
#   ./seed.sh verify   read every seeded entity back and assert it survived
#
# WHY THIS EXISTS
# ---------------
# Every other CI job installs Kanso fresh, so every migration meets an EMPTY
# database. The classic ways a migration breaks - a NOT NULL column with no
# default, a backfill that trips over real values, a unique index that collides
# with existing duplicates - are all invisible to a fresh install. dev/
# upgrade-check.sh installs the last RELEASED tag, runs this seeder against it,
# swaps in the branch tree and upgrades; `verify` is then the assertion that the
# user's data survived. See the `upgrade` job in .github/workflows/ci.yml.
#
# DB-AGNOSTIC BY CONSTRUCTION
# ---------------------------
# Everything is seeded THROUGH THE HTTP API, which is identical on sqlite,
# postgres and mariadb - so this script works on all three even though the
# upgrade job currently only runs one of them. The single exception is the
# backdated activity timestamp (see backdate_changes below), which no API can
# set; that one value goes through one small helper with a per-driver branch,
# the same pattern dev/smoke.sh uses for its park_tail_key_* helpers.
#
# WHAT IT COVERS (be honest about this list, and extend it when you add a table)
# -----------------------------------------------------------------------------
#   kanso_boards            1 board (unicode title)
#   kanso_stacks            2 stacks
#   kanso_cards             3 cards: one rich (description, due date, unicode),
#                           one recurrence template, one plain
#   kanso_labels            1 label, assigned to the rich card
#   kanso_assigned_users    admin assigned to the rich card
#   kanso_comments          1 top-level comment + 1 THREADED reply
#   kanso_comment_reactions 1 reaction on the top-level comment
#   kanso_checklist_items   1 item
#   kanso_attachments       1 uploaded text attachment (bytes asserted on verify)
#   kanso_card_relations    1 `relates` edge between two cards
#   kanso_time_entries      1 time entry
#   kanso_recur_rules       1 weekly recurrence rule
#   kanso_changes           the change-log rows all of the above generate, with
#                           this board's rows aged 8 days (see backdate_changes)
#
# NOT covered yet (a migration touching these is NOT protected by this job):
#   projects, board groups, saved filters, views, ACL/shares, public shares,
#   calendar feeds, automation/archive rules, custom card fields, reminders,
#   reviews, subscriptions, webhooks, trash.
# Extend the list above together with the seeding, so the next reader can trust
# it. Do NOT turn this into a general-purpose fixtures framework.
#
# Env:
#   KANSO_BASE_URL      default http://localhost:8891
#   KANSO_ADMIN_AUTH    default admin:admin
#   KANSO_DB            postgres (default) | mysql | sqlite - only used by the
#                       one backdating helper
#   KANSO_CONTAINER     default kanso-dev      (sqlite branch of that helper)
#   KANSO_DB_CONTAINER  default kanso-dev-db   (postgres/mysql branch)
#   KANSO_SEED_STATE    where the ids are remembered, default dev/.seed-state.env
set -eu
cd "$(dirname "$0")"

MODE="${1:-seed}"

KANSO_DB="${KANSO_DB:-postgres}"
CONTAINER="${KANSO_CONTAINER:-kanso-dev}"
DB_CONTAINER="${KANSO_DB_CONTAINER:-kanso-dev-db}"
BASE_URL="${KANSO_BASE_URL:-http://localhost:8891}"
ADMIN_AUTH="${KANSO_ADMIN_AUTH:-admin:admin}"
STATE="${KANSO_SEED_STATE:-.seed-state.env}"

# Fixed literals the verify pass asserts on. Deliberately non-ASCII: a migration
# that re-encodes or truncates a column shows up here rather than in production.
BOARD_TITLE='Upgrade seed — üñïçø∂é board 🗂'
CARD_TITLE='Seeded card — ünïcode ✅'
CARD_BODY='Pre-existing description with a unicode dash — an emoji 🚀 and a
second line, so a backfill meets real text rather than an empty string.'
COMMENT_BODY='Seeded top-level comment — ünïcode 💬'
REPLY_BODY='Seeded threaded reply — ünïcode ↩'
ATTACHMENT_TEXT='seeded attachment bytes — ünïcode 📎'
BACKDATE_DAYS=8

fail() { echo "SEED FAIL ($MODE): $*" >&2; exit 1; }

API_BODY="$(mktemp)"
UPLOAD_FILE=''
cleanup() { rm -f "$API_BODY" ${UPLOAD_FILE:+"$UPLOAD_FILE"}; }
trap cleanup EXIT

# api <METHOD> <path> [json body] - body lands in $API_BODY, status in $API_STATUS.
# Auth is admin BasicAuth + the OCS-APIRequest header, which is how Nextcloud
# lets a non-browser client skip the CSRF token (same as dev/smoke.sh).
api() {
	if [ $# -ge 3 ]; then
		API_STATUS="$(curl -s -o "$API_BODY" -w '%{http_code}' \
			-u "$ADMIN_AUTH" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
			-X "$1" -d "$3" "$BASE_URL/apps/kanso$2")"
	else
		API_STATUS="$(curl -s -o "$API_BODY" -w '%{http_code}' \
			-u "$ADMIN_AUTH" -H 'OCS-APIRequest: true' \
			-X "$1" "$BASE_URL/apps/kanso$2")"
	fi
}

expect_200() {
	[ "$API_STATUS" = "200" ] \
		|| fail "$1 -> HTTP $API_STATUS. Response: $(head -c 500 "$API_BODY")"
}

# First "id" in a JSON object response is the created entity's own id.
created_id() { grep -o '"id":[0-9]*' "$API_BODY" | head -1 | cut -d: -f2; }

# Minimal JSON string escaping for the literals above: backslash, quote, newline.
# They are fixed constants in this file, never external input.
json_str() {
	printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | awk 'BEGIN{ORS=""} NR>1{print "\\n"} {print}'
}

# --- the one thing the API cannot do ----------------------------------------
# Age this board's change-log rows so the instance looks like it has been in use
# for more than a week rather than for the eight seconds this script took. The
# activity feed renders entries older than a week differently (#10177), and a
# migration that rewrites kanso_changes must meet rows with a plausible spread of
# timestamps, not a single clump at "now". `created_at` is a unix-timestamp int
# column, so the SQL is identical everywhere - only the client invocation differs,
# which is exactly why this is ONE helper with a per-driver branch rather than
# scattered psql calls. Both values interpolated below are locally derived: an
# integer literal and the board id this script just created.
backdate_changes_postgres() {
	docker exec "$DB_CONTAINER" psql -U nextcloud -d nextcloud -tAc \
		"UPDATE oc_kanso_changes SET created_at = created_at - $((BACKDATE_DAYS * 86400)) WHERE board_id = $SEED_BOARD;"
}
backdate_changes_mysql() {
	docker exec "$DB_CONTAINER" mariadb -unextcloud -pnextcloud -N -B nextcloud -e \
		"UPDATE oc_kanso_changes SET created_at = created_at - $((BACKDATE_DAYS * 86400)) WHERE board_id = $SEED_BOARD;"
}
backdate_changes_sqlite() {
	docker exec -u www-data "$CONTAINER" php -r '
		$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
		$s = $db->prepare("UPDATE oc_kanso_changes SET created_at = created_at - ? WHERE board_id = ?");
		$s->execute([(int)$argv[1], (int)$argv[2]]);
	' "$((BACKDATE_DAYS * 86400))" "$SEED_BOARD"
}
backdate_changes() {
	case "$KANSO_DB" in
		postgres)      backdate_changes_postgres >/dev/null ;;
		mysql|mariadb) backdate_changes_mysql >/dev/null ;;
		sqlite)        backdate_changes_sqlite ;;
		*) fail "unknown KANSO_DB='$KANSO_DB'" ;;
	esac
}

# ---------------------------------------------------------------------------
# seed
# ---------------------------------------------------------------------------
do_seed() {
	api POST /api/boards "{\"title\":\"$(json_str "$BOARD_TITLE")\"}"
	expect_200 'POST /api/boards'
	SEED_BOARD="$(created_id)"
	[ -n "$SEED_BOARD" ] || fail "POST /api/boards returned no id: $(head -c 500 "$API_BODY")"

	api POST /api/stacks "{\"boardId\":${SEED_BOARD},\"title\":\"Seeded To do\"}"
	expect_200 'POST /api/stacks (1)'
	SEED_STACK="$(created_id)"

	api POST /api/stacks "{\"boardId\":${SEED_BOARD},\"title\":\"Seeded Done\"}"
	expect_200 'POST /api/stacks (2)'
	SEED_STACK2="$(created_id)"

	api POST /api/labels "{\"boardId\":${SEED_BOARD},\"title\":\"seeded\",\"color\":\"0082c9\"}"
	expect_200 'POST /api/labels'
	SEED_LABEL="$(created_id)"

	api POST /api/cards \
		"{\"stackId\":${SEED_STACK},\"title\":\"$(json_str "$CARD_TITLE")\",\"duedate\":\"2026-12-24T09:00:00+00:00\"}"
	expect_200 'POST /api/cards (rich card)'
	SEED_CARD="$(created_id)"

	# The description is a separate write on purpose: card create does not take
	# one (CardController::create), and board reads return summaries only - the
	# body is loaded when the card is opened. So this is the only way to get a
	# non-empty description column into the seed at all.
	api PATCH "/api/cards/${SEED_CARD}" "{\"description\":\"$(json_str "$CARD_BODY")\"}"
	expect_200 'PATCH /api/cards/{id} (description)'

	api POST /api/cards "{\"stackId\":${SEED_STACK},\"title\":\"Recurrence template\"}"
	expect_200 'POST /api/cards (template)'
	SEED_TEMPLATE="$(created_id)"

	api POST /api/cards "{\"stackId\":${SEED_STACK2},\"title\":\"Seeded related card\"}"
	expect_200 'POST /api/cards (related)'
	SEED_CARD2="$(created_id)"

	api PUT "/api/cards/${SEED_CARD}/labels/${SEED_LABEL}"
	expect_200 'PUT card label'

	api PUT "/api/cards/${SEED_CARD}/assignees/admin"
	expect_200 'PUT card assignee'

	api POST "/api/cards/${SEED_CARD}/comments" "{\"body\":\"$(json_str "$COMMENT_BODY")\"}"
	expect_200 'POST comment'
	SEED_COMMENT="$(created_id)"

	# Threaded: the reply hangs off the comment above. GET .../comments is the
	# exact read that a bad comments migration turns into a 500.
	api POST "/api/cards/${SEED_CARD}/comments" \
		"{\"body\":\"$(json_str "$REPLY_BODY")\",\"parentCommentId\":${SEED_COMMENT}}"
	expect_200 'POST threaded reply'
	SEED_REPLY="$(created_id)"

	# Emoji reaction (U+1F44D), percent-encoded in the path.
	api PUT "/api/comments/${SEED_COMMENT}/reactions/%F0%9F%91%8D"
	expect_200 'PUT comment reaction'

	api POST "/api/cards/${SEED_CARD}/checklist" '{"title":"Seeded checklist item"}'
	expect_200 'POST checklist item'
	SEED_CHECKLIST="$(created_id)"

	UPLOAD_FILE="$(mktemp)"
	printf '%s\n' "$ATTACHMENT_TEXT" > "$UPLOAD_FILE"
	API_STATUS="$(curl -s -o "$API_BODY" -w '%{http_code}' \
		-u "$ADMIN_AUTH" -H 'OCS-APIRequest: true' \
		-F "file=@${UPLOAD_FILE};filename=seeded-note.txt;type=text/plain" \
		"$BASE_URL/apps/kanso/api/cards/${SEED_CARD}/attachments")"
	expect_200 'POST attachment'
	SEED_ATTACHMENT="$(created_id)"

	api POST "/api/cards/${SEED_CARD}/relations" \
		"{\"otherCardId\":${SEED_CARD2},\"kind\":\"relates\"}"
	expect_200 'POST card relation'

	api POST "/api/cards/${SEED_CARD}/time-entries" '{"seconds":5400,"note":"seeded work"}'
	expect_200 'POST time entry'

	api POST "/api/boards/${SEED_BOARD}/recur-rules" \
		"{\"templateCardId\":${SEED_TEMPLATE},\"targetStackId\":${SEED_STACK},\"mode\":0,\"rrule\":\"FREQ=WEEKLY;BYDAY=MO\",\"duedatePolicy\":0,\"duedateOffsetSeconds\":0,\"skipWhileOpen\":true}"
	expect_200 'POST recurrence rule'
	SEED_RULE="$(created_id)"

	backdate_changes

	cat > "$STATE" <<-STATEEOF
		SEED_BOARD=${SEED_BOARD}
		SEED_STACK=${SEED_STACK}
		SEED_STACK2=${SEED_STACK2}
		SEED_LABEL=${SEED_LABEL}
		SEED_CARD=${SEED_CARD}
		SEED_CARD2=${SEED_CARD2}
		SEED_TEMPLATE=${SEED_TEMPLATE}
		SEED_COMMENT=${SEED_COMMENT}
		SEED_REPLY=${SEED_REPLY}
		SEED_CHECKLIST=${SEED_CHECKLIST}
		SEED_ATTACHMENT=${SEED_ATTACHMENT}
		SEED_RULE=${SEED_RULE}
	STATEEOF

	echo "SEEDED: board=${SEED_BOARD} cards=${SEED_CARD},${SEED_TEMPLATE},${SEED_CARD2}"
	echo "        comments=${SEED_COMMENT}(+reply ${SEED_REPLY}) attachment=${SEED_ATTACHMENT} rule=${SEED_RULE}"
	echo "        change-log rows for this board aged ${BACKDATE_DAYS} days"
	echo "        ids written to $(pwd)/${STATE}"
}

# ---------------------------------------------------------------------------
# verify
# ---------------------------------------------------------------------------
do_verify() {
	[ -f "$STATE" ] || fail "no state file at $(pwd)/${STATE} - run './seed.sh seed' first"
	# shellcheck disable=SC1090
	. "./$STATE"

	# The board list - the first screen a user sees after an update.
	api GET /api/boards
	expect_200 'GET /api/boards'
	grep -q "\"id\":${SEED_BOARD}[,}]" "$API_BODY" \
		|| fail "the seeded board ${SEED_BOARD} is missing from GET /api/boards"
	echo "  ok: boards list"

	# The board itself, with its stacks and card summaries.
	api GET "/api/boards/${SEED_BOARD}"
	expect_200 'GET /api/boards/{id}'
	grep -q "\"id\":${SEED_STACK}[,}]" "$API_BODY" || fail "stack ${SEED_STACK} missing from the board payload"
	grep -q "\"id\":${SEED_CARD}[,}]" "$API_BODY" || fail "card ${SEED_CARD} missing from the board payload"
	echo "  ok: board loads with its stacks and cards"

	# Opening the card - descriptions load here, not in the board payload.
	api GET "/api/cards/${SEED_CARD}"
	expect_200 'GET /api/cards/{id}'
	grep -q 'Pre-existing description' "$API_BODY" \
		|| fail "the card description did not survive: $(head -c 800 "$API_BODY")"
	grep -q "\"labelIds\":\[${SEED_LABEL}\]" "$API_BODY" \
		|| fail "the label assignment did not survive: $(head -c 800 "$API_BODY")"
	grep -q '"assigneeIds"' "$API_BODY" || fail 'the card payload has no assigneeIds block'
	echo "  ok: card opens with description, label and assignee"

	# The read that a broken comments migration turns into a 500.
	api GET "/api/cards/${SEED_CARD}/comments"
	expect_200 'GET /api/cards/{id}/comments'
	grep -q "\"id\":${SEED_COMMENT}[,}]" "$API_BODY" || fail "comment ${SEED_COMMENT} is gone"
	grep -q "\"parentCommentId\":${SEED_COMMENT}[,}]" "$API_BODY" \
		|| fail "the threaded reply lost its parent: $(head -c 800 "$API_BODY")"
	# Nextcloud's JSONResponse escapes non-ASCII as \uXXXX, so the escaped form is
	# what actually comes back today; the raw form is accepted too so this does
	# not go red if that ever changes. grep -F: these are literal strings,
	# backslashes and all, not patterns.
	BS='\'
	grep -qF "${BS}u00fcn${BS}u00efcode" "$API_BODY" || grep -qF 'ünïcode' "$API_BODY" \
		|| fail "the unicode in the comment bodies did not survive: $(head -c 800 "$API_BODY")"
	grep -qF "${BS}ud83d${BS}udc4d" "$API_BODY" || grep -qF '👍' "$API_BODY" \
		|| fail 'the comment reaction did not survive'
	echo "  ok: comments list, thread, unicode and reaction"

	api GET "/api/cards/${SEED_CARD}/checklist"
	expect_200 'GET checklist'
	grep -q 'Seeded checklist item' "$API_BODY" || fail 'the checklist item is gone'
	echo "  ok: checklist"

	api GET "/api/cards/${SEED_CARD}/attachments"
	expect_200 'GET attachments'
	grep -q 'seeded-note.txt' "$API_BODY" || fail 'the attachment is gone'
	# And its BYTES, not just its row.
	DL="$(curl -s -u "$ADMIN_AUTH" -H 'OCS-APIRequest: true' \
		"$BASE_URL/apps/kanso/api/cards/${SEED_CARD}/attachments/${SEED_ATTACHMENT}")"
	case "$DL" in
		*"$ATTACHMENT_TEXT"*) : ;;
		*) fail "the attachment bytes did not survive: $(printf '%s' "$DL" | head -c 300)" ;;
	esac
	echo "  ok: attachment row and bytes"

	api GET "/api/cards/${SEED_CARD}/relations"
	expect_200 'GET relations'
	# The payload buckets edges by kind; each entry carries the OTHER card's id
	# under "cardId" (its own "id" is the relation row's).
	grep -q "\"cardId\":${SEED_CARD2}[,}]" "$API_BODY" \
		|| fail "the card relation is gone: $(head -c 500 "$API_BODY")"
	echo "  ok: card relation"

	api GET "/api/cards/${SEED_CARD}/time-entries"
	expect_200 'GET time entries'
	grep -q '5400' "$API_BODY" || fail 'the time entry is gone'
	echo "  ok: time entry"

	api GET "/api/boards/${SEED_BOARD}/recur-rules"
	expect_200 'GET recurrence rules'
	grep -q "\"id\":${SEED_RULE}[,}]" "$API_BODY" || fail "recurrence rule ${SEED_RULE} is gone"
	echo "  ok: recurrence rule"

	# The backdated change log: both the per-card activity feed and the delta-sync
	# endpoint read kanso_changes, and the rows here are 8 days old.
	api GET "/api/cards/${SEED_CARD}/activity"
	expect_200 'GET card activity'
	NEWEST="$(grep -o '"timestamp":[0-9]*' "$API_BODY" | cut -d: -f2 | sort -n | tail -1)"
	[ -n "${NEWEST:-}" ] || fail "the card activity feed is empty: $(head -c 500 "$API_BODY")"
	# And the rows really are the aged ones the seeder wrote, not fresh entries
	# some later write produced - so this assertion cannot pass vacuously.
	CUTOFF=$(( $(date +%s) - (BACKDATE_DAYS - 1) * 86400 ))
	[ "$NEWEST" -lt "$CUTOFF" ] \
		|| fail "the activity rows are not backdated (newest=${NEWEST}, expected < ${CUTOFF})"
	echo "  ok: card activity, still ${BACKDATE_DAYS} days old"

	api GET "/api/boards/${SEED_BOARD}/changes?since=0"
	expect_200 'GET board changes (delta sync)'
	echo "  ok: delta sync"

	echo "VERIFY OK: every seeded entity still reads back."
}

case "$MODE" in
	seed)   do_seed ;;
	verify) do_verify ;;
	*) echo "usage: $0 [seed|verify]" >&2; exit 2 ;;
esac
