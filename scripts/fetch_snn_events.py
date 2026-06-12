# Path: scripts/fetch_snn_events.py
# WordPress AI Data ManagerからSNNイベント列を取得してJSONLへ保存する最小クライアント。

import argparse
import json
from pathlib import Path
from typing import Any, Dict

import requests


def fetch_page(base_url: str, token: str, after_id: int, limit: int, data_format: str) -> Dict[str, Any]:
    endpoint = base_url.rstrip('/') + '/wp-json/fourier/v1/snn-events'
    response = requests.get(
        endpoint,
        headers={'Authorization': f'Bearer {token}'},
        params={'after_id': after_id, 'limit': limit, 'format': data_format},
        timeout=30,
    )
    response.raise_for_status()
    return response.json()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--token', required=True)
    parser.add_argument('--out', default='snn_events.jsonl')
    parser.add_argument('--format', default='all')
    parser.add_argument('--limit', type=int, default=100)
    args = parser.parse_args()

    out_path = Path(args.out)
    after_id = 0
    total = 0

    with out_path.open('w', encoding='utf-8') as f:
        while True:
            page = fetch_page(args.base_url, args.token, after_id, args.limit, args.format)
            items = page.get('items', [])
            if not items:
                break
            for item in items:
                f.write(json.dumps(item, ensure_ascii=False) + '\n')
                total += 1
            new_after_id = int(page.get('last_id', after_id))
            if new_after_id <= after_id or len(items) < args.limit:
                break
            after_id = new_after_id

    print(f'exported {total} sequences to {out_path}')


if __name__ == '__main__':
    main()
