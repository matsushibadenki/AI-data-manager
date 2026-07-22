# AI Data Manager - API連携マニュアル

本システム（AI Data Manager）は、機械学習エンジニアリングを効率化するために「外部へデータを提供するAPI」と「外部のAIを利用するAPI」の双方向の連携機能を備えています。

このマニュアルでは、それぞれのAPI設定と使い方について解説します。

---

## 1. 外部連携用 REST API (データ取得・エクスポート)

本システムに蓄積された学習データを、PythonスクリプトやAxolotlなどのLLM学習パイプラインから直接フェッチ（自動取得）するためのエンドポイントです。

### 1.1. 認証トークンの取得
1. 本システムの「**API設定**」ページを開きます。
2. ページ下部の「**データ取得用 アクセストークン**」欄に表示されているランダムな文字列（Bearer Token）をコピーします。

### 1.2. エンドポイントの仕様
- **メソッド**: `GET`
- **URL**: `https://[あなたのサーバーのURL]/wp-json/fourier/v1/export-data`
- **認証ヘッダー**: `Authorization: Bearer <あなたのトークン>`
- **クエリパラメータ**:
  - `format` (任意): 取得したいデータのフォーマットを絞り込みます（例: `dpo`, `instruction`, `chatml`, `episode`）。カンマ区切りで複数指定（例: `dpo,instruction`）も可能です。指定がない場合は全件取得します。
  - `output_style` (任意): 出力データの構造を指定します（例: `raw`, `transformer`, `sara`）。指定がない場合は `raw` となります。
    - `raw`: データベースに保存された元の形式（タイトルやフォーマットを含めた形式）で出力します。
    - `transformer`: 一般的なTransformerの学習（Causal LM SFT）で扱いやすいよう、1つの `text` キーに結合された形式で出力します。
    - `sara`: SARA Engine向けの Event Memory（イベント構造）形式で出力します。

### 1.3. 出力されるデータ構造 (JSON)
AIのデータセットライブラリ（Hugging Face `datasets` など）でパースしやすいよう、フラットなJSON配列として出力されます。
```json
[
  {
    "title": "タイトル（メタデータ）",
    "format": "dpo",
    "prompt": "入力プロンプト",
    "chosen": "良い回答",
    "rejected": "悪い回答"
  }
]
```

### 1.4. 使用例
**▼ curl コマンドによる取得例**
```bash
curl -H "Authorization: Bearer YOUR_SECRET_TOKEN" \
     "https://your-server.com/wp-json/fourier/v1/export-data?format=dpo"
```

**▼ Python (requests) による取得例**
```python
import requests

url = "https://your-server.com/wp-json/fourier/v1/export-data"
headers = {
    "Authorization": "Bearer YOUR_SECRET_TOKEN"
}
params = {
    "format": "instruction"
}

response = requests.get(url, headers=headers, params=params)

if response.status_code == 200:
    dataset = response.json()
    print(f"{len(dataset)}件のデータを取得しました。")
    # ここから学習パイプラインへデータを流し込む処理
else:
    print(f"Error: {response.status_code} - {response.text}")
```

### 1.5. データの登録・更新・削除 (CRUD API)
外部からプログラムを通じて直接学習データを登録、更新、削除することができます。

#### データの登録 (POST)
- **メソッド**: `POST`
- **URL**: `https://[あなたのサーバーのURL]/wp-json/fourier/v1/learning-data`
- **リクエストボディ (JSON)**: `format` と `data` は必須です。
```json
{
  "title": "[Auto] Botによる収集データ",
  "format": "dpo",
  "data": {
    "prompt": "入力プロンプト",
    "chosen": "良い回答",
    "rejected": "悪い回答"
  }
}
```
※JSON配列 `[{}, {}]` を送信することで、複数件の一括登録（バルクインサート）も可能です。
**▼ curl 例**
```bash
curl -X POST -H "Authorization: Bearer YOUR_SECRET_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"format":"instruction","data":{"instruction":"質問","output":"回答"}}' \
     "https://your-server.com/wp-json/fourier/v1/learning-data"
```

#### データの更新 (PUT)
- **メソッド**: `PUT`
- **URL**: `https://[あなたのサーバーのURL]/wp-json/fourier/v1/learning-data/{id}`
- **リクエストボディ (JSON)**: 変更したい項目（`title`, `format`, `data`）のみを送信します。
**▼ curl 例**
```bash
curl -X PUT -H "Authorization: Bearer YOUR_SECRET_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"data":{"prompt":"修正後の入力","chosen":"修正後の良い回答","rejected":"悪い回答"}}' \
     "https://your-server.com/wp-json/fourier/v1/learning-data/123"
```

#### データの削除 (DELETE)
- **メソッド**: `DELETE`
- **URL**: `https://[あなたのサーバーのURL]/wp-json/fourier/v1/learning-data/{id}`
**▼ curl 例**
```bash
curl -X DELETE -H "Authorization: Bearer YOUR_SECRET_TOKEN" \
     "https://your-server.com/wp-json/fourier/v1/learning-data/123"
```

