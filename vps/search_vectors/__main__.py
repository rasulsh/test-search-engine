"""Entry point for systemd: `python -m search_vectors`.

Always ONE worker: each worker would hold its own model and vectors, and a
/reload would only reach one of them.
"""

from __future__ import annotations

import logging
import os

import uvicorn


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")
    uvicorn.run(
        "search_vectors.app:create_app",
        factory=True,
        host=os.environ.get("VPS_HOST", "127.0.0.1"),
        port=int(os.environ.get("VPS_PORT", "8600")),
        workers=1,
        ssl_certfile=os.environ.get("VPS_SSL_CERTFILE") or None,
        ssl_keyfile=os.environ.get("VPS_SSL_KEYFILE") or None,
        proxy_headers=False,
        server_header=False,
    )


if __name__ == "__main__":
    main()
