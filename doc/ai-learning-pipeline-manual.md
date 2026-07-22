# AI Learning Pipeline マニュアル

## 1. 概要

AI Data Managerの「AI Learning Pipeline」は、WebページのURLからAI学習データを段階的に生成し、レビュー後に公開するための機能です。

```text
URL入力 → スクレイピング → 文章抽出 → 要約 → Instruction生成 → Q&A生成
       → Chat生成 → タグ生成 → 概念抽出 → Knowledge Server登録 → レビュー待ち
```

各段階はWordPress Cronの個別イベントとして実行されます。LLM処理中にブラウザを開き続ける必要はありません。

## 2. 初期設定

### 2.1. パイプライン画面を作成する

1. WordPress管理画面で「固定ページ → 新規追加」を開きます。
2. 任意のページタイトルを入力します。
3. ページテンプレートとして **AI Learning Pipeline** を選択します。
4. ページを公開します。
5. 公開ページをログインユーザーが開きます。

未ログインの場合はログイン画面へ移動します。

### 2.2. LLMを設定する

既存の「API設定」ページで、使用するLLMのAPIキー・モデル・接続先を設定します。

パイプライン画面では以下のプロバイダを選択できます。

- OpenAI
- Gemini
- Ollama
- Custom（OpenAI互換API）

選択したプロバイダはパイプラインごとに保存されます。

## 3. URLからデータを作成する

1. パイプライン画面の「対象URL」にURLを入力します。
2. LLMプロバイダを選択します。
3. 必要に応じてKnowledge Server設定を開きます。
4. 「パイプライン開始」を押します。

登録直後のデータはWordPressの `pending` 投稿として作成されます。処理キューには対象URL、現在の処理段階、状態が表示されます。

通常のHTTP/HTTPS Webページを対象とします。文章抽出では `script`、`style`、`noscript`、`svg`、`nav`、`footer`、`header` を除外します。本文は最大120,000文字、LLMへの入力は最大50,000文字です。

## 4. 処理ステージ

| ステージ | 内容 |
| --- | --- |
| `scraping` | URLからHTMLを取得し、タイトル・本文を保存します。 |
| `extraction` | HTMLから学習対象となる文章を抽出します。 |
| `summary` | 要約、重要ポイント、出典情報を生成します。 |
| `instruction` | Instruction / Input / Output形式のデータを生成します。 |
| `qa` | Question / Answer形式のデータを生成します。 |
| `chat` | messages配列を持つ対話データを生成します。 |
| `tags` | 検索用タグと説明文を生成します。 |
| `concepts` | 概念、定義、概念間の関係を生成します。 |
| `knowledge` | Knowledge Serverへ登録します。 |
| `review` | 人間による確認を待ちます。 |

結果は投稿本文に次の形式で保存されます。

```json
{
  "format": "pipeline",
  "data": {
    "source": {}, "extraction": {}, "summary": {},
    "instruction": {}, "qa": {}, "chat": {},
    "tags": {}, "concepts": {}, "knowledge": {}
  }
}
```

処理段階・状態・エラーメッセージ・更新日時は投稿メタに保存されます。

## 5. WordPress Cronの設定

### 5.1. WP Crontrolを使う場合

開発環境やアクセス数の少ないサイトでは、WP Crontrolで確認・実行できます。

1. WP Crontrolをインストールして有効化します。
2. 「ツール → Cron Events」を開きます。
3. `fourier_pipeline_worker` を検索します。
4. 未実行のイベントが登録されていることを確認します。
5. 必要に応じて「今すぐ実行」を押します。

1件のURLにつき、次のステージを処理するイベントが順番に登録されます。

```text
fourier_pipeline_worker(post_id)
```

### 5.2. 本番環境でのサーバーCron

本番環境では、アクセスがなくても処理が進むよう、OSのCronから `wp-cron.php` を定期実行する構成を推奨します。

