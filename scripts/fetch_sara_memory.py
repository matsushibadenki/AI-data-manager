# Path: /wp-content/themes/AI-data-manager/scripts/fetch_sara_memory.py
"""
SARA Event Memory CMSから events / relations / concepts / priority を取得するクライアント。

配置場所:
  /wp-content/themes/AI-data-manager/scripts/fetch_sara_memory.py

例:
  python scripts/fetch_sara_memory.py \
    --base-url https://example.com \
    --token YOUR_BEARER_TOKEN \
    --out-dir ./sara_export
"""

import argparse
import json
from pathlib import Path
from urllib import request, parse


def fetch_json(base_url: str, token: str, path: str, params: dict) -> object:
    query = parse.urlencode(params)
    url = f"{base_url.rstrip('/')}/wp-json/fourier/v1/{path}"
    if query:
        url = f"{url}?{query}"

    req = request.Request(url)
    if token:
        req.add_header("Authorization", f"Bearer {token}")

    with request.urlopen(req, timeout=60) as res:
        body = res.read().decode("utf-8")
        return json.loads(body)


def fetch_jsonl(base_url: str, token: str, path: str, params: dict) -> str:
    query = parse.urlencode(params)
    url = f"{base_url.rstrip('/')}/wp-json/fourier/v1/{path}"
    if query:
        url = f"{url}?{query}"

    req = request.Request(url)
    if token:
        req.add_header("Authorization", f"Bearer {token}")

    with request.urlopen(req, timeout=60) as res:
        return res.read().decode("utf-8")


def write_json(path: Path, data: object) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--token", default="")
    parser.add_argument("--out-dir", required=True)
    parser.add_argument("--limit", type=int, default=5000)
    args = parser.parse_args()

    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    events_jsonl = fetch_jsonl(args.base_url, args.token, "sara/export-jsonl", {"limit": args.limit})
    (out_dir / "events.jsonl").write_text(events_jsonl, encoding="utf-8")

    relations = fetch_json(args.base_url, args.token, "sara/relations", {"limit": args.limit})
    concepts = fetch_json(args.base_url, args.token, "sara/concepts", {"limit": args.limit})
    priority = fetch_json(args.base_url, args.token, "sara/priority", {"limit": args.limit})

    write_json(out_dir / "relations.json", relations)
    write_json(out_dir / "concepts.json", concepts)
    write_json(out_dir / "priority_queue.json", priority)

    manifest = {
        "schema": "sara_event_memory_export_v2",
        "files": {
            "events": "events.jsonl",
            "relations": "relations.json",
            "concepts": "concepts.json",
            "priority_queue": "priority_queue.json",
        },
        "notes": [
            "proposal_source distinguishes snn / ann / hybrid / signal_processing candidates.",
            "verification_state must be checked before using events as durable knowledge.",
        ],
    }
    write_json(out_dir / "manifest.json", manifest)
    print(f"Exported SARA Event Memory to {out_dir}")


if __name__ == "__main__":
    main()
