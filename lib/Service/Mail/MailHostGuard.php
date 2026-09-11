<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Mail;

use OCP\IConfig;

/**
 * Stops email intake from being an SSRF primitive.
 *
 * The mail server host is free text supplied by any board MANAGER - not an
 * admin. Without this, "configure a mailbox" is really "make the Nextcloud
 * server open a TCP connection to any address you name, and tell you how it
 * went": `testConnection` reports connect-refused, timeout and TLS failure
 * distinguishably, which is a working port scanner for the server's internal
 * network, cloud metadata endpoints included.
 *
 * The guard resolves the name HERE and returns the address to connect to, and
 * that return value is what makes it sound. Validating the hostname and then
 * letting the socket resolve it again is the classic DNS-rebinding hole: the
 * attacker's resolver answers with a public address for our check and
 * 169.254.169.254 microseconds later for the connection. Resolving once and
 * connecting to the resolved IP closes that window, and the original hostname
 * is carried separately so TLS still validates against the certificate's name.
 *
 * Admins whose IMAP server genuinely IS on the LAN can allow private ranges
 * with `occ config:app:set kanso mail_intake_allow_private_hosts --value yes`.
 * It is deliberately an admin-only, instance-wide switch: the whole point is
 * that a board manager must not be able to reach the internal network by
 * filling in a form.
 */
class MailHostGuard {
	public const APP_ID = 'kanso';
	public const KEY_ALLOW_PRIVATE = 'mail_intake_allow_private_hosts';

	/**
	 * Ranges the server must never dial, whatever the admin has opted into.
	 *
	 * These are matched EXPLICITLY rather than through `filter_var`'s
	 * `FILTER_FLAG_NO_RES_RANGE`, because that flag's answer depends on the PHP
	 * version: PHP 8.2 rejects `2001:db8::1` as reserved and PHP 8.5 accepts it.
	 * A security control whose verdict changes when the runtime is upgraded is
	 * not a control, so the list is spelled out here and compared bit by bit.
	 *
	 * Covers loopback, the unspecified address, link-local (which is where
	 * 169.254.169.254, the cloud metadata endpoint, lives), carrier-grade NAT,
	 * IETF protocol assignments, benchmarking, multicast and the reserved
	 * top block.
	 */
	private const ALWAYS_BLOCKED = [
		// IPv4
		'0.0.0.0/8',
		'127.0.0.0/8',
		'100.64.0.0/10',
		'169.254.0.0/16',
		'192.0.0.0/24',
		'198.18.0.0/15',
		'224.0.0.0/4',
		'240.0.0.0/4',
		// IPv6
		'::/128',
		'::1/128',
		'fe80::/10',
		'ff00::/8',
	];

	/**
	 * Ranges an admin can opt into with {@see KEY_ALLOW_PRIVATE} - the "our IMAP
	 * server is on the LAN" case, and nothing beyond it.
	 */
	private const PRIVATE_RANGES = [
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'fc00::/7',
	];

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * Resolves `$host` and returns the single IP literal the connection must be
	 * made to.
	 *
	 * @throws ImapException if the name does not resolve, or resolves anywhere
	 *                       the server should not be dialling
	 */
	public function resolve(string $host): string {
		$host = trim($host);
		if ($host === '') {
			throw new ImapException('Mail server host is empty');
		}

		$allowPrivate = $this->allowsPrivateHosts();

		// An IP literal needs no resolution - just the same verdict.
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			$this->assertAddressAllowed($host, $allowPrivate);
			return $host;
		}

		$addresses = $this->lookup($host);
		if ($addresses === []) {
			throw new ImapException('Cannot resolve mail server host ' . $host);
		}

		// EVERY answer must be acceptable, not just the one we pick. A name that
		// resolves to both a public and an internal address would otherwise be a
		// coin flip the attacker gets to re-toss on every poll.
		foreach ($addresses as $address) {
			$this->assertAddressAllowed($address, $allowPrivate);
		}