```bash
*/1 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

`example.com` は実際のサイトURLに置き換えてください。サーバーCronを使う場合は `wp-config.php` に次を追加します。

```php
define('DISABLE_WP_CRON', true);
```

## 6. Knowledge Server連携

パイプライン画面の「Knowledge Server設定」にURLとトークンを入力すると、ログインユーザーのユーザーメタに保存されます。

現在は以下のAPI形式を想定しています。

```text
POST {Knowledge Server URL}/items
Authorization: Bearer {token}
Content-Type: application/json
```

送信ボディの例です。

```json
{
  "source_url": "https://example.com/article",
  "data": {
    "source": {}, "summary": {}, "instruction": {},
    "qa": {}, "chat": {}, "tags": {}, "concepts": {}
  }
}
```

Knowledge ServerのURLを設定しなかった場合は、登録処理をスキップしてレビューへ進みます。

## 7. レビューと公開

自動処理が完了すると、処理状態が `review` になります。

- **承認**：投稿を `publish` に変更します。
- **差し戻し**：レビュー状態を `rejected` として保存します。

承認前の投稿は公開されません。LLMの出力、出典URL、タグ、概念、Knowledge Server登録結果を確認してから承認してください。

## 8. エラー対応

状態が `error` のまま進まない場合は、次を確認してください。

1. WordPressサーバーから対象URLへアクセスできるか確認します。
2. API設定のAPIキー、モデル名、接続先を確認します。
3. WP Crontrolで `fourier_pipeline_worker` が登録されているか確認します。
4. 投稿メタ `_fourier_pipeline_message` または `_fourier_pipeline_error` を確認します。

Knowledge Server登録に失敗する場合は、URL、`/items` エンドポイント、Bearerトークン、サーバー間通信、HTTP 2xx応答を確認してください。

## 9. 運用上の注意

- URLの重複登録は防止されます。
- 外部サイトの利用規約・robots.txt・著作権を確認してください。
- 自動生成データには誤りが含まれる可能性があるため、公開前レビューを必須にしてください。
- APIキーとKnowledge Serverトークンは画面やログに表示しないでください。
- 大量登録時はLLM APIのレート制限と利用料金を確認してください。
- 動的レンダリングが必須のページでは、通常のHTTP取得だけでは本文を取得できない場合があります。

## 10. 関連ファイル

- `page-ai-pipeline.php`：パイプライン画面
- `inc/functions_ai_pipeline.php`：キュー登録、Cron処理、LLM処理、レビュー処理
- `inc/functions_llm_api.php`：LLMプロバイダとの通信
- `inc/functions_web_scrape.php`：既存のヘッドレスブラウザ方式スクレイピング

## 10.1. ディレクトリ整理

実行時に必要なWordPressテーマファイルと、開発・保守用スクリプトを分離しています。

- `scripts/`：WordPressから呼び出されるダンプ処理
- `tools/maintenance/python/`：過去の修正・更新用Pythonスクリプト
- `tools/maintenance/php/`：手動検証・データ確認用PHPスクリプト
- `tools/tests/fixtures/`：検証用画像
- `doc/`：各種マニュアル

保守用Pythonスクリプトを実行する場合は、テーマのルートをカレントディレクトリにしてください。

```bash
cd /path/to/AI-data-manager
python tools/maintenance/python/update_import.py
```

## 11. 概念単位の知識蒸留

URLがない場合でも、パイプライン画面の「概念蒸留 / Concept Distillation / 概念蒸馏」から中心概念を直接入力できます。

例：

```text
犬
```

概念蒸留では、まず次の観念カテゴリを展開します。

- 特徴 / Characteristics / 特征
- 生物分類・構造 / Taxonomy & structure / 生物分类与结构
- 習性・機能 / Behavior & function / 习性与功能
- 歴史・変遷 / History / 历史与演变
- 人間との関係・利用 / Human relationship / 与人类的关系与用途
- 関連語・周辺概念 / Related terms / 相关词与周边概念
- 例・具体化 / Examples / 示例与具体化
- よくある誤解・限界 / Misconceptions / 常见误解与局限
- 他概念・他生物との比較 / Comparison / 与其他概念或动物比较
- 推論問題・応用 / Reasoning & application / 推理问题与应用

LLMは概念に不要な枝を除外し、必要な枝を追加・統合します。その後、各枝に2〜3問の質問を作り、各質問について次の3種類の回答候補を生成します。

1. 短い直接回答
2. 背景・理由を含む説明
3. 具体例、反例、比較、推論のいずれかを含む応用回答

各回答には `confidence` と `caveats` を付けます。知識が不確実な場合に断定を避け、レビュー時に確認できるようにするためです。

概念蒸留の保存形式は `concept_distillation` です。

```json
{
  "format": "concept_distillation",
  "data": {
    "concept": "犬",
    "concept_map": {"branches": []},
    "branch_questions": {"questions": []},
    "branch_answers": {"items": []},
    "knowledge": {"registered": false}
  }
}
```

Knowledge Serverへ送信する場合は、既存の `/items` エンドポイントへ `type: concept_distillation` として登録します。

## 12. ロードマップ

- [Done] 概念マップ生成を実装
- [Done] 観念カテゴリごとの質問生成を実装
- [Done] 質問ごとの複数回答候補を実装
- [Done] Knowledge Server登録とレビュー待ちを実装
- [Next] 概念ごとの回答品質スコアリングと重複除去
- [Later] 概念グラフの可視化・編集UI
- [Later] 複数LLMによる回答比較と投票

## 13. Episode / Causal Narrative

物語性を保存するための `episode` 形式を追加しています。これは既存のInstruction、ChatML、DPOを置き換えるものではなく、物語の原典となる中間表現です。

登録画面の「Episode / 物語構造」タブから、次の内容を保存できます。

- 人間が読める物語本文
- 初期状況、初期状態、主体の目的
- 出来事と行動意図
- 直後の結果、長期的な結果
- 主体、因果関係、複数対象への影響
- 代替行動と予測結果
- 抽出原則と確信度
- 観測可能な中間ステップ
- 領域、テーマ、出典、ライセンス

保存形式の例です。

```json
{
  "format": "episode",
  "data": {
    "schema_version": "1.0",
    "data_type": "episode",
    "episode_id": "ep_000001",
    "narrative_text": "雨の日、主人公は道端で猫を見つけた。",
    "narrative": {
      "setting": "雨の日の帰り道",
      "initial_state": "主人公は一人だった",
      "goal": "猫の安全を確保する",
      "events": [],
      "outcome": "猫を保護した",
      "long_term_outcome": "協力者との信頼が生まれた"
    },
    "agents": [],
    "causal_relations": [],
    "impact": [],
    "alternatives": [],
    "interpretations": [],
    "observable_reasoning": {
      "observable_facts": [],
      "affected_parties": [],
      "candidate_actions": [],
      "predicted_effects": [],
      "answer": ""
    },
    "annotations": {"domain": [], "themes": [], "review_status": "pending_review"},
    "source": {"type": "human_authored", "license": "original"}
  }
}
```

`episode`はJSON / JSONLインポートでも自動判定され、REST APIの`format=episode`フィルタでも取得できます。Transformer形式でエクスポートすると、まず物語本文をテキストとして出力します。
