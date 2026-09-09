<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Email intake: threat model

Companion to [email-intake.md](email-intake.md) (how to set it up). This one
records what the feature defends against and why each defence is shaped the way
it is. Written for #117.

## The trust change this feature makes

Every other way a card gets created requires a Nextcloud session or a per-board
HMAC secret. Email intake is the first path where **an unauthenticated stranger
causes a write**, and the content they supply lands in a card body that other
people read. Two facts drive everything below:

1. **`From:` is not an identity.** SMTP lets anyone claim any address, and by
   the time a message reaches IMAP the envelope sender is gone. The allowlist is
   a *filter*; `requireAuth` is the only thing that authenticates.
2. **Anything the server does with a card's text** — notify, subscribe, link,
   render — is now reachable by that stranger.

## Defences

Every row has at least one test; the ones marked ▲ were additionally verified by
mutation (break the code, watch the named test fail).

### Content and privilege

| Threat | Defence |
|---|---|
| **`@mention` notification spoofing** — a description write re-parses `@name` and sends real notifications + auto-subscriptions attributed to the writer. Intake writes a stranger's text *as the board owner*, so `@alice` in an email would ping Alice with the owner's name on it. | `CardService::update(..., notifyMentions: false)`. The flag exists solely for this caller. ▲ |
| Card attributed to a forged sender | Cards are always created as the board **owner**, never the NC user whose address `From:` claims. The claimed sender is plain text in the body. |
| Reader cannot tell a card came from outside | Every intake card opens with a provenance line naming the sender and stating explicitly whether the domain was verified — `linkify` makes URLs in stranger mail clickable, so "who sent this" has to be on the card. |
| Stored XSS via message body | `MarkdownIt({html: false})` + DOMPurify in the renderer; HTML mail is flattened to text before storage. |
| Bidi/zero-width spoofing of a card title (`invoice\u{202E}gnp.exe`) | Invisible and bidi-control characters stripped from every header-derived string. |

### Network

| Threat | Defence |
|---|---|
| **SSRF** — `host`/`port` are free text from any board *manager*, so intake could dial `127.0.0.1`, `169.254.169.254` or any internal host, with `testConnection` reporting the outcome (a working port scanner). | `MailHostGuard` resolves the name and rejects loopback, private, link-local, unique-local, unspecified and IPv4-mapped equivalents. Admin opt-in (`mail_intake_allow_private_hosts`) permits LAN servers but **never** loopback. |
| DNS rebinding around that check | The guard returns the resolved IP and the socket dials **that**, not the name — closing the window between check and connect. The hostname travels separately as `peer_name` so TLS still validates against it. |
| Credential sniffed in transit | TLS mandatory (no cleartext mode); `verify_peer` + `verify_peer_name`, no self-signed. |
| Password sent before the TLS upgrade | STARTTLS is the only command written before `enableCrypto()`; asserted by test. |
| IMAP command injection via config fields | CR/LF/NUL rejected at the config boundary *and* in the client; quoted-string escaping. ▲ |

### Credential handling

