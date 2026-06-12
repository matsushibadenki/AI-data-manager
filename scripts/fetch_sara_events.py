# Path: /wp-content/themes/AI-data-manager/scripts/fetch_sara_events.py
# Description: WordPress SARA Event Memory APIからJSONLイベントを取得し、SARA Engineのdata/raw等へ保存する簡易クライアント。

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Iterable
from urllib import request, error


def fetch_jsonl(base_url: str, token: str, limit: int, after_id: int) -> str:
    endpoint = base_url.rstrip("/") + f"/wp-json/fourier/v1/sara/export-jsonl?limit={limit}&after_id={after_id}"
    req = request.Request(endpoint, headers={"Authorization": f"Bearer {token}"})
    try:
        with request.urlopen(req, timeout=30) as res:
            return res.read().decode("utf-8")
    except error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code}: {body}") from exc


def validate_jsonl(lines: Iterable[str]) -> int:
    count = 0
    for line in lines:
        line = line.strip()
        if not line:
            continue
        item = json.loads(line)
        required = ["event_uid", "t", "dt", "modality", "event_type"]
        missing = [key for key in required if key not in item]
        if missing:
            raise ValueError(f"Missing keys {missing} in event {item.get('event_uid')}")
        count += 1
    return count


def main() -> int:
    parser = argparse.ArgumentParser(description="Fetch SARA Event Memory JSONL from WordPress.")
    parser.add_argument("--base-url", required=True, help="WordPress site URL, e.g. https://example.com")
    parser.add_argument("--token", required=True, help="Bearer token stored in fourier_server_access_token")
    parser.add_argument("--out", required=True, help="Output JSONL path, e.g. data/raw/wp_sara_events.jsonl")
    parser.add_argument("--limit", type=int, default=5000)
    parser.add_argument("--after-id", type=int, default=0)
    args = parser.parse_args()

    text = fetch_jsonl(args.base_url, args.token, args.limit, args.after_id)
    count = validate_jsonl(text.splitlines())

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(text, encoding="utf-8")
    print(f"saved {count} events to {out_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
