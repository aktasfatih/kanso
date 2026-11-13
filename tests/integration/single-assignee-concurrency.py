#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

import argparse
import json
import pathlib
import select
import subprocess
import time

parser = argparse.ArgumentParser(
    description="Single-assignee races on an explicitly selected disposable Nextcloud installation"
)
parser.add_argument("--container", required=True)
parser.add_argument("--config-dir", required=True)
parser.add_argument("--data-dir", required=True)
parser.add_argument("--driver", choices=["postgres", "mariadb", "mysql"], required=True)
parser.add_argument("--nextcloud-root", default="/var/www/html")
parser.add_argument(
    "--script",
    default="/var/www/html/custom_apps/kanso/tests/integration/single-assignee.php",
)
parser.add_argument(
    "--mysql-container",
    help="Disposable Oracle MySQL container for root-only lock observation without changing app grants",
)
parser.add_argument("--output", help="Optional JSON evidence path")
ARGS = parser.parse_args()


def command(profile, mode, spec):
    return [
        "docker",
        "exec",
        "-i",
        "-u",
        "www-data",
        "-e",
        "NEXTCLOUD_ROOT=" + ARGS.nextcloud_root,
        "-e",
        "NEXTCLOUD_CONFIG_DIR=" + ARGS.config_dir,
        "-e",
        "KANSO_SINGLE_EXPECTED_DATA_DIR=" + ARGS.data_dir,
        "-e",
        "KANSO_INTEGRATION_DISPOSABLE=1",
        ARGS.container,
        "php",
        ARGS.script,
        mode,
        json.dumps(spec),
    ]


def run(profile, mode, spec):
    result = subprocess.run(
        command(profile, mode, spec), capture_output=True, text=True, timeout=25
    )
    if result.returncode:
        raise RuntimeError(mode + ": " + result.stderr)
    return json.loads(result.stdout)


def receive(worker):
    if not select.select([worker.stdout], [], [], 20)[0]:
        raise RuntimeError("Worker event timeout")
    line = worker.stdout.readline()
    if not line:
        raise RuntimeError("Worker exited: " + worker.stderr.read())
    return json.loads(line)


def send(worker, line):
    worker.stdin.write(line + "\n")
    worker.stdin.flush()


def start(profile, spec):
    worker = subprocess.Popen(
        command(profile, "act", spec),
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        bufsize=1,
    )
    ready = receive(worker)
    assert ready["event"] == "ready", ready
    send(worker, "start")
    return worker, ready["connection"]


def finish(worker):
    result = receive(worker)
    assert result["event"] == "result", result
    worker.stdin.close()
    worker.wait(timeout=15)
    assert worker.returncode == 0, worker.stderr.read()
    return result


def wait_lock(profile, connection):
    if not ARGS.mysql_container:
        return run(profile, "wait-lock", {"connection": connection})
    query = (
        "SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID="
        + str(connection)
    )
    deadline = time.monotonic() + 4
    while time.monotonic() < deadline:
        result = subprocess.run(
            [
                "docker",
                "exec",
                ARGS.mysql_container,
                "sh",
                "-c",
                'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e '
                + "'"
                + query
                + "'",
            ],
            text=True,
            capture_output=True,
            timeout=10,
        )
        if result.returncode:
            raise RuntimeError(result.stderr)
        if int(result.stdout.strip()) > 0:
            return {"waiting": True}
        time.sleep(0.02)
    raise RuntimeError("No Oracle database lock wait")


results = []
for profile in [ARGS.driver]:
    for scenario in [
        "choices",
        "mode-assign",
        "assign-mode",
        "mode-csv",
        "csv-mode",
        "mode-move",
        "move-mode",
        "mode-clone",
        "clone-mode",
    ]:
        f = run(
            profile,
            "setup",
            {
                "single": scenario == "choices",
                "assignees": 1 if scenario in ["choices", "assign-mode"] else 2,
                "recurring": "clone" in scenario,
            },
        )
        workers = []
        try:
            source = f["source"]
            target = f["target"]
            person = f["people"][1]
            if scenario == "choices":
                first = {
                    "operation": "assign",
                    "card": f["card"],
                    "person": f["people"][0],
                }
                second = {"operation": "assign", "card": f["card"], "person": person}
            else:
                reverse = scenario.endswith("-mode")
                op = scenario.split("-")[0 if reverse else 1]
                destination = target if op == "move" else source
                toggle = {"operation": "mode", "board": destination}
                action = {
                    "operation": op,
                    "board": source,
                    "card": f["card"],
                    "person": person,
                    "stack": f["targetStack"] if op == "move" else f["sourceStack"],
                    "rule": f.get("rule"),
                }
                first, second = (action, toggle) if reverse else (toggle, action)
            a, _ = start(profile, {**first, "hold": True})
            workers.append(a)
            assert receive(a)["event"] == "held"
            b, connection = start(profile, second)
            workers.append(b)
            assert wait_lock(profile, connection)["waiting"]
            if scenario == "choices":
                independent, _ = start(
                    profile,
                    {"operation": "assign", "card": f["other"], "person": person},
                )
                workers.append(independent)
                assert finish(independent)["ok"]
            send(a, "release")
            one, two = finish(a), finish(b)
            assert one["ok"], (profile, scenario, one)
            expected_success = scenario in [
                "choices",
                "assign-mode",
                "csv-mode",
                "move-mode",
                "clone-mode",
            ]
            assert two["ok"] == expected_success, (profile, scenario, two)
            final = run(profile, "inspect", f)
            sets = final["sets"]
            ids = sets.get(str(f["card"]), [])
            if scenario == "choices":
                assert ids == [person], final
            elif scenario == "mode-assign":
                assert ids == ["admin", f["people"][0]], final
            elif scenario == "assign-mode":
                assert ids == ["admin", person], final
            elif scenario == "mode-csv":
                assert len(final["rows"]) == 2, final
            elif scenario == "csv-mode":
                assert (
                    len(final["rows"]) == 3 and max(map(len, sets.values())) == 2
                ), final
            elif scenario == "mode-move":
                assert final["sourceDeleted"] == 0 and len(final["rows"]) == 2, final
            elif scenario == "move-mode":
                assert final["sourceDeleted"] > 0 and any(
                    len(value) == 2 for value in sets.values()
                ), final
            elif scenario == "mode-clone":
                assert len(final["rows"]) == 2, final
            elif scenario == "clone-mode":
                assert (
                    len(final["rows"]) == 3 and max(map(len, sets.values())) == 2
                ), final
            row = {
                "driver": profile,
                "scenario": scenario,
                "lockWaitObserved": True,
                "result": "PASS",
                "contender": two,
                "state": final,
            }
            results.append(row)
            print(json.dumps(row), flush=True)
        finally:
            for worker in workers:
                if worker.poll() is None:
                    worker.kill()
                    worker.wait()
            run(profile, "cleanup", f)
if ARGS.output:
    pathlib.Path(ARGS.output).write_text(json.dumps(results, indent=2) + "\n")
