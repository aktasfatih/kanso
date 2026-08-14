# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Configuration for the Kanso MCP server, read from the environment."""

from __future__ import annotations

import os
from dataclasses import dataclass


class ConfigError(RuntimeError):
    """Raised when required configuration is missing or invalid."""


def _truthy(value: str | None, default: bool) -> bool:
    if value is None or value == "":
        return default
    return value.strip().lower() not in {"0", "false", "no", "off"}


@dataclass(frozen=True)
class KansoConfig:
    """Connection settings for a Kanso / Nextcloud instance.

    Read from the environment:
      - NEXTCLOUD_HOST      base URL, e.g. https://cloud.example.com
      - NEXTCLOUD_USERNAME  Nextcloud user id
      - NEXTCLOUD_PASSWORD  an app password (Settings -> Security -> Devices & sessions)
      - VERIFY_SSL          optional; "false"/"0" to skip TLS verification (default true)
    """

    host: str
    username: str
    password: str
    verify_ssl: bool = True

    @property
    def api_base_url(self) -> str:
        """The Kanso app-framework API base (NOT the OCS endpoint)."""
        return f"{self.host.rstrip('/')}/index.php/apps/kanso/api"

    @classmethod
    def from_env(cls) -> "KansoConfig":
        host = os.environ.get("NEXTCLOUD_HOST", "").strip()
        username = os.environ.get("NEXTCLOUD_USERNAME", "").strip()
        password = os.environ.get("NEXTCLOUD_PASSWORD", "")

        missing = [
            name
            for name, value in (
                ("NEXTCLOUD_HOST", host),
                ("NEXTCLOUD_USERNAME", username),
                ("NEXTCLOUD_PASSWORD", password),
            )
            if not value
        ]
        if missing:
            raise ConfigError(
                "Missing required environment variable(s): "
                + ", ".join(missing)
                + ". Set NEXTCLOUD_HOST, NEXTCLOUD_USERNAME and NEXTCLOUD_PASSWORD "
                "(use a Nextcloud app password)."
            )

        return cls(
            host=host,
            username=username,
            password=password,
            verify_ssl=_truthy(os.environ.get("VERIFY_SSL"), default=True),
        )
