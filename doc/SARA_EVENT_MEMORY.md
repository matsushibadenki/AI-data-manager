# Path: /wp-content/themes/AI-data-manager/doc/SARA_EVENT_MEMORY.md
# SARA Event Memory CMS

この修正は、WordPressをSARA Engine向けの「海馬相当 Event Memory」として使うための追加実装です。

## 追加ファイル

- `inc/functions_sara_event_memory.php`
- `scripts/fetch_sara_events.py`
- `doc/SARA_EVENT_MEMORY.md`

`functions.php` には次の読み込みを追加しています。

```php
require_once get_template_directory() . '/inc/functions_sara_event_memory.php';
```

## 役割分担

WordPress側はSNN本体を動かしません。WordPressは次の役割に限定します。

- Source管理
- Sparse event管理
- Experience record管理
- Curriculum manifest生成
- JSONL / REST API出力

SARA Engine側は、WordPressから取得したJSONLを `data/raw`、`data/interim`、`data/processed` に流し込み、学習・評価・推論を行います。

## 追加REST API

認証は既存の `fourier_server_access_token` を使うBearer Token方式です。

```text
GET  /wp-json/fourier/v1/sara/sources
POST /wp-json/fourier/v1/sara/sources
GET  /wp-json/fourier/v1/sara/events
POST /wp-json/fourier/v1/sara/events
GET  /wp-json/fourier/v1/sara/experiences
POST /wp-json/fourier/v1/sara/experiences
POST /wp-json/fourier/v1/sara/text-to-events
GET  /wp-json/fourier/v1/sara/export-jsonl
GET  /wp-json/fourier/v1/sara/curriculum-manifest
```

## イベント形式

SARA Engineの方針に合わせて、dense tensorではなく相対時間を持つsparse eventとして出力します。

```json
{
  "event_uid": "event_001",
  "source_id": 1,
  "experience_uid": "exp_001",
  "t": 1.24,
  "dt": 0.08,
  "modality": "text",
  "channel": "char",
  "event_type": "symbol_onset",
  "symbol": "猫",
  "payload": {},
  "state_before": ["previous_symbol:"],
  "state_after": ["current_symbol:猫"],
  "reward": 0,
  "prediction_error": 0,
  "confidence": 1,
  "quality_score": 0.7,
  "tags": ["text", "relative_time", "sara"]
}
```

## テキストからイベント列を作る例

```bash
curl -X POST "https://example.com/wp-json/fourier/v1/sara/text-to-events" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"text":"猫が魚を食べた。","insert":true}'
```

## SARA Engine側へJSONLを保存する例

```bash
python scripts/fetch_sara_events.py \
  --base-url "https://example.com" \
  --token "YOUR_TOKEN" \
  --out "data/raw/wp_sara_events.jsonl"
```

## 設計上の注意

- WordPress側で画像・音声・動画の重い解析はしない方針です。
- 解析済みの `object_detected`、`speech_segment`、`motion_start`、`tactile_contact` などをPOSTで受け取る構成にします。
- YouTubeや現実空間データは、外部Pythonパイプラインで変化検出・同期検出を行い、WordPressにはイベントとして保存します。
- 大規模化した場合は、WordPress DBからDuckDB / Parquetへ移すことを推奨します。その場合でもJSONL APIは互換層として残せます。