| Threat | Defence |
|---|---|
| Credential readable at rest | `ICrypto`-encrypted (NC's server-secret cipher). |
| Credential leaking outward | Absent from `jsonSerialize` (`hasPassword` instead); login-failure text replaced wholesale, since servers echo the command back; the configured username is scrubbed out of any stored error. |
| Credential outliving its mailbox | Deleting the config removes the row and its dedupe keys. |
| Undecryptable credential after a server-secret change | Reported as "re-enter it" rather than a generic failure. |

### Abuse and spam

| Threat | Defence |
|---|---|
| **Auto-responder / bounce loops** — a card raises a notification email, an out-of-office answers it, that answer becomes a card, forever. | Messages carrying `Auto-Submitted`, `Precedence: bulk/list/junk`, `List-Id`/`List-Unsubscribe`/`List-Post`, `X-Auto-Response-Suppress`, a null `Return-Path`, or a `multipart/report` content type are never carded. |
| Spam becoming cards | The upstream filter's verdict is honoured (`X-Spam-Flag`, `X-Spam-Status`, `X-Spamd-Result`). Kanso does not re-implement spam detection. |
| Forged sender passing the allowlist | `requireAuth` demands a DMARC pass from the receiving MTA, cross-checked against the `From` domain (with subdomain alignment). SPF-only or DKIM-only does **not** satisfy it: SPF authenticates the envelope, not the visible `From`. |
| A forged `Authentication-Results` header | Only the **topmost** occurrence is read — hops prepend, so it is the one our own MTA wrote. Anything below is the sender's to invent. ▲ |
| Board flooded from an open address | Per-mailbox daily cap (default 200) *and* per-sender daily cap (default 50), both overridable. Hitting the mailbox cap stops the run **without advancing the watermark**, so the excess waits on the server rather than being destroyed. A per-sender cap skips-and-advances instead, so one flooder cannot wedge the mailbox for everyone. |
| Duplicate cards from re-delivery | `kanso_mail_seen` keyed on the Message-ID hash, with a UNIQUE index doing the work — the failing insert *is* the check, so it is race-free. Messages with no Message-ID fall back to a content hash. |
| A user cannot tell why nothing happened | A healthy run records what it declined ("Last run skipped 3 messages from senders not on the allowlist") in the field the config screen shows. |

### Resource exhaustion

| Threat | Defence |
|---|---|
| Memory exhaustion from a huge message | 5 MiB ceiling; oversized messages are drained (keeping the stream in sync) and skipped. |
| Parser DoS via nesting/fan-out | Depth cap 10, part cap 200. |
| Regex CPU burn on a multi-megabyte header | Header values clamped to 2 KB before parsing. |
| Cron overrun from many unreachable servers | 30 s per-connection timeout plus a 120 s whole-run budget; deferred mailboxes are logged and picked up next tick. |
| Duplicate-key table growing forever | 60-day retention, pruned on roughly one run a day. |
| One board's failure stopping all intake | Per-mailbox catch; the error is recorded on that board's row. ▲ |

### Correctness that protects the mailbox

| Threat | Defence |
|---|---|
| Poller marking a human's mail as read | `EXAMINE` (read-only) + `BODY.PEEK[]` — no flag writes at all. |
| Duplicate cards from the `n:*` range quirk (`UID 9:*` returns UID 4 on a mailbox ending at 4) | Client-side filter. ▲ |
| Replaying the batch after a crash | The watermark advances per message and is persisted on the failure path. ▲ |
| Re-carding all history after a mailbox is restored | A UIDVALIDITY change resumes from `UIDNEXT` ("card what arrives from now on"). A *first* poll still ingests what is already there, which is what someone setting up a new address expects. |
| Mail lost while the target column is deleted | The stack is checked before connecting; the watermark does not advance, so the mail waits. |
| Config changed with no trace | Create/update/delete are logged with actor, board, host, account and mailbox — never the password. |

## Residual risks

These are accepted, not solved. They are properties of the feature as specified.

- **An open intake address is open by design.** With an empty allowlist, anyone
  who learns the address can create cards up to the daily caps. That is the
  feature the issue asked for; the caps bound it and the UI says so plainly.
- **`requireAuth` is only as good as the deployment.** It trusts the topmost
  `Authentication-Results`, which means it trusts that mail reaches the mailbox
  through an MTA that actually authenticates. A mailbox fed directly by a third
  party would have no such header — or a sender-supplied one. Kanso cannot
  detect this, so the option is off by default and the admin doc states the
  requirement.
- **A board manager still chooses the mail server.** The SSRF guard restricts
  *where*, not *whether*. A manager can still point intake at any public host.
- **Content is unverified text.** A phishing mail that clears the filters still
  becomes a card with a clickable link. The provenance line is the mitigation;
  it is not a content filter.

## Deliberately out of scope

- **Storing attachments.** Intake names attached files in the body and stores
  none of them. Storing them adds malware distribution, quota exhaustion and
  content-type confusion to the threat surface, and belongs in its own change
  with its own review.
- **Replies as comments.** Threading a reply onto the original card means
  trusting `In-Reply-To`, which is attacker-settable — it would let a stranger
  append to a card of their choosing. Needs a real design first.
