# 開発・保守ツール / Development & Maintenance Tools / 开发与维护工具

このディレクトリは、WordPressテーマの実行時に直接読み込まれない補助スクリプトをまとめています。

This directory contains helper scripts that are not loaded directly by the WordPress theme at runtime.

此目录用于存放不会在WordPress主题运行时直接加载的辅助脚本。

## 構成 / Structure / 目录结构

- `maintenance/python/`
  - 過去の一括修正・コード更新用Pythonスクリプト
  - Historical bulk-fix and code-update Python scripts
  - 历史批量修复和代码更新Python脚本
- `maintenance/php/`
  - データベース確認・手動スクレイピング検証用PHPスクリプト
  - PHP scripts for database checks and manual scraping diagnostics
  - 用于数据库检查和手动抓取诊断的PHP脚本
- `tests/fixtures/`
  - 手動スクレイピング検証で生成・参照する画像
  - Images generated or referenced by manual scraping diagnostics
  - 手动抓取诊断生成或引用的图像
- `tests/php/`
  - WordPressを読み込んで実行するPHPスモークテスト
  - PHP smoke tests that bootstrap WordPress
  - 加载WordPress后运行的PHP冒烟测试

## 実行時に必要なスクリプト / Runtime scripts / 运行时脚本

WordPressから呼び出されるダンプ処理は、保守ツールとは分けて `scripts/` に置いています。

The dump processors called by WordPress remain in `scripts/` as runtime scripts.

由WordPress调用的转储处理脚本保留在 `scripts/` 中，作为运行时脚本使用。

- `scripts/process_wiki_dump.py`
- `scripts/process_commons_dump.py`

## 実行時の注意 / Running scripts / 执行注意事项

既存の保守スクリプトは、テーマのルートディレクトリをカレントディレクトリとして実行してください。

Run legacy maintenance scripts with the theme root as the current working directory.

运行旧版维护脚本时，请将主题根目录作为当前工作目录。

```bash
cd /path/to/AI-data-manager
python tools/maintenance/python/update_import.py
```

概念回答の品質評価、意味的重複除去、学習価値・被覆・矛盾候補・最小系譜は、Dockerのアプリコンテナ内で確認できます。

```bash
php wp-content/themes/AI-data-manager/tools/tests/php/test_concept_quality.php
```

複数LLM審査の正規化、重み付き合議、モデル別傾向集計、合議回答の採用、選択的な回答再蒸留、非同期ジョブ遷移は、外部APIを呼ばないモック結果で確認できます。

```bash
php wp-content/themes/AI-data-manager/tools/tests/php/test_concept_multi_judge.php
```

移動に伴い、WordPress側のダンプ処理参照先と、検証画像の出力先は更新済みです。
