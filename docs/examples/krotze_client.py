#!/usr/bin/env python3
"""
A minimal Krotze client, and executable documentation of the signing scheme.

Standard library only — the point is to show that talking to the API needs
nothing but HMAC-SHA256 and an HTTP client.

    python krotze_client.py https://krotze.com

It registers an identity, claims a username, creates a channel, posts a
message, polls once, and prints what came back. Credentials are written to
./krotze-identity.json so a second run continues as the same person.

See ../API.md for the full guide.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import sys
import time
import urllib.error
import urllib.request

IDENTITY_FILE = "krotze-identity.json"


class KrotzeError(RuntimeError):
    """An error the server reported, with its machine-readable reason if any."""

    def __init__(self, status: int, payload: dict):
        self.status = status
        self.payload = payload
        self.reason = payload.get("reason")
        super().__init__(payload.get("error") or payload.get("message") or f"HTTP {status}")


class Krotze:
    def __init__(self, base: str, identity: dict | None = None):
        self.base = base.rstrip("/")
        self.identity = identity or {}
        # Learned from the server if our clock turns out to be wrong; see
        # the `stale` branch in request().
        self.clock_skew = 0

    # ---------------------------------------------------------------- signing

    def _sign(self, method: str, path: str, body: bytes) -> dict:
        """
        The four headers that authenticate a request.

        `path` must be exactly what goes on the wire — including the /api
        prefix and any query string — and `body` exactly the bytes sent. The
        secret is used as a literal ASCII string; it looks like hex, but is
        never decoded.
        """
        ts = str(int(time.time()) + self.clock_skew)
        nonce = os.urandom(16).hex()
        canonical = "\n".join([
            method,
            path,
            ts,
            nonce,
            hashlib.sha256(body).hexdigest(),
        ]).encode()

        return {
            "X-Chat-Device": self.identity["device_id"],
            "X-Chat-Ts": ts,
            "X-Chat-Nonce": nonce,
            "X-Chat-Sig": hmac.new(
                self.identity["secret"].encode(), canonical, hashlib.sha256
            ).hexdigest(),
        }

    # ---------------------------------------------------------------- transport

    def request(self, method: str, path: str, body: dict | None = None, *, anonymous=False, _retried=False):
        payload = json.dumps(body).encode() if body is not None else b""
        headers = {"Accept": "application/json"}
        if body is not None:
            headers["Content-Type"] = "application/json"
        if self.identity and not anonymous:
            headers.update(self._sign(method, path, payload))

        request = urllib.request.Request(
            self.base + path, data=payload or None, headers=headers, method=method
        )

        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                return json.loads(response.read() or b"{}")
        except urllib.error.HTTPError as e:
            raw = e.read()
            try:
                data = json.loads(raw or b"{}")
            except ValueError:
                data = {}

            # A wrong local clock invalidates every signature. The server's own
            # Date header tells us the offset; keep it and sign again.
            if e.code == 401 and data.get("reason") == "stale" and not _retried:
                served = e.headers.get("Date")
                if served:
                    from email.utils import parsedate_to_datetime

                    self.clock_skew = int(parsedate_to_datetime(served).timestamp()) - int(time.time())
                    return self.request(method, path, body, anonymous=anonymous, _retried=True)

            raise KrotzeError(e.code, data) from None

    # ---------------------------------------------------------------- identity

    def register(self, device_name="python-client", invite: str | None = None) -> dict:
        """Create a new identity. `invite` is required on invite-only servers."""
        body = {"device_name": device_name}
        if invite:
            body["invite"] = invite

        self.identity = self.request("POST", "/api/session", body, anonymous=True)
        return self.identity

    def set_username(self, username: str) -> dict:
        return self.request("POST", "/api/profile/username", {"username": username})

    # ---------------------------------------------------------------- the app

    def state(self) -> dict:
        return self.request("GET", "/api/state")

    def create_channel(self, name: str, retention_days: int = 7) -> dict:
        return self.request("POST", "/api/channels", {"name": name, "retention_days": retention_days})

    def send(self, uuid: str, text: str, reply_to: int | None = None) -> dict:
        body = {"body": text}
        if reply_to:
            body["reply_to"] = reply_to
        return self.request("POST", f"/api/channels/{uuid}/messages", body)

    def messages(self, uuid: str, after: int = 0) -> dict:
        # The query string is part of what gets signed — build the path once
        # and hand the same string to both the signature and the request.
        return self.request("GET", f"/api/channels/{uuid}/messages?after={after}")

    def mark_read(self, uuid: str, message_id: int) -> dict:
        return self.request("POST", f"/api/channels/{uuid}/read", {"message_id": message_id})

    def join(self, invite_token: str) -> dict:
        """Apply to a channel. The owner has to approve before you can read it."""
        return self.request("POST", f"/api/join/{invite_token}")

    def poll(self, on_message, interval: float = 4.0):
        """
        The loop a real client lives in: watch /api/state, fetch what is new.

        Handles `channel_moved`, which a client must not ignore — rotating an
        invite gives a channel a new UUID and the old one stops resolving.
        """
        seen: dict[str, int] = {}

        while True:
            state = self.state()

            for note in state["notifications"]:
                if note["type"] == "channel_moved":
                    seen[note["data"]["channel_uuid"]] = seen.pop(note["data"]["old_uuid"], 0)
                    self.request("POST", "/api/notifications/read", {"id": note["id"]})

            for channel in state["channels"]:
                if channel["status"] != "approved" or not channel["unread"]:
                    continue
                uuid = channel["uuid"]
                result = self.messages(uuid, after=seen.get(uuid, 0))
                for message in result["messages"]:
                    seen[uuid] = message["id"]
                    on_message(channel, message)
                if seen.get(uuid):
                    self.mark_read(uuid, seen[uuid])

            time.sleep(interval)


def load_identity() -> dict | None:
    if os.path.exists(IDENTITY_FILE):
        with open(IDENTITY_FILE) as f:
            return json.load(f)
    return None


def save_identity(identity: dict) -> None:
    with open(IDENTITY_FILE, "w") as f:
        json.dump(identity, f, indent=2)
    os.chmod(IDENTITY_FILE, 0o600)


def main() -> int:
    base = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8000"
    invite = sys.argv[2] if len(sys.argv) > 2 else None

    identity = load_identity()
    client = Krotze(base, identity)

    if not identity:
        print(f"Registering a new identity on {base} …")
        try:
            client.register(invite=invite)
        except KrotzeError as e:
            if e.reason == "registration_closed":
                print("This server is invite-only. Pass the token from a /register/<token> link:")
                print(f"  python {sys.argv[0]} {base} <token>")
                return 1
            raise
        save_identity(client.identity)
        print(f"  user_id={client.identity['user_id']} status={client.identity.get('status')}")

        username = f"py-{client.identity['user_id']}"
        client.set_username(username)
        print(f"  username={username}")
    else:
        print(f"Continuing as user_id={identity['user_id']}")

    state = client.state()
    if state["account_status"] != "approved":
        print(f"This identity is {state['account_status']}: an admin has to approve it first.")
        return 1

    print(f"Server version {state['version']}, {len(state['channels'])} channel(s).")

    channel = client.create_channel("Reference client demo")
    print(f"Created channel {channel['uuid']}")
    print(f"  invite: {channel['invite_url']}")

    client.send(channel["uuid"], "Hello from the reference client.")
    messages = client.messages(channel["uuid"])["messages"]
    for m in messages:
        print(f"  <{m['username']}> {m['body']}")

    print("\nWorking. Watch for new messages with client.poll(print).")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KrotzeError as e:
        print(f"Error {e.status}: {e}" + (f" (reason: {e.reason})" if e.reason else ""), file=sys.stderr)
        sys.exit(1)