		return $addresses[0];
	}

	/**
	 * Whether the admin has opted the instance into private-range mail servers.
	 */
	public function allowsPrivateHosts(): bool {
		return $this->config->getAppValue(self::APP_ID, self::KEY_ALLOW_PRIVATE, 'no') === 'yes';
	}

	/**
	 * @return string[] every A and AAAA answer for the name
	 */
	private function lookup(string $host): array {
		$addresses = [];

		// gethostbynamel covers IPv4 and is the cheap path; dns_get_record adds
		// AAAA, which must be checked too or an internal target reachable only
		// over IPv6 walks straight through.
		$v4 = @gethostbynamel($host);
		if (is_array($v4)) {
			$addresses = $v4;
		}

		$records = @dns_get_record($host, DNS_AAAA);
		if (is_array($records)) {
			foreach ($records as $record) {
				if (isset($record['ipv6']) && is_string($record['ipv6'])) {
					$addresses[] = $record['ipv6'];
				}
			}
		}

		return array_values(array_unique($addresses));
	}

	/**
	 * @throws ImapException if the address is one the server must not dial
	 */
	private function assertAddressAllowed(string $address, bool $allowPrivate): void {
		$packed = @inet_pton($address);
		if ($packed === false) {
			throw new ImapException('Mail server host resolved to an invalid address');
		}

		// An IPv4-mapped IPv6 address (::ffff:127.0.0.1) is an IPv4 address in a
		// costume; unwrap it so it is judged by the IPv4 rules rather than
		// sliding past them as "some IPv6 address".
		$packed = $this->unwrapMappedV4($packed);

		foreach (self::ALWAYS_BLOCKED as $range) {
			if ($this->inRange($packed, $range)) {
				throw new ImapException(
					'Mail server host resolves to a restricted address (' . $address . ')'
				);
			}
		}

		if ($allowPrivate) {
			// The opt-in covers RFC 1918 / ULA only. Loopback, link-local and the
			// rest stayed blocked above: there is no legitimate "my IMAP server is
			// the Nextcloud process itself", and loopback is where admin panels bind.
			return;
		}

		foreach (self::PRIVATE_RANGES as $range) {
			if ($this->inRange($packed, $range)) {
				throw new ImapException(
					'Mail server host resolves to a private or reserved address ('
					. $address
					. '). An administrator can allow this with the mail_intake_allow_private_hosts app setting.'
				);
			}
		}
	}

	/**
	 * `::ffff:a.b.c.d` -> the packed 4-byte form of `a.b.c.d`. Anything else is
	 * returned unchanged.
	 */
	private function unwrapMappedV4(string $packed): string {
		if (strlen($packed) !== 16) {
			return $packed;
		}
		// 80 zero bits then 16 one bits is the IPv4-mapped prefix.
		if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
			return substr($packed, 12, 4);
		}
		return $packed;
	}

	/**
	 * Whether a packed address falls inside `<address>/<prefix>`. Compares whole
	 * bytes then the remaining bits, so it is exact rather than a string prefix
	 * match on the textual form (which is what makes '169.254.' style checks
	 * miss things like '::ffff:169.254.169.254').
	 */
	private function inRange(string $packed, string $cidr): bool {
		$parts = explode('/', $cidr, 2);
		$networkPacked = @inet_pton($parts[0]);
		if ($networkPacked === false) {
			return false;
		}
		// An IPv4 address is never inside an IPv6 range, and vice versa.
		if (strlen($networkPacked) !== strlen($packed)) {
			return false;
		}

		// A bare address with no prefix means that single host, not "everything":
		// the entries above all carry a prefix, and defaulting the other way
		// would turn a typo into a rule that matches the whole internet.
		$bits = isset($parts[1]) ? (int)$parts[1] : strlen($networkPacked) * 8;
		$wholeBytes = intdiv($bits, 8);
		$remainingBits = $bits % 8;

		if ($wholeBytes > 0 && strncmp($packed, $networkPacked, $wholeBytes) !== 0) {
			return false;
		}
		if ($remainingBits === 0) {
			return true;
		}

		$mask = 0xFF << (8 - $remainingBits) & 0xFF;
		return (ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
	}
}