---

## 2. LLM連携API設定 (データ拡張・蒸留用)

本システムの「シート一覧」画面にある **バリエーション生成（🪄）** や **データ蒸留（🧪）** 機能を利用するために、強力なLLMのAPIキーや接続先を設定します。

設定はすべて「**API設定**」ページから行います。

### 2.1. クラウドAPI (OpenAI / Google Gemini)
もっとも手軽で高性能なアプローチです。
- **API Key**: それぞれの公式開発者コンソール（OpenAI Platform / Google AI Studio）から取得したAPIキーを入力します。
- **Default Model**:
  - OpenAI 推奨: `gpt-5.5`, `gpt-4-turbo`
  - Gemini 推奨: `gemini-3.1-pro-preview`, `gemini-1.5-flash-latest`

### 2.2. ローカルLLM: Ollama
自分のPCやローカルサーバーでOllamaを立ち上げている場合の設定です。
- **Endpoint URL**:
  - 基本的には `http://127.0.0.1:11434` です。
  - ※ もしAI Data ManagerをDockerコンテナ内で動かしており、ホストマシンのOllamaへアクセスしたい場合は `http://host.docker.internal:11434` や、マシンのローカルIPを指定してください。
- **Model Name**: 既に `ollama run <model>` 等でダウンロード済みのモデル名（例: `gemma4:12b-mlx`, `command-r`）を入力します。

### 2.3. ローカルLLM: Llama.cpp / その他OpenAI互換API
Llama.cppのサーバーモードや、vLLMなどの「OpenAI互換サーバー」を利用する場合の設定です。
- **Endpoint URL**: OpenAI互換のベースURLを入力します。
  - 例: `http://127.0.0.1:8080/v1` （`/chat/completions` の直前までを含めてください）
- **Model Name**: サーバー側で指定が必須の場合のみ入力します（多くのローカル互換サーバーでは空欄でも動作します）。

---

## 3. マルチモーダル連携用 API (汎用メディア対応)

GPT-4V相当のモデルやSora、Whisperなどのマルチモーダル学習に対応するため、画像・音声・動画・文書・コードなどのメディアデータのアップロードや学習メタデータの付与を行える汎用APIエンドポイントを提供しています。（従来の `/images/` エンドポイントも後方互換性のため利用可能です）

これらのAPIは `FileBird API` の設定画面で発行できる `X-API-Key` またはクエリパラメータ `api_key` による認証が必要です。

### 3.1. マルチモーダルデータと学習フォーマットの登録 (POST)
- **エンドポイント**: `POST /wp-json/my-image-api/v1/media/upload`
- **形式**: `multipart/form-data`
- **パラメータ**:
  - `file`: アップロードするメディアファイル (画像, 音声, 動画, PDF, CSV等 - 必須)
  - `folder_name`: 保存先のフォルダ名 (任意)
  - `description`: ファイルに紐づける学習メタデータのJSON文字列 (任意)

**▼ curl 例: 画像 (Instructionフォーマット)**
```bash
curl -X POST -H "X-API-Key: YOUR_FILEBIRD_API_KEY" \
     -F "file=@/path/to/your/image.jpg" \
     -F "folder_name=AI_Training_Images" \
     -F 'description={"format":"instruction","prompt":"この画像に写っているものは？","chosen":"りんごです"}' \
     "https://your-server.com/wp-json/my-image-api/v1/media/upload"
```

**▼ curl 例: 音声 (Transcriptionフォーマット)**
```bash
curl -X POST -H "X-API-Key: YOUR_FILEBIRD_API_KEY" \
     -F "file=@/path/to/your/audio.mp3" \
     -F "folder_name=AI_Training_Audio" \
     -F 'description={"format":"transcription","text":"こんにちは、今日はいい天気ですね。","speaker":"Speaker 1"}' \
     "https://your-server.com/wp-json/my-image-api/v1/media/upload"
```

### 3.2. メディアメタデータの更新 (PUT)
- **エンドポイント**: `PUT /wp-json/my-image-api/v1/media/{id}`
- **パラメータ (JSON)**: `title` (メディアタイトル), `description` (学習用JSONメタデータ)

**▼ curl 例**
```bash
curl -X PUT -H "X-API-Key: YOUR_FILEBIRD_API_KEY" \
     -H "Content-Type: application/json" \
     -d '{"description":"{\"format\":\"transcription\",\"text\":\"修正された書き起こし\"}"}' \
     "https://your-server.com/wp-json/my-image-api/v1/media/123"
```

### 3.3. メディアの削除 (DELETE)
- **エンドポイント**: `DELETE /wp-json/my-image-api/v1/media/{id}`

**▼ curl 例**
```bash
curl -X DELETE -H "X-API-Key: YOUR_FILEBIRD_API_KEY" \
     "https://your-server.com/wp-json/my-image-api/v1/media/123"
```
