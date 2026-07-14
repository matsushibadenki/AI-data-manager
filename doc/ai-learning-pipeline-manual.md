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
