# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Kanso MCP server — runs the streamable-http transport on :7654.
#   docker build -t kanso-mcp ./mcp
#   docker run --rm -p 127.0.0.1:7654:7654 --env-file mcp/.env kanso-mcp
# Credentials come from the environment (--env-file / -e), never baked into the image.

FROM python:3.12-slim

WORKDIR /app
COPY pyproject.toml README.md ./
COPY kanso_mcp ./kanso_mcp
RUN pip install --no-cache-dir .

# Listen on all interfaces INSIDE the container; restrict real exposure via the
# host port mapping (publish to 127.0.0.1 — see README / compose).
ENV MCP_HOST=0.0.0.0 \
    MCP_PORT=7654
EXPOSE 7654

ENTRYPOINT ["kanso-mcp"]
CMD ["--http"]
