<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Email intake: threat model and abuse plan

Working document for #117 (create cards by email). It records what the current
implementation already defends against, what it does **not**, and the order the
gaps should be closed in.

## The trust change this feature makes

Every other way a card gets created requires a Nextcloud session or a per-board
HMAC secret. Email intake is the first path where **an unauthenticated stranger
causes a write**, and the content they supply lands in a card body that other
people read. Two consequences drive everything below:

1. `From:` is not an identity. SMTP lets anyone claim any address, and by the
   time a message reaches IMAP the envelope sender is gone. The allowlist is a
   *filter*, not authentication.
2. Anything the server does with a card's text — notify, subscribe, link,
   render — is now reachable by that stranger.

**Status: not shippable yet.** Intake is default-off with no UI to turn it on,
which is the only reason the P0s below are not live issues. Nothing here should
be exposed to users until P0 is done.

## Already handled

Each of these has a test unless noted.

| # | Threat | Defence | Where |
|---|---|---|---|
| 1 | Credential sniffed in transit | TLS mandatory (no cleartext mode); `verify_peer` + `verify_peer_name`, no self-signed | `StreamImapTransport::open` |
| 2 | STARTTLS sent after the password | STARTTLS is the only command written before the upgrade | `ImapClient::connect`, test asserts `writesBeforeCrypto === 1` |
| 3 | Credential readable at rest | `ICrypto`-encrypted (NC's server-secret cipher) | `MailIntakeService::saveConfig` |
| 4 | Credential leaking outward | Omitted from `jsonSerialize` (`hasPassword` instead); LOGIN failure text replaced wholesale, since servers echo the command back | `MailIntake`, `ImapClient::login` |
| 5 | IMAP command injection via config fields | CR/LF/NUL rejected at the config boundary *and* in the client; quoted-string escaping | `saveConfig`, `ImapClient::assertArgumentSafe` |
| 6 | Config changed by a non-manager | MANAGE asserted on every endpoint | `MailIntakeService`, 3 denial tests |
| 7 | Duplicate cards from re-delivery | UID watermark + UIDVALIDITY pairing; watermark persisted on the failure path | `poll()` |
| 8 | Duplicate cards from the `n:*` range quirk | Client-side filter (`UID 9:*` returns UID 4 on a mailbox that ends at 4) | `searchUidsAbove` |
| 9 | Poller marks a human's mail as read | `EXAMINE` (read-only) + `BODY.PEEK[]` | `ImapClient` |
| 10 | Memory exhaustion from a huge message | 5 MiB ceiling, drained to keep the stream in sync | `ImapClient::fetchMessage` |
| 11 | Parser DoS via nesting/fan-out | Depth cap 10, part cap 200 | `MimeParser` |
| 12 | Invalid UTF-8 failing the INSERT | Scrubbed on every body and header | `MimeParser::toUtf8` |
| 13 | One board's failure stopping all intake | Per-mailbox catch, error recorded on the row | `pollAll` |
| 14 | Stored XSS via message body | `MarkdownIt({html: false})` + DOMPurify; HTML mail is flattened to text before storage | `src/services/markdown.js`, `MimeParser::htmlToText` |

## P0 — must land before intake can be enabled

### 0.1 `@mention` notification spoofing (worst one)

`CardService::update` re-parses the description server-side and
`MentionService::handleMentions` sends real notifications and auto-subscribes
the named users. Intake writes an unauthenticated stranger's text into that
description, with `actorUid` = the **board owner**.

So `@alice @bob` in an email body produces genuine Nextcloud notifications that
read as though the board owner mentioned them — unauthenticated notification
spam with a spoofed actor, and a plausible phishing primitive ("the owner
mentioned you, click here").

*Fix:* intake-created content must not drive mentions. Either write the
description through a path that skips `handleMentions`, or neutralise `@` in
intake bodies before storage. The former is better — it keeps the body faithful.

### 0.2 SSRF via the configured host

`host`/`port` are free-text from any board **manager**, not just an admin. That
turns Kanso into a connect-anywhere probe: `127.0.0.1`, link-local metadata
endpoints, internal-only hosts. `testConnection` returns the error text
synchronously, which makes it a usable port scanner (open/refused/timeout are
distinguishable).

*Fix:* reject loopback / private / link-local / unique-local destinations by
default, resolved at connect time (not just on the config string, or DNS
rebinding walks around it). Add an admin-level allowlist for the legitimate
"our IMAP server is on the LAN" case.

### 0.3 Auto-responder and bounce loops

Nothing checks whether a message is itself automated. A card created by intake
can generate a Nextcloud notification email → an out-of-office replies → a new
card → and so on. A bounce loop does the same.

*Fix:* drop before carding when any of these is present — `Auto-Submitted:`
anything but `no`, `Precedence: bulk|list|junk`, `X-Auto-Response-Suppress`,
`List-Id`/`List-Unsubscribe`, or an empty `Return-Path: <>`. Cheap, and it is
the standard set.

### 0.4 Unbounded card creation from an open address

With an empty allowlist (the "public intake address" the issue asks for),
anyone who learns the address can create cards without limit. The only current
bound is 50 per poll, i.e. ~600/hour/mailbox — enough to bury a board.

*Fix:* a per-mailbox daily cap and a per-sender rate limit, both with the excess
left on the server rather than silently dropped, plus `lastError` reporting when
a cap engages so it is visible rather than mysterious.

## P1 — before it is documented as production-ready

- **Sender authentication.** We cannot check SPF/DKIM ourselves post-delivery,
  but the receiving MTA already did and recorded it in `Authentication-Results:`.
  Read that header (trusting only the topmost one, from our own MTA) and offer a
  "require passing DMARC" toggle. Without it, allowlist entries are trivially
  forged — which makes the allowlist feel like security while providing none.
- **Spam-header awareness.** Honour `X-Spam-Flag: YES` / `X-Spam-Status: Yes`
  from the upstream filter rather than re-implementing spam detection.
- **Deduplicate by `Message-ID`.** Protects against a mailbox re-delivering
  after a UIDVALIDITY reset, which currently re-cards the entire history.
- **UIDVALIDITY reset policy.** Today a renumbered mailbox restarts at UID 0 and
  re-cards everything it still holds. Starting from `UIDNEXT` instead ("card
  what arrives from now on") is almost certainly the behaviour people want, and
  is a one-line change — but it is a product decision, so it needs a call.
- **Link handling.** `linkify: true` makes every URL in a stranger's email a
  clickable link in the card. Consider marking intake-created cards visually as
  externally-sourced so a reader knows the content is untrusted.

## P2 — hardening

- **Header-length cap before regex.** `parseFrom`'s pattern runs over an
  unbounded header value (a single header can be ~5 MiB under the message cap);
  backtracking there is superlinear. Clamp header values to ~2 KB before
  decoding — no legitimate `From` is longer.
- **Unicode spoofing in titles.** Bidi overrides and confusables let a subject
  render deceptively in the card list. Strip bidi control characters.
- **Poll-time budget.** N mailboxes × a 30 s timeout can run one cron tick long.
  Add a whole-run wall-clock budget and resume next tick.
- **`lastError` exposure.** It can carry the server's text, which may include
  the username. Manager-only today, so low, but worth scrubbing.
- **Config-change audit.** Changing the mailbox is a security-relevant act with
  no activity entry.

## Deliberately out of scope

- **Storing attachments.** Intake names attached files in the body and stores
  none of them. Storing them adds malware distribution, quota exhaustion and
  content-type confusion to the threat surface, and should be its own piece of
  work with its own review.
- **Replies as comments.** Threading a reply onto the original card means
  trusting `In-Reply-To`, which is attacker-settable — it would let a stranger
  append to a chosen existing card. Needs a real design before it is built.
