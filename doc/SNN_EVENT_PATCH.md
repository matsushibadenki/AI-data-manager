# Path: doc/SNN_EVENT_PATCH.md
# SNNイベント列エクスポート追加案

## 追加するファイル

`wp-content/themes/AI-data-manager/inc/functions_snn_events.php`

## 修正するファイル

`wp-content/themes/AI-data-manager/functions.php`

`functions_rest_api.php` の読み込み直後に下記を追加してください。

```php
/* ----------------------- SNNイベント列エクスポート ---------------------- */
require_once get_template_directory() . '/inc/functions_snn_events.php';
```

## 追加されるAPI

`GET /wp-json/fourier/v1/snn-events`

既存のBearer Token認証をそのまま使います。

クエリ例:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://your-site.example/wp-json/fourier/v1/snn-events?format=all&limit=100&after_id=0"
```

## Python取得例

```bash
python scripts/fetch_snn_events.py \
  --base-url https://your-site.example \
  --token YOUR_TOKEN \
  --out snn_events.jsonl
```

## 出力イメージ

```json
{
  "schema": "snn_event_sequence_v1",
  "source": {"wp_post_id": 123, "title": "sample", "format": "plain"},
  "time_unit": "relative_token_step",
  "channels": ["text_token"],
  "events": [
    {"id":"e0","dt":0,"t_rel":0,"channel":"text_token","role":"plain","symbol":"猫","value":1},
    {"id":"e1","dt":1,"t_rel":1,"channel":"text_token","role":"plain","symbol":"が","value":1}
  ],
  "links": [
    {"src":"e0","dst":"e1","delay":1,"type":"next_token"}
  ]
}
```

この段階ではWordPress側は「保存・管理・相対イベント化」までに止め、音韻化・意味素化・動画/音声アライメントはPython側パイプラインで追加する想定です。
