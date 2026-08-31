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

### 5.3. Docker環境のCron Runner

このリポジトリの`docker-compose.yml`には`wordpress-cron`サービスが含まれます。`DISABLE_WP_CRON=true`でも、Docker内部から1分ごとに`wp-cron.php`を呼び出すため、画面を閉じた後もパイプラインと連続自動蒸留が進みます。

```bash
docker compose up -d wordpress-cron
docker compose ps wordpress-cron
```

WordPress側では`fourier_auto_distillation_watchdog`が1分ごとにハートビートを記録し、実行中ジョブのCron予約が消失していた場合は自動復旧します。Runnerはアプリと同じDockerイメージを共有するため、専用イメージの重複ビルドは発生しません。

テーマ単体を別のDocker環境へ導入する場合は、`tools/cron/docker-compose.wordpress-cron.yml`を既存Composeへ統合するか、次のようにオーバーライドとして指定できます。既存Composeのアプリサービス名は`app`、イメージ名は`ai-data-manager-docker-app:latest`を前提とします。

```bash
docker compose -f docker-compose.yml \
  -f www/html/wordpress/wp-content/themes/AI-data-manager/tools/cron/docker-compose.wordpress-cron.yml \
  up -d wordpress-cron
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

URLがない場合でも、パイプライン画面の「概念蒸留」から中心概念を直接入力できます。画面表示はWordPressのロケールに合わせて、日本語・英語・简体中文のいずれか1言語だけを表示します。

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

回答生成後は `answer_quality` 段階で、追加のLLM APIを呼ばずに各候補を100点満点で評価します。

- 質問・中心概念との関連性：30点
- 期待回答点の充足：25点
- 学習データとしての情報量：20点
- 確信度と注意点：15点
- 可読性：10点

65点未満の回答は低品質として除外します。65点以上の候補は、完全一致に加えて2文字・3文字n-gramの類似度を比較し、同じ質問内で82%以上、別質問間で90%以上類似する場合は重複として除外します。日本語・英語・简体中文で同じ判定方式を利用できます。

元の `branch_answers` は監査用に保持され、評価結果は `answer_quality.items` の `accepted_variants` と `rejected_variants` に分けて保存されます。除外回答には低品質または重複の理由、重複先ID、類似度を記録します。

概念蒸留の保存形式は `concept_distillation` です。

```json
{
  "format": "concept_distillation",
  "data": {
    "concept": "犬",
    "concept_map": {"branches": []},
    "branch_questions": {"questions": []},
    "branch_answers": {"items": []},
    "answer_quality": {
      "thresholds": {"minimum_score": 65, "duplicate_similarity": 0.82},
      "summary": {
        "total_variants": 3,
        "accepted_variants": 1,
        "rejected_variants": 2,
        "duplicate_rejected": 1,
        "average_score": 54.0
      },
      "items": [],
      "curated_items": []
    },
    "knowledge": {"registered": false}
  }
}
```

Knowledge Serverへ送信する場合は、既存の `/items` エンドポイントへ `type: concept_distillation` として登録します。採用回答だけを `curated_answers`、評価集計を `quality_summary`、元データと全判定履歴を `data` に格納します。採用回答が0件の場合はKnowledge Server登録を行わず、レビュー待ちに進めます。

レビュー待ちの概念データには、平均点、採用数、除外数、重複数が表示されます。「回答品質」を開くと、質問ごとに回答本文、点数、採用・除外理由、重複類似度を確認できます。

### 用途別品質設定と再評価

「回答品質」を開くと、保存済み回答に適用する品質設定を変更できます。

| プリセット | 最低品質点 | 同じ質問内の重複類似度 | 用途 |
|---|---:|---:|---|
| 探索重視 | 50 | 75% | 多様な候補を広く残す調査・アイデア探索 |
| 標準 | 65 | 82% | 一般的な学習データ作成 |
| 厳格 | 75 | 88% | 正確性と重複抑制を優先するデータセット |
| カスタム | 40〜95 | 60〜98% | 用途固有の基準 |

「この設定で再評価」は、保存済みの `branch_answers` をローカル処理で再採点します。質問・回答の再生成やLLM API呼び出しは行いません。

再評価のたびに次の情報を保存します。

- 選択したプロファイルと実際の閾値
- 評価日時と評価リビジョン
- 採用・除外・重複件数
- 直前までの評価履歴（最新10件）

Knowledge Serverへ登録済みのデータを再評価した場合は `knowledge.sync_required: true` を設定し、画面に「再同期が必要」と表示します。再評価しただけでは外部サーバーへ自動上書きしません。

### 概念グラフの可視化と編集

概念レビュー行の「概念グラフ」を開くと、中心概念と知識の枝を階層表示します。各枝には次の集計を表示します。

- 優先度
- 生成済み質問数
- 品質評価で採用された回答数
- 有効・無効状態

枝を選択すると、次の項目を編集できます。

- 枝の名称
- 知識の範囲
- 優先度（1〜3）
- 質問観点（1行に1件、最大12件）
- 枝の有効・無効

「枝を追加」から手動の枝も作成できます。英数字の名称には読みやすいslug、日本語・中国語などの名称には `custom-<hash>` 形式の安定した内部IDを割り当てます。既存の枝IDは質問・回答との関連付けを維持するため変更しません。

枝を無効にすると、その枝の質問に対応する回答を品質評価の採用対象から直ちに外します。元の質問・回答データは監査用として削除しません。

概念グラフを変更した場合は、次の状態を保存します。

```json
{
  "concept_graph": {
    "revision": 2,
    "edited_at": "2026-08-31 11:42:33",
    "changed_branch_id": "custom-0123456789",
    "change_type": "branch_created",
    "distillation_refresh_required": true
  }
}
```

質問や回答は編集後の枝定義から自動再生成しないため、画面に「再蒸留が必要」と表示します。Knowledge Server登録済みの場合は「再同期が必要」も表示します。編集履歴は変更前・変更後を含めて最新20件まで保存します。

### 複数LLMによる回答比較と投票

レビュー行の「複数LLM審査」を開くと、同じ質問に対する複数回答を複数のLLMで比較できます。OpenAI、Gemini、Ollama、Customのうち、API設定済みのプロバイダを2つ以上選択してください。

審査時はモデル名、生成元、ローカル品質点を隠し、次の共通基準で各回答を100点満点で評価します。

| 評価軸 | 配点 |
| --- | ---: |
| 正確性 | 25 |
| 質問との関連性 | 20 |
| 完全性 | 20 |
| 明瞭さ | 15 |
| 不確実性の扱い | 10 |
| 学習データとしての有用性 | 10 |

「複数LLM審査を開始」を押すと、`fourier_concept_multi_judge_worker` がモデルを1つずつ処理します。長いAPI呼び出しを1回の画面リクエスト内で連続実行しないため、画面を閉じてもWordPress CronまたはDocker Cron Runnerから継続できます。

画面には次の運用状態を表示します。

- `queued`：審査待ち
- `running`：モデル別の審査中
- `completed`：2つ以上のモデルが完了
- `partial`：1モデルだけ成功
- `error`：全モデルで失敗

各質問について、順位点（Borda方式）と平均評価点を組み合わせた合議点、推奨回答、モデル別投票、確信度、懸念点を保存します。合意状態は「全会一致」「多数決」「意見分割」で表示します。未知の質問ID・回答IDは無視し、配点や確信度は許容範囲に補正します。

審査結果は `data.multi_judge`、直前の実行結果は投稿メタ `_fourier_concept_multi_judge_history` に最新10件まで保存します。再実行時に実API料金が発生するため、自動反復は行いません。また、合議結果はレビュー用の推奨情報であり、現在の `answer_quality` の採否を自動変更しません。Knowledge Server登録済みデータでは再同期が必要な状態だけを記録します。

### 合議回答の採用と選択的な再蒸留

合議結果がある質問では、順位ごとのラジオボタンから優先回答を選び、「選択した回答を一括採用」で複数質問をまとめて確定できます。採用した回答は次のように扱います。

- 元の回答候補とローカル品質点は削除しない
- 選択した回答を `accepted_variants` と `curated_items` の先頭へ移動
- `consensus_preferred: true` と採用日時を記録
- 後から品質プリセットを変えて再評価しても優先選択を維持
- 採用前後と操作者を `_fourier_concept_curation_history` に最新20件まで保存

回答自体を作り直す場合は、質問見出しの「再蒸留対象」をチェックし、使用する設定済みLLMを1つ選んで「選択範囲を再蒸留」を押します。`fourier_concept_selective_redistill_worker` が選択質問の回答候補だけを非同期で再生成します。選択していない質問、観念の枝、回答候補は変更しません。

再蒸留前の回答は `_fourier_concept_redistillation_history` に最新10回分保存します。完了後はローカル品質評価を自動実行し、その質問に対する以前の手動採用を解除します。回答本文が変わるため、以前の複数LLM審査には「再審査が必要」を表示します。Knowledge Server登録済みの場合は再同期が必要な状態になります。

選択的再蒸留は外部API料金またはローカル計算資源を使うため、ユーザー操作なしには開始しません。1回に指定できる質問は最大10件です。

### 審査モデルの重み付けと評価傾向

「モデル重みと評価傾向」を開くと、審査に参加したモデルごとの合議への影響度を25〜200%で設定できます。100%が標準です。重みの変更では保存済み審査結果だけを再集計するため、LLM APIを再実行しません。

重みは次の値へ同じ比率で適用します。

- 回答順位のBorda点
- 評価軸の合計点から求める平均点
- 推奨回答への投票比率
- 重み付き多数決の判定

設定は投稿メタ `_fourier_concept_judge_weights` に保存し、次回の複数LLM審査でも引き継ぎます。回答が選択的再蒸留され、以前の審査が失効している場合は重みを変更できません。先に複数LLM審査を再実行してください。

評価傾向では、現在の審査と `_fourier_concept_multi_judge_history` の履歴を横断して、モデルごとに次を表示します。

- 全回答候補に付けた平均点
- 自己申告した平均確信度
- モデルの1位回答と最終合議の一致率
- 最初と最新の審査間における平均点の変化
- 参加した審査回数と質問判定数

これらはモデルの正しさを直接証明する値ではありません。常に高得点を付けるモデル、確信度が過度に高いモデル、他モデルと結論が一致しにくいモデルを発見するためのレビュー補助指標です。

## 12. ロードマップ

- [Done] 概念マップ生成を実装
- [Done] 観念カテゴリごとの質問生成を実装
- [Done] 質問ごとの複数回答候補を実装
- [Done] Knowledge Server登録とレビュー待ちを実装
- [Done] 概念ごとの回答品質スコアリングと意味的重複除去を実装
- [Done] レビュー画面に品質集計・採否理由・類似度の詳細表示を実装
- [Done] 用途別品質プリセット・カスタム閾値・既存回答の再評価UIを実装
- [Done] 評価履歴とKnowledge Server再同期判定を実装
- [Done] 概念グラフの可視化・枝編集・新規枝追加UIを実装
- [Done] グラフ編集履歴・再蒸留・再同期判定を実装
- [Done] 複数LLMによる非同期回答比較・投票・合議表示を実装
- [Done] 合議結果の一括採用・監査履歴・再評価時の優先回答維持を実装
- [Done] 質問単位の選択的な回答再蒸留と旧回答履歴を実装
- [Done] 審査モデルの25〜200%重み付けと合議再計算を実装
- [Done] 履歴横断の平均点・確信度・合議一致率・採点変化を実装
- [Next] 枝編集から質問自体を選択再生成する差分プレビュー
- [Later] 評価傾向のCSV出力とモデル比較レポート

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

## 14. 連続自動蒸留

AI登録ページの「データ蒸留生成」内にある「連続自動蒸留 / Continuous Distillation / 连续自动蒸馏」から、停止するまで蒸留を継続できます。

処理フローは次のとおりです。

```text
シードと設定を保存
→ WordPress Cronへ1バッチ予約
→ LLM生成
→ JSON構造検証
→ 正規化SHA-256署名による重複検査
→ pending（レビュー待ち）の学習データとして保存
→ 指定間隔後の次回Cronを予約
→ ユーザーが停止するまで反復
```

### 設定項目

- シードデータ・トピック
- 蒸留方式
- 出力形式
- LLMプロバイダ
- 追加指示
- 実行間隔（1〜60分）
- 1回の生成数（1〜5件）

同一ユーザーが同時に実行できる自動蒸留ジョブは1件です。停止すると予約済みCronを解除し、LLM応答待ちだった場合も保存前に停止状態を再確認します。

実行中は開始ボタンが「設定を反映して再開始」に変わります。このボタンを押すと現在のジョブを安全に停止し、画面上の設定で新しいジョブを開始します。

ページ再読込後などでシード欄が空の場合、再開始時は実行中ジョブに保存されているシードを再利用します。`DISABLE_WP_CRON`が有効な環境では、開始操作と画面の状態監視が期限到来済みCronの非同期起動を補助します。ただし画面を閉じた後も継続させるには、サーバーCronから`wp-cron.php`を呼び出す設定が必要です。

運用状態にはジョブID、開始時刻、最終更新時刻、次回実行時刻が表示されます。次回実行時刻を過ぎてもワーカーが動いていない場合は、実態と異なる「AI生成中」を表示せず「開始待機中」へ補正し、期限到来済みCronの再起動を試みます。

生成物は即時公開されません。「AI Learning Pipeline」の処理キューにレビュー待ちとして追加され、承認すると公開、差し戻すと非公開のまま保持されます。

通信エラー時は、最大1時間まで指数バックオフしながら自動再試行します。状態確認時に実行予約が消失している場合は、自動的に予約を復旧します。

反復ごとに「基礎事実」「比較」「応用」「誤解訂正」「境界条件・推論」の観点を切り替えます。また、直前バッチの生成結果を次のプロンプトへ渡し、同じ問い・結論・具体例の反復を抑制します。JSONのキー順、大小文字、空白、句読点の違いは正規化署名で重複として扱います。

### 状態表示

画面には次の情報を表示します。

- 運用状態バッジ（停止中、操作処理中、開始待機中、AI生成中、次回待機中、再試行中）
- 開始・再開始・停止操作の受付結果
- 再開始前後のジョブID
- ジョブID、状態、現在フェーズ
- 反復回数
- 保存件数
- 重複除外件数
- 無効データ件数
- エラー件数と直近エラー
- 次回実行時刻
- Cron Runnerの状態と最終ハートビート

「AIとのやり取りと処理ログを表示する」を有効にすると、ジョブ開始、LLMへの送信プロンプト、LLM応答、構造検証、保存件数、重複・無効件数、再試行、停止を時系列で確認できます。APIキーはログに含めません。ログはジョブごとに最新50件まで保存し、画面には最新20件を表示します。

### 運用上の注意

自動蒸留は停止するまでLLM API料金またはローカル計算資源を継続的に消費します。WordPress Cronはアクセスがないと遅延する場合があるため、本番環境ではサーバーCronから`wp-cron.php`を定期実行してください。

### 連続自動蒸留ロードマップ

- [Done] Docker常駐Cron Runnerによる画面非依存の継続実行
- [Done] Watchdogハートビートと消失予約の自動復旧
- [Done] Runner稼働状態・最終ハートビートの画面表示
- [Done] 概念ごとの回答品質スコアリングと意味的重複除去
- [Done] 品質閾値の用途別設定と既存回答の再評価
- [Done] 概念グラフの可視化・編集UI
- [Done] 複数LLMによる回答比較と投票
- [Done] 合議結果の一括採用と選択的な再蒸留
- [Done] 審査モデルの重み付けと評価傾向分析
- [Next] 枝編集から質問自体を選択再生成する差分プレビュー
- [Later] Runner停止・連続エラーの外部通知
