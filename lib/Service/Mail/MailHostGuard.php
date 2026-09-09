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
		if (filter_var($address, FILTER_VALIDATE_IP) === false) {
			throw new ImapException('Mail server host resolved to an invalid address');
		}

		if ($allowPrivate) {
			// Even with the opt-in, loopback stays blocked: there is no legitimate
			// "my IMAP server is the Nextcloud process itself", and it is the most
			// useful address to an attacker (admin panels bound to 127.0.0.1).
			if ($this->isLoopback($address)) {
				throw new ImapException('Mail server host resolves to a loopback address');
			}
			return;
		}

		// NO_PRIV_RANGE covers RFC 1918 and fc00::/7; NO_RES_RANGE covers
		// loopback, link-local, 0.0.0.0/8 and the reserved blocks.
		$public = filter_var(
			$address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
		);
		if ($public === false) {
			throw new ImapException(
				'Mail server host resolves to a private or reserved address ('
				. $address
				. '). An administrator can allow this with the mail_intake_allow_private_hosts app setting.'
			);
		}

		// Belt and braces for the ranges PHP's filter has historically been
		// inconsistent about across versions, and the ones that matter most:
		// the cloud metadata address and IPv4-mapped IPv6.
		if ($this->isLoopback($address) || $this->isLinkLocal($address) || $this->isMappedV4($address)) {
			throw new ImapException('Mail server host resolves to a restricted address (' . $address . ')');
		}
	}

	private function isLoopback(string $address): bool {
		if (str_starts_with($address, '127.')) {
			return true;
		}
		$packed = @inet_pton($address);
		return $packed !== false && $packed === @inet_pton('::1');
	}

	private function isLinkLocal(string $address): bool {
		// 169.254.0.0/16 - includes 169.254.169.254, the cloud metadata endpoint.
		if (str_starts_with($address, '169.254.')) {
			return true;
		}
		// fe80::/10
		$lower = strtolower($address);
		return str_starts_with($lower, 'fe8')
			|| str_starts_with($lower, 'fe9')
			|| str_starts_with($lower, 'fea')
			|| str_starts_with($lower, 'feb');
	}

	/**
	 * ::ffff:127.0.0.1 and friends - an IPv4 address wearing an IPv6 costume,
	 * which some validators wave through.
	 */
	private function isMappedV4(string $address): bool {
		$lower = strtolower($address);
		if (!str_contains($lower, ':') || !str_contains($lower, '.')) {
			return false;
		}
		$lastColon = strrpos($lower, ':');
		if ($lastColon === false) {
			return false;
		}
		$tail = substr($lower, $lastColon + 1);
		if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
			return false;
		}
		// Judge the embedded IPv4 on its own merits.
		return filter_var(
			$tail,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
		) === false;
	}
}
