# SARA Event Memory CMS v2

## 目的

このWordPressテーマは、SARA Engine向けの **Event Memory CMS** として動作します。

SARA側では、研究比較のために次の3モードを併存できます。

- `snn`: SNNのみで生成された候補
- `ann`: ANN補佐で生成された候補
- `hybrid`: SNNとANNを併用した候補

WordPress側は、どの方式が正しいかを決めません。  
候補・証拠・検証状態・履歴を保存し、SARA側の研究結果で採用判断できるようにします。

## 追加された考え方

従来の `sara_events` に加えて、以下を追加しました。

- `sara_relations`: Event同士の時間・因果候補グラフ
- `sara_concepts`: Concept Crystal候補
- `sara_priority`: Replay / consolidation 用の優先度キュー

## Eventの重要フィールド

```json
{
  "event_uid": "event_xxx",
  "modality": "audio",
  "event_type": "speech_start",
  "proposal_source": "snn",
  "verification_state": "unverified",
  "evidence_type": "candidate",
  "extractor_name": "change_detector",
  "extractor_version": "0.1.0",
  "prediction_error": 0.4,
  "novelty": 0.3,
  "reward": 0.0,
  "coverage": 0.5,
  "redundancy": 0.1,
  "event_cost": 1.0
}
```

### proposal_source

候補イベントの生成元です。

- `manual`
- `snn`
- `ann`
- `hybrid`
- `signal_processing`
- `rule`
- `import`

### verification_state

検証状態です。

- `observed`
- `unverified`
- `candidate`
- `provisional`
- `verified`
- `contradicted`
- `quarantined`
- `rejected`

重要: ANN生成でもSNN生成でも、初期値は原則 `unverified` または `candidate` です。  
検証済みの世界知識として扱うには `verified` へ昇格させます。

## Relation Graph

Endpoint:

```text
GET  /wp-json/fourier/v1/sara/relations
POST /wp-json/fourier/v1/sara/relations
```

例:

```json
{
  "relation_uid": "rel_001",
  "source_event_uid": "event_a",
  "relation_type": "predicts",
  "target_event_uid": "event_b",
  "min_delay_ms": 80,
  "max_delay_ms": 180,
  "confidence": 0.62,
  "evidence_count": 31,
  "counterexample_count": 6,
  "verification_state": "provisional",
  "proposal_source": "snn"
}
```

推奨relation_type:

- `before`
- `after`
- `overlaps`
- `same_episode`
- `predicts`
- `enables`
- `prevents`
- `requires`
- `supports`
- `contradicts`
- `causal_hypothesis`

## Concept Crystal

Endpoint:

```text
GET  /wp-json/fourier/v1/sara/concepts
POST /wp-json/fourier/v1/sara/concepts
```

例:

```json
{
  "concept_uid": "concept_cat_like_cluster",
  "label": "cat_candidate",
  "concept_type": "dynamic_mode",
  "evidence_count": 93,
  "contradiction_count": 2,
  "verification_state": "candidate",
  "utility_score": 0.72,
  "event_pattern": ["v_018", "a_044", "text_neko"]
}
```

## Priority Queue

Endpoint:

```text
GET  /wp-json/fourier/v1/sara/priority
POST /wp-json/fourier/v1/sara/priority
```

優先度は次の考え方で計算します。

```text
priority =
prediction_error
+ novelty
+ reward
+ coverage
- redundancy
- cost penalty
```

実装では安全のためclipとcost補正を入れています。

## Export

```text
GET /wp-json/fourier/v1/sara/export-jsonl
```

eventsをJSONLで出力します。

追加クライアント:

```text
/wp-content/themes/AI-data-manager/scripts/fetch_sara_memory.py
```

例:

```bash
python scripts/fetch_sara_memory.py \
  --base-url https://example.com \
  --token YOUR_BEARER_TOKEN \
  --out-dir ./sara_export
```

生成されるファイル:

```text
events.jsonl
relations.json
concepts.json
priority_queue.json
manifest.json
```

## 設計上の注意

WordPressは推論器ではありません。

WordPressの役割:

- source provenance
- event storage
- relation candidate storage
- priority queue
- concept crystal candidate storage
- export

SARAの役割:

- SNN-only / ANN-assisted / hybrid 比較
- 予測利得の検証
- 反例確認
- concept crystal昇格
- replay / consolidation
