<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Creating cards by email

Point a board at a mailbox and every message that arrives there becomes a card:
the subject becomes the title, the body becomes the description.

Kanso does not receive mail itself — Nextcloud has no inbound mail server. It
**polls a mailbox you already have** over IMAP, every five minutes, using
Nextcloud's background jobs.

## Before you start

You need a mailbox that exists **only for this board** — `cards@example.com`,
`support@example.com`, a `+board` alias, whatever your provider gives you. Two
reasons it should be dedicated:

- Every message in it becomes a card, including ones you did not mean to file.
- Kanso needs the account's password. Give it a mailbox whose password you are
  willing to store, not your personal account. If your provider supports app
  passwords, use one.

Your IMAP server must accept TLS — either implicit TLS (usually port 993) or
STARTTLS (usually port 143). Kanso will not connect in the clear, because that
would put the mailbox password on the wire.

## Setting it up

1. Open the board → **Board settings** → **Automation** → **Email intake**.
   You need *manage* permission on the board.
2. Fill in the IMAP server, port and encryption.
3. Enter the mailbox account and its password.
4. Choose the folder (`INBOX` unless you filter into a subfolder) and the
   **column** new cards should land in.
5. Click **Test connection**. This connects and signs in immediately, so a typo
   shows up now rather than in five minutes.
6. Tick **Check this mailbox automatically** and **Save**.

The first check picks up whatever is already sitting in the mailbox, then each
run handles what has arrived since.

### Who is allowed to send

**Leave the allowlist empty and anyone who knows the address can create cards.**
That is sometimes exactly what you want — a public `support@` address — but it
is worth deciding deliberately.

To restrict it, put one entry per line:

```
jacek@example.com
@example.com
```

A bare address matches exactly; an `@domain` entry matches anyone at that
domain.

> **The allowlist is a filter, not proof.** A sender address is trivial to
> forge. Anyone who knows an allowed address can put it in a `From:` header.
> To make it mean something, turn on **Require verified senders** below.

### Require verified senders (recommended)

With this on, a message is only carded if your mail server verified that it
really came from the domain its `From:` claims — a DMARC pass.

This works only if mail reaches the mailbox through a mail server that performs
that check and records it in an `Authentication-Results:` header. Most hosted
providers (Google Workspace, Microsoft 365, Fastmail, Mailcow, mailu) and any
Postfix with OpenDMARC or Rspamd do. If yours does not, every message will be
rejected — use **Test connection**, send yourself a message, and check the
status line under the form.

Kanso only trusts the *topmost* such header, the one added by the server that
delivered the message. Headers below it can be forged by the sender.

### Limits

Two caps stop a board being buried, set per mailbox (`0` uses the default):

| | Default | What happens when it is reached |
|---|---|---|
| Cards per day, whole mailbox | 200 | Polling stops for the day. The remaining mail **stays on the server** and is picked up tomorrow — nothing is lost. |
| Cards per day, one sender | 50 | That sender's further messages are skipped. Everyone else still gets through. |

## What gets skipped

Some mail is never carded, whatever the allowlist says:

- **Automatic mail** — out-of-office replies, bounces, mailing-list posts and
  anything marked `Auto-Submitted` or `Precedence: bulk`. This is not a
  nicety: a card can raise a notification email, and if an auto-reply to that
  became a card you would have an endless loop.
- **Spam**, when your mail server has already flagged it.
- **Messages already turned into a card**, even if the server delivers them
  twice.

When a run skips anything, the Email intake panel says so — "Last run skipped 3
messages from senders not on the allowlist". If someone reports that they mailed
the address and nothing happened, look there first.

## What a card looks like

- **Title** — the subject. A message with no subject is titled after its sender.
- **Description** — a line recording who the mail claimed to be from and whether
  that was verified, then the message text. HTML mail is converted to plain
  text; when a message has both, the plain-text version is used.
- **Attachments are not imported.** Their file names are listed at the end of
  the description so you know something was left behind. Fetch them from the
  mailbox if you need them.
- The card is created as the **board owner**, not as the sender — Kanso will not
  attribute a card to a Nextcloud user on the strength of an email header.
- `@name` in an email does **not** notify that person. Only mentions typed by a
  real user in Kanso do.

## Administration

### Mail servers on your local network

By default Kanso refuses to connect to private, loopback or link-local
addresses, so that configuring a mailbox cannot be used to probe your internal
network. If your IMAP server genuinely is on the LAN:

```
occ config:app:set kanso mail_intake_allow_private_hosts --value yes
```

This is instance-wide and admin-only, on purpose. Loopback stays blocked even
with it on.

### Checking it runs

Intake runs from Nextcloud's background jobs, so cron must be working
(**Administration settings → Basic settings → Background jobs**; AJAX cron is
too unreliable for this). To poll immediately:

```
occ background-job:execute --force "OCA\\Kanso\\Cron\\PollMailIntake"
```

Failures are recorded per board — visible in the Email intake panel and logged
with the `kanso` app id.

### Turning it off

Untick **Check this mailbox automatically** to pause, or **Remove mailbox** to
delete the settings and the stored password entirely.

## Security

The threat model, the defences and the residual risks are written up in
[email-intake-security.md](email-intake-security.md). The short version: the
password is encrypted at rest and never sent back to the browser, connections
are TLS-only and cannot be aimed at your internal network, and content arriving
by mail cannot trigger notifications or run script in a card.

## Limitations

- Polling is every five minutes, so a card can take that long to appear.
- Replying to an intake card by email creates a **new card**; it does not add a
  comment.
- One mailbox per board.
- Attachments are named, not stored.
