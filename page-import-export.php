<?php
/*
 * Name: page-import-export.php
 * Template Name: Import Export
 * Description: LLM学習データのインポートおよびエクスポート画面。LaTeX数式表示に対応。
 */

// 認証状態の確認
$is_authenticated = is_user_logged_in();

// ログイン処理
$login_error = '';
if (!$is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $creds = array(
        'user_login'    => isset($_POST['username']) ? sanitize_user($_POST['username']) : '',
        'user_password' => isset($_POST['password']) ? $_POST['password'] : '',
        'remember'      => true
    );
    $user = wp_signon($creds, false);
    if (is_wp_error($user)) {
        $login_error = $user->get_error_message();
    } else {
        wp_safe_redirect($_SERVER['REQUEST_URI']);
        exit;
    }
}

get_header();

// WordPressのAJAX URLとノンスを取得
$ajax_url = admin_url('admin-ajax.php');
$upload_nonce = wp_create_nonce('learning_data_action');
?>

<style>

.learning-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--border-subtle, #eee);
    padding-bottom: 0.5rem;
    overflow-x: auto;
}
.learning-tab {
    padding: 0.75rem 1.25rem;
    background: transparent;
    border: none;
    cursor: pointer;
    font-weight: 500;
    color: var(--text-secondary, #666);
    border-radius: var(--radius-md, 4px);
    transition: all 0.2s ease;
    white-space: nowrap;
}
.learning-tab:hover {
    background: var(--bg-surface-hover, #f5f5f5);
}
.learning-tab.active {
    background: var(--bg-surface, #fff);
    color: var(--text-primary, #000);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border: 1px solid var(--border-subtle, #eee);
}
.learning-tab-content {
    display: none;
}
.learning-tab-content.active {
    display: block;
}

/* 既存の変数とスタイルを踏襲 */
.import-export-container {
    max-width: 1000px;
    margin: 3rem auto;
    padding: 0 1rem;
    font-family: var(--font-primary, 'Inter', 'Noto Sans JP', sans-serif);
}

.auth-form-wrapper {
    background: var(--bg-surface, #fff);
    max-width: 400px;
    margin: 5rem auto;
    padding: 2.5rem;
    border-radius: var(--radius-lg, 8px);
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    border: 1px solid var(--border-subtle, #eee);
}

.auth-input {
    width: 100%;
    padding: 0.8rem;
    margin-bottom: 1rem;
    border: 1px solid var(--border-subtle, #ccc);
    border-radius: 4px;
    box-sizing: border-box;
}

.panel-section {
    background: var(--bg-surface, #fff);
    padding: 2rem;
    border-radius: var(--radius-lg, 8px);
    border: 1px solid var(--border-subtle, #eee);
    margin-bottom: 2rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}

.panel-title {
    font-size: 1.3rem;
    font-weight: 600;
    margin-top: 0;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border-bottom: 2px solid var(--border-subtle, #eee);
    padding-bottom: 0.8rem;
}



/* プレビューテーブル */
.preview-table-wrapper {
    overflow-x: auto;
    margin-top: 1.5rem;
    display: none;
}
.data-sheet {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.data-sheet th, .data-sheet td {
    border: 1px solid var(--border-subtle, #eee);
    padding: 0.75rem;
    text-align: left;
}
.data-sheet th {
    background: var(--bg-body, #fafafa);
    font-weight: 600;
    color: var(--text-secondary, #666);
}

/* フィルター類 */
.filter-group {
    margin-bottom: 1.5rem;
}
.filter-label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}
.format-checkboxes {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}
.format-checkboxes label {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.9rem;
    cursor: pointer;
}

.radio-group {
    display: flex;
    gap: 1.5rem;
}
.radio-group label {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    cursor: pointer;
}

.date-inputs {
    display: flex;
    gap: 1rem;
    align-items: center;
}
.date-inputs input[type="date"] {
    padding: 0.5rem;
    border: 1px solid var(--border-subtle, #ccc);
    border-radius: 4px;
}

/* プログレスバー */
.progress-wrapper {
    display: none;
    margin-top: 1rem;
}
.progress-bar {
    height: 8px;
    background: #eee;
    border-radius: 4px;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    background: var(--accent, #C9A96E);
    width: 0%;
    transition: width 0.3s;
}

/* ボタン */
.btn-black {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.85rem;
    line-height: 1;
    text-decoration: none;
    background-color: var(--bg-surface);
    color: var(--text-primary);
    border: 1px solid var(--border-subtle);
    padding: 0.7rem 1.8rem;
    border-radius: var(--radius-full, 50px);
    transition: all 0.3s ease;
    cursor: pointer;
}
.btn-black:hover:not(:disabled) {
    color: var(--accent);
    border-color: var(--accent);
    background-color: var(--accent-subtle);
    transform: translateY(-1px);
}
.btn-black:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

<main>
    <div class="import-export-container">
        
        <?php if (!$is_authenticated): ?>
            <!-- ログインフォーム -->
            <div class="auth-form-wrapper">
                <h2 style="text-align:center; margin-top:0;"><span class="material-symbols-outlined" style="vertical-align:middle;">lock</span> <?php echo esc_html__('認証が必要です', 'fourier'); ?></h2>
                <?php if ($login_error): ?>
                    <p style="color:red; font-size:0.9rem; text-align:center;"><?php echo esc_html($login_error); ?></p>
                <?php endif; ?>
                <form method="post" action="">
                    <input type="text" name="username" class="auth-input" placeholder="Username" required autofocus>
                    <input type="password" name="password" class="auth-input" placeholder="Password" required>
                    <button type="submit" name="login_submit" class="btn-base btn-primary" style="width:100%;">
                        <?php echo esc_html__('ログイン', 'fourier'); ?>
                    </button>
                </form>
                <div style="margin-top: 1.5rem; text-align: center; font-size: 0.8rem; color: var(--text-secondary);">
                    <p style="margin-bottom: 0.5rem;"><?php echo esc_html__('※新規登録は管理者へお問い合わせください', 'fourier'); ?></p>
                    <a href="#" style="color: var(--text-secondary); text-decoration: underline; margin-right: 0.5rem;"><?php echo esc_html__('プライバシーポリシー', 'fourier'); ?></a>
                    <a href="#" style="color: var(--text-secondary); text-decoration: underline;"><?php echo esc_html__('利用規約', 'fourier'); ?></a>
                </div>
            </div>
        <?php else: ?>


            <div style="margin-bottom: 1.5rem;">
                <h2 style="margin: 0; display:flex; align-items:center; gap:0.5rem; font-size: 1.8rem;">
                    <span class="material-symbols-outlined" style="font-size: 2rem;">import_export</span>
                    <?php echo esc_html__('インポート / エクスポート', 'fourier'); ?>
                </h2>
                <p style="margin: 0.5rem 0 0 0; color: var(--text-secondary);">
                    <?php echo esc_html__('LLM学習データのバッチ登録および一括ダウンロードを行います。', 'fourier'); ?>
                </p>
            </div>


            <div class="learning-tabs">
                <button type="button" class="learning-tab active" data-target="tab-import">インポート</button>
                <button type="button" class="learning-tab" data-target="tab-export">エクスポート</button>
                <button type="button" class="learning-tab" data-target="tab-huggingface">Hugging Face</button>
                <button type="button" class="learning-tab" data-target="tab-wikipedia">Wikipediaダンプ一括処理</button>
                <button type="button" class="learning-tab" data-target="tab-commons">Wikimedia Commons一括処理</button>
                <button type="button" class="learning-tab" data-target="tab-webscrape">Webスクレイピング</button>
            </div>

            <!-- インポートセクション -->
            <section id="tab-import" class="panel-section learning-tab-content active">
                <h3 class="panel-title">
                    <span class="material-symbols-outlined">upload_file</span>
                    <?php echo esc_html__('データインポート', 'fourier'); ?>
                </h3>

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem;"><?php echo esc_html__('読み込みフォーマットの指定 (任意):', 'fourier'); ?></label>
                    <select id="import-force-format" class="auth-input" style="max-width: 300px; margin-bottom: 0;">
                        <option value="auto">自動推定 (Auto)</option>
                        <option value="chatml">ChatML</option>
                        <option value="sharegpt">ShareGPT</option>
                        <option value="instruction">Instruction</option>
                        <option value="cot">CoT</option>
                        <option value="dpo">DPO / RLHF</option>
                        <option value="frontend_code">Frontend Code</option>
                        <option value="plain">プレーンテキスト</option>
                        <option value="structured">構造化データ</option>
                    </select>
                </div>

                <div class="drop-zone" id="drop-zone">
                    <div class="drop-zone-content">
                        <span class="material-symbols-outlined upload-icon">cloud_upload</span>
                        <p class="drop-zone-text"><?php echo esc_html__('ここにファイルをドラッグ＆ドロップ', 'fourier'); ?></p>
                        <p class="drop-zone-subtext"><?php echo esc_html__('対応フォーマット: JSONL, JSON, CSV', 'fourier'); ?></p>
                        <span class="drop-zone-or"><?php echo esc_html__('または', 'fourier'); ?></span>
                        <button type="button" class="btn-base btn-primary" onclick="document.getElementById('file-input').click()">
                            <?php echo esc_html__('ファイルを選択', 'fourier'); ?>
                        </button>
                        <input type="file" id="file-input" accept=".jsonl,.json,.csv" style="display: none;" />
                    </div>
                </div>

                <!-- テンプレートダウンロード -->
                <style>

.learning-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--border-subtle, #eee);
    padding-bottom: 0.5rem;
    overflow-x: auto;
}
.learning-tab {
    padding: 0.75rem 1.25rem;
    background: transparent;
    border: none;
    cursor: pointer;
    font-weight: 500;
    color: var(--text-secondary, #666);
    border-radius: var(--radius-md, 4px);
    transition: all 0.2s ease;
    white-space: nowrap;
}
.learning-tab:hover {
    background: var(--bg-surface-hover, #f5f5f5);
}
.learning-tab.active {
    background: var(--bg-surface, #fff);
    color: var(--text-primary, #000);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border: 1px solid var(--border-subtle, #eee);
}
.learning-tab-content {
    display: none;
}
.learning-tab-content.active {
    display: block;
}

                .template-dl-btn {
                    font-size: 0.75rem;
                    padding: 0.25rem 0.6rem;
                    background: var(--bg-body, #fafafa);
                    border: 1px solid var(--border-subtle, #e0e0e0);
                    border-radius: 4px;
                    color: var(--text-secondary, #666);
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    gap: 0.2rem;
                    transition: all 0.2s ease;
                }
                .template-dl-btn:hover {
                    background: var(--bg-surface, #fff);
                    color: var(--text-primary, #333);
                    border-color: #ccc;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                }
                </style>
                <div class="template-downloads" style="margin-top: 1rem; padding: 0.8rem 1rem; background: var(--bg-surface, #fff); border: 1px solid var(--border-subtle, #e0e0e0); border-radius: 6px;">
                    <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.6rem; display: flex; align-items: center; gap: 0.3rem;">
                        <span class="material-symbols-outlined" style="font-size: 1rem;">download</span>
                        入力用テンプレート（JSON）をダウンロード
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                        <?php
                        $templates = [
                            'plain' => 'プレーンテキスト',
                            'instruction' => 'Instruction',
                            'chatml' => 'ChatML',
                            'sharegpt' => 'ShareGPT',
                            'cot' => 'CoT',
                            'dpo' => 'DPO / RLHF',
                            'frontend_code' => 'HTML/CSS/JS',
                            'structured' => '構造化データ'
                        ];
                        $template_dir_url = get_template_directory_uri() . '/templates/import/';
                        foreach ($templates as $file => $label):
                        ?>
                            <a href="<?php echo esc_url($template_dir_url . $file . '.json'); ?>" download="<?php echo esc_attr($file); ?>_template.json" class="template-dl-btn">
                                <span class="material-symbols-outlined" style="font-size: 0.9rem;">description</span>
                                <?php echo esc_html($label); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- プレビューと実行 -->
                <div id="import-preview-area" style="display: none;">
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
                        <h4 style="margin-top:0;">プレビュー結果</h4>
                        <div id="preview-stats" style="display:flex; gap: 2rem; font-size: 0.9rem;">
                            <!-- jsで挿入 -->
                        </div>
                    </div>
                    
                    <div class="preview-table-wrapper" id="preview-table-wrapper" style="display:block;">
                        <table class="data-sheet">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Title</th>
                                    <th>Detected Format</th>
                                    <th>Data Preview</th>
                                </tr>
                            </thead>
                            <tbody id="preview-tbody">
                                <!-- jsで挿入 -->
                            </tbody>
                        </table>
                    </div>

                    <div style="text-align: center; margin-top: 2rem;">
                        <button type="button" id="btn-execute-import" class="btn-base btn-primary" style="background: var(--text-primary); color: #fff;">
                            <span class="material-symbols-outlined">publish</span>
                            <?php echo esc_html__('インポートを実行', 'fourier'); ?>
                        </button>
                    </div>

                    <div class="progress-wrapper" id="import-progress">
                        <div style="display:flex; justify-content:space-between; font-size:0.85rem; margin-bottom:0.3rem;">
                            <span>インポート中...</span>
                            <span id="import-progress-text">0%</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" id="import-progress-fill"></div></div>
                    </div>
                </div>
            </section>

            <!-- Hugging Faceセクション -->
            <section id="tab-huggingface" class="panel-section learning-tab-content">
                <h3 class="panel-title">
                    <span class="material-symbols-outlined">public</span>
                    <?php echo esc_html__('外部オープンデータセット連携 (Hugging Face)', 'fourier'); ?>
                </h3>
                <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                    Hugging Faceで公開されているThe Pile, RedPajama, Alpacaなどの主要データセットから、必要な件数をサンプリングして直接インポートします。
                </p>

                <div style="background: var(--bg-body, #fafafa); padding: 1.5rem; border: 1px solid var(--border-subtle); border-radius: 6px; margin-bottom: 1.5rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1rem;">
                        <div>
                            <label style="display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem;"><?php echo esc_html__('プリセットから選択', 'fourier'); ?></label>
                            <select id="hf-preset-select" class="auth-input" style="margin-bottom: 0;">
                                <option value="">-- 選択してください --</option>
                                <option value="llm-jp/llm-jp-corpus">llm-jp-corpus (理研/NII)</option>
                                <option value="elyza/ELYZA-tasks-100">ELYZA-tasks-100 (日本語評価/指示)</option>
                                <option value="kunishou/databricks-dolly-15k-ja">Dolly 15k 日本語訳 (kunishou)</option>
                                <option value="izumi-lab/llm-japanese-dataset">LLM Japanese Dataset (izumi-lab)</option>
                                <option value="fujiki/japanese_alpaca_data">Alpaca 日本語 (fujiki)</option>
                                <option value="cyberagent/chatbot-arena-ja-calibrated">Chatbot Arena JA (CyberAgent)</option>
                                <option value="databricks/databricks-dolly-15k">Dolly (databricks-dolly-15k)</option>
                                <option value="yahma/alpaca-cleaned">Alpaca (alpaca-cleaned)</option>
                                <option value="OpenAssistant/oasst1">OASST1 (Open Assistant)</option>
                                <option value="togethercomputer/RedPajama-Data-1T">RedPajama-Data-1T</option>
                                <option value="EleutherAI/the_pile_deduplicated">The Pile (deduplicated)</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem;"><?php echo esc_html__('または、Dataset ID を手動入力', 'fourier'); ?></label>
                            <div style="display: flex; gap: 0.5rem;">
                                <input type="text" id="hf-dataset-id" class="auth-input" style="margin-bottom: 0;" placeholder="例: user/dataset-name">
                                <button type="button" id="btn-hf-load" class="btn-base btn-primary" style="white-space: nowrap;">読み込む</button>
                            </div>
                        </div>
                    </div>

                    <!-- アクセストークン入力 (Gated/Private用) -->
                    <div style="margin-bottom: 1rem; border-top: 1px dashed var(--border-subtle); padding-top: 1rem;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem;"><?php echo esc_html__('Hugging Face Access Token (任意: Gated/Private用)', 'fourier'); ?></label>
                        <input type="password" id="hf-access-token" class="auth-input" style="margin-bottom: 0;" placeholder="hf_...">
                        <span style="font-size: 0.75rem; color: var(--text-secondary);">※llm-jp-corpusなどのGated Datasetを取得する場合はトークンが必要です。</span>
                    </div>

                    <!-- 設定ロード後の表示領域 -->
                    <div id="hf-config-area" style="display: none; border-top: 1px solid var(--border-subtle); padding-top: 1rem; margin-top: 1rem;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1rem;">
                            <div>
                                <label style="display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem;">Config (サブセット)</label>
                                <select id="hf-config-select" class="auth-input" style="margin-bottom: 0;"></select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem;">Split (分割)</label>
                                <select id="hf-split-select" class="auth-input" style="margin-bottom: 0;"></select>
                            </div>
                        </div>
                        
                        <div style="background: var(--bg-surface); border: 1px solid var(--border-subtle); padding: 0.8rem; border-radius: 4px; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                            <div><strong>データセット規模:</strong> <span id="hf-dataset-size-info">情報を取得中...</span></div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1rem;">
                            <div>
                                <label style="display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem;">取得件数 (Limit)</label>
                                <input type="number" id="hf-limit" class="auth-input" style="margin-bottom: 0;" value="100" min="1" max="10000">
                                <span style="font-size: 0.75rem; color: var(--text-secondary);">※推奨: テスト時は100件程度、最大1万件</span>
                            </div>
                            <div>
                                <label style="display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem;">開始位置 (Offset)</label>
                                <input type="number" id="hf-offset" class="auth-input" style="margin-bottom: 0;" value="0" min="0">
                            </div>
                        </div>

                        <div style="text-align: right;">
                            <button type="button" id="btn-hf-preview" class="btn-base btn-primary" style="background: var(--text-primary); color: #fff;">
                                <span class="material-symbols-outlined" style="font-size: 1.2rem;">visibility</span> プレビュー取得
                            </button>
                        </div>
                    </div>
                </div>

                <!-- プレビュー結果表示領域 -->
                <div id="hf-preview-area" style="display: none;">
                    <div style="background: var(--bg-surface); padding: 1.5rem; border: 1px solid var(--border-subtle); border-radius: 6px; margin-bottom: 1.5rem;">
                        <h4 style="margin-top: 0; margin-bottom: 1rem;">カラムマッピング設定</h4>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">外部データセットのカラムを、システム内の「Instruction形式」「プレーンテキスト形式」などにどう割り当てるか設定してください。</p>
                        
                        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1rem; align-items: center; margin-bottom: 1rem;">
                            <label style="font-size: 0.85rem; font-weight: 500;">取り込みフォーマット</label>
                            <select id="hf-target-format" class="auth-input" style="margin-bottom: 0;">
                                <option value="instruction">Instruction (指示・回答)</option>
                                <option value="dpo">DPO (良い回答・悪い回答)</option>
                                <option value="text">Raw Text (プレーンテキスト)</option>
                            </select>
                        </div>

                        <div id="hf-mapping-container" style="background: var(--bg-body); padding: 1rem; border-radius: 4px;">
                            <!-- JSで動的にマッピング項目を生成 -->
                        </div>
                    </div>

                    <div class="preview-table-wrapper" style="display: block;">
                        <table class="data-sheet">
                            <thead>
                                <tr id="hf-preview-thead">
                                    <!-- JSでカラム名挿入 -->
                                </tr>
                            </thead>
                            <tbody id="hf-preview-tbody">
                                <!-- JSでデータ挿入 -->
                            </tbody>
                        </table>
                    </div>

                    <div style="text-align: center; margin-top: 2rem;">
                        <button type="button" id="btn-execute-hf-import" class="btn-base btn-primary" style="background: var(--text-primary); color: #fff;">
                            <span class="material-symbols-outlined">publish</span>
                            <?php echo esc_html__('マッピングしてインポート実行', 'fourier'); ?>
                        </button>
                    </div>

                    <div class="progress-wrapper" id="hf-import-progress">
                        <div style="display:flex; justify-content:space-between; font-size:0.85rem; margin-bottom:0.3rem;">
                            <span>インポート中...</span>
                            <span id="hf-import-progress-text">0%</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" id="hf-import-progress-fill"></div></div>
                    </div>
                </div>
            </section>

            <!-- Wikipediaダンプセクション -->
            <section id="tab-wikipedia" class="panel-section learning-tab-content">
                <h3 class="panel-title">
                    <span class="material-symbols-outlined">library_books</span>
                    Wikipediaダンプ一括処理
                </h3>
                
                <div style="background: var(--bg-body, #fafafa); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--border-subtle, #eee); margin-bottom: 2rem;">
                    <p style="font-size: 0.9rem; color: #555; margin-bottom: 1rem;">
                        WikipediaのXMLダンプファイル（<a href="https://dumps.wikimedia.org/" target="_blank">dumps.wikimedia.org</a>）のURLを指定して、サーバー側でバックグラウンド処理を行い、学習用のJSONデータセットに分割保存します。<br>
                        数GBのファイルの処理には時間がかかります。完了後、生成されたJSONファイルを個別にインポートしてください。
                    </p>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display:block; font-weight:bold; margin-bottom:0.5rem; font-size:0.9rem;">ダンプファイルのURL:</label>
                        <input type="text" id="wiki-dump-url" class="auth-input" placeholder="例: https://dumps.wikimedia.org/jawiki/latest/jawiki-latest-abstract.xml.gz" style="width:100%; margin-bottom:0.5rem;">
                        <span style="font-size: 0.8rem; color: #666;">※ `.xml.bz2` または `.xml.gz` に対応。軽量な <code>abstract.xml.gz</code> の利用を推奨します。</span>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <label style="display:block; font-weight:bold; margin-bottom:0.5rem; font-size:0.9rem;">1ファイルあたりの記事数:</label>
                        <input type="number" id="wiki-dump-chunk" class="auth-input" value="10000" min="1000" max="100000" style="width:200px;">
                    </div>
                    
                    <button type="button" id="btn-wiki-start" class="btn-base btn-primary">バックグラウンド処理を開始</button>
                    
                    <div id="wiki-progress-container" style="display:none; margin-top: 1.5rem; padding: 1rem; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
                        <div style="font-weight:bold; margin-bottom: 0.5rem;">ステータス: <span id="wiki-status-text">待機中</span></div>
                        <div style="color: #666; font-size: 0.85rem; margin-bottom: 0.5rem;" id="wiki-message-text"></div>
                        <div class="progress-bar"><div class="progress-fill" id="wiki-progress-fill" style="width:0%"></div></div>
                    </div>
                </div>
                
                <h4 style="margin-bottom: 1rem;">生成されたデータセット一覧</h4>
                <div style="text-align:right; margin-bottom:1rem;">
                    <button type="button" id="btn-wiki-refresh" class="btn-base btn-secondary"><span class="material-symbols-outlined" style="font-size:1rem;">refresh</span> 更新</button>
                </div>
                <div class="preview-table-wrapper" style="display:block;">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th>ファイル名</th>
                                <th>サイズ</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="wiki-files-tbody">
                            <tr><td colspan="3" style="text-align:center; color:#999;">ファイルがありません</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Commonsダンプセクション -->
            <section id="tab-commons" class="panel-section learning-tab-content">
                <h3 class="panel-title">
                    <span class="material-symbols-outlined">image</span>
                    Wikimedia Commons ダンプ一括処理
                </h3>
                
                <div style="background: var(--bg-body, #fafafa); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--border-subtle, #eee); margin-bottom: 2rem;">
                    <p style="font-size: 0.9rem; color: #555; margin-bottom: 1rem;">
                        Wikimedia CommonsのJSONダンプ（<a href="https://dumps.wikimedia.org/other/wikibase/commonswiki/" target="_blank">dumps.wikimedia.org</a>）のURLを指定して、画像URLとキャプションを抽出します。<br>
                        数十GB以上のファイルの処理には非常に時間がかかります。完了後、生成されたJSONファイルを個別にインポートしてください。
                    </p>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display:block; font-weight:bold; margin-bottom:0.5rem; font-size:0.9rem;">ダンプファイルのURL:</label>
                        <input type="text" id="commons-dump-url" class="auth-input" placeholder="例: https://dumps.wikimedia.org/other/wikibase/commonswiki/latest-mediainfo.json.gz" style="width:100%; margin-bottom:0.5rem;" value="https://dumps.wikimedia.org/other/wikibase/commonswiki/latest-mediainfo.json.gz">
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <label style="display:block; font-weight:bold; margin-bottom:0.5rem; font-size:0.9rem;">1ファイルあたりのデータ数:</label>
                        <input type="number" id="commons-dump-chunk" class="auth-input" value="10000" min="1000" max="100000" style="width:200px;">
                    </div>
                    
                    <button type="button" id="btn-commons-start" class="btn-base btn-primary">バックグラウンド処理を開始</button>
                    
                    <div id="commons-progress-container" style="display:none; margin-top: 1.5rem; padding: 1rem; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
                        <div style="font-weight:bold; margin-bottom: 0.5rem;">ステータス: <span id="commons-status-text">待機中</span></div>
                        <div style="color: #666; font-size: 0.85rem; margin-bottom: 0.5rem;" id="commons-message-text"></div>
                        <div class="progress-bar"><div class="progress-fill" id="commons-progress-fill" style="width:0%"></div></div>
                    </div>
                </div>
                
                <h4 style="margin-bottom: 1rem;">生成されたデータセット一覧</h4>
                <div style="text-align:right; margin-bottom:1rem;">
                    <button type="button" id="btn-commons-refresh" class="btn-base btn-secondary"><span class="material-symbols-outlined" style="font-size:1rem;">refresh</span> 更新</button>
                </div>
                <div class="preview-table-wrapper" style="display:block;">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th>ファイル名</th>
                                <th>サイズ</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="commons-files-tbody">
                            <tr><td colspan="3" style="text-align:center; color:#999;">ファイルがありません</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Webスクレイピングセクション -->
            <section id="tab-webscrape" class="panel-section learning-tab-content">
                <h3 class="panel-title">
                    <span class="material-symbols-outlined">web</span>
                    Webページスクレイピング
                </h3>
                
                <div style="background: var(--bg-body, #fafafa); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--border-subtle, #eee); margin-bottom: 2rem;">
                    <p style="font-size: 0.9rem; color: #555; margin-bottom: 1rem;">
                        入力したURLをHeadlessブラウザでレンダリングし、フルサイズのスクリーンショット画像と実装データ（HTMLソースコード）を取得・関連付けて学習データとして登録します。
                    </p>
                    
                    <div id="webscrape-alert" style="display:none; padding:1rem; background:#fee; border:1px solid #fcc; color:#c00; margin-bottom:1.5rem; border-radius:4px;">
                        <strong>機能が無効化されています：</strong> 現在の環境ではHeadlessブラウザコンテナ（browserless）との通信ができません。
                    </div>

                    <div id="webscrape-form">
                        <div style="margin-bottom: 1.5rem;">
                            <label style="display:block; font-weight:bold; margin-bottom:0.5rem; font-size:0.9rem;">対象URL:</label>
                            <input type="text" id="webscrape-url" class="auth-input" placeholder="例: https://example.com" style="width:100%; margin-bottom:0.5rem;">
                        </div>
                        
                        <button type="button" id="btn-webscrape-start" class="btn-base btn-primary">取得と登録を実行</button>
                        
                        <div id="webscrape-progress-container" style="display:none; margin-top: 1.5rem; padding: 1rem; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
                            <div style="font-weight:bold; margin-bottom: 0.5rem;">ステータス: <span id="webscrape-status-text">処理中...</span></div>
                            <div style="color: #666; font-size: 0.85rem; margin-bottom: 0.5rem;">しばらくお待ちください。（ページサイズにより数十秒かかる場合があります）</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- エクスポートセクション -->
            <section id="tab-export" class="panel-section learning-tab-content">
                <h3 class="panel-title">
                    <span class="material-symbols-outlined">download</span>
                    <?php echo esc_html__('データエクスポート', 'fourier'); ?>
                </h3>

                <form id="export-form" method="POST" action="<?php echo esc_url($ajax_url); ?>">
                    <input type="hidden" name="action" value="frontend_learning_data_export">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($upload_nonce); ?>">

                    <div class="filter-group">
                        <span class="filter-label"><?php echo esc_html__('対象フォーマット', 'fourier'); ?></span>
                        <div class="format-checkboxes">
                            <label><input type="checkbox" name="formats[]" value="all" checked id="export-format-all"> すべて</label>
                            <label><input type="checkbox" name="formats[]" value="plain" class="export-format-item"> Plain Text</label>
                            <label><input type="checkbox" name="formats[]" value="instruction" class="export-format-item"> Instruction</label>
                            <label><input type="checkbox" name="formats[]" value="chatml" class="export-format-item"> ChatML</label>
                            <label><input type="checkbox" name="formats[]" value="sharegpt" class="export-format-item"> ShareGPT</label>
                            <label><input type="checkbox" name="formats[]" value="cot" class="export-format-item"> CoT</label>
                            <label><input type="checkbox" name="formats[]" value="dpo" class="export-format-item"> DPO / RLHF</label>
                            <label><input type="checkbox" name="formats[]" value="frontend_code" class="export-format-item"> HTML/CSS/JS</label>
                            <label><input type="checkbox" name="formats[]" value="structured" class="export-format-item"> 構造化データ</label>
                        </div>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label"><?php echo esc_html__('出力形式', 'fourier'); ?></span>
                        <div class="radio-group">
                            <label><input type="radio" name="export_format" value="jsonl" checked> JSONL (.jsonl)</label>
                            <label><input type="radio" name="export_format" value="json"> JSON (.json)</label>
                            <label><input type="radio" name="export_format" value="csv"> CSV (.csv)</label>
                        </div>
                    </div>

                    <div class="filter-group" style="margin-top: 1rem;">
                        <span class="filter-label"><?php echo esc_html__('出力構造 (JSON/JSONLのみ)', 'fourier'); ?></span>
                        <div class="radio-group">
                            <label><input type="radio" name="output_style" value="raw" checked> Raw (そのまま出力)</label>
                            <label><input type="radio" name="output_style" value="transformer"> 一般的なTransformer (text結合)</label>
                            <label><input type="radio" name="output_style" value="sara"> sara向け (Event構造)</label>
                        </div>
                    </div>

                    <div style="margin-top: 2rem;">
                        <button type="submit" class="btn-base btn-primary">
                            <span class="material-symbols-outlined">download</span>
                            <?php echo esc_html__('ダウンロード', 'fourier'); ?>
                        </button>
                    </div>
                </form>
            </section>

        <?php endif; ?>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // タブ切り替え処理
        const tabs = document.querySelectorAll('.learning-tab');
        const contents = document.querySelectorAll('.learning-tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active from all tabs and contents
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                
                // Add active to clicked tab and corresponding content
                this.classList.add('active');
                const targetId = this.getAttribute('data-target');
                document.getElementById(targetId).classList.add('active');
            });
        });

        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        const ajaxUrl = '<?php echo esc_url($ajax_url); ?>';
        const uploadNonce = '<?php echo esc_js($upload_nonce); ?>';
        
        let pendingImportData = null;

        if (dropZone) {
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
            });

            dropZone.addEventListener('drop', function(e) {
                let dt = e.dataTransfer;
                let files = dt.files;
                if (files.length) handleFile(files[0]);
            });

            fileInput.addEventListener('change', function() {
                if (this.files.length) handleFile(this.files[0]);
            });
        }

        function handleFile(file) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (!['jsonl', 'json', 'csv'].includes(ext)) {
                alert('対応していないファイル形式です。JSONL, JSON, CSVのいずれかを選択してください。');
                return;
            }
            dropZone.innerHTML = '<div class="drop-zone-content"><span class="material-symbols-outlined upload-icon" style="font-size:3rem; color:var(--accent);">hourglass_empty</span><p class="drop-zone-text">プレビューを生成中...</p></div>';

            const fd = new FormData();
            fd.append('action', 'frontend_learning_data_import_preview');
            fd.append('nonce', uploadNonce);
            fd.append('import_file', file);
            fd.append('force_format', document.getElementById('import-force-format').value);

            fetch(ajaxUrl, {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(res => {
                // reset dropzone
                dropZone.innerHTML = '<div class="drop-zone-content"><span class="material-symbols-outlined upload-icon">cloud_upload</span><p class="drop-zone-text">ここにファイルをドラッグ＆ドロップ</p><p class="drop-zone-subtext">対応フォーマット: JSONL, JSON, CSV</p><span class="drop-zone-or">または</span><button type="button" class="btn-base btn-primary" onclick="document.getElementById(\'file-input\').click()">ファイルを選択</button><input type="file" id="file-input" accept=".jsonl,.json,.csv" style="display: none;" /></div>';
                
                // Re-attach listener to the new file-input element
                document.getElementById('file-input').addEventListener('change', function() {
                    if (this.files.length) handleFile(this.files[0]);
                });
                
                if (!res.success) {
                    alert(res.data.message || 'エラーが発生しました');
                    return;
                }

                const d = res.data;
                pendingImportData = d; // Store for execution
                
                // Read actual file content to parse fully on client side to send later
                const reader = new FileReader();
                reader.onload = function(e) {
                    pendingImportData.rawContent = e.target.result;
                    pendingImportData.fileName = file.name;
                    pendingImportData.fileExt = ext;
                };
                reader.readAsText(file);

                document.getElementById('import-preview-area').style.display = 'block';
                
                // Render Stats
                let formatText = '';
                for (const [fmt, count] of Object.entries(d.format_counts)) {
                    formatText += `<span style="background:#eee; padding:2px 6px; border-radius:4px; font-size:0.8rem;">${fmt}: ${count}</span> `;
                }
                document.getElementById('preview-stats').innerHTML = `
                    <div><strong>総件数:</strong> ${d.total_count}件</div>
                    <div><strong>検出フォーマット:</strong> ${formatText}</div>
                    ${d.errors.length > 0 ? `<div style="color:red;"><strong>エラー:</strong> ${d.errors.length}件</div>` : ''}
                `;

                // Render Table (max 10)
                const tbody = document.getElementById('preview-tbody');
                tbody.innerHTML = '';
                d.preview.forEach((item, index) => {
                    const tr = document.createElement('tr');
                    let previewStr = JSON.stringify(item.data);
                    if (previewStr.length > 100) previewStr = previewStr.substring(0, 100) + '...';
                    
                    tr.innerHTML = `
                        <td>${index + 1}</td>
                        <td>${escHtml(item.title || '（タイトルなし）')}</td>
                        <td><span style="background:var(--accent-subtle); padding:2px 6px; border-radius:4px;">${escHtml(item.format)}</span></td>
                        <td style="font-family:monospace; font-size:0.8rem;">${escHtml(previewStr)}</td>
                    `;
                    tbody.appendChild(tr);
                });

                // LaTeX (KaTeX) レンダリングを実行
                if (typeof renderMathInElement === 'function') {
                    renderMathInElement(tbody, {
                        delimiters: [
                            {left: '$$', right: '$$', display: true},
                            {left: '$', right: '$', display: false},
                            {left: '\\(', right: '\\)', display: false},
                            {left: '\\[', right: '\\]', display: true}
                        ],
                        throwOnError: false
                    });
                }
            })
            .catch(err => {
                alert('通信エラー');
                console.error(err);
            });
        }

        // Import Execute
        document.getElementById('btn-execute-import')?.addEventListener('click', function() {
            if (!pendingImportData) return;
            
            this.disabled = true;
            document.getElementById('import-progress').style.display = 'block';
            const progressFill = document.getElementById('import-progress-fill');
            const progressText = document.getElementById('import-progress-text');
            
            // Client side parsing to send batch
            let items = [];
            try {
                if (pendingImportData.fileExt === 'jsonl') {
                    const lines = pendingImportData.rawContent.trim().split('\n');
                    items = lines.map(line => {
                        const j = JSON.parse(line);
                        return detectFormatLocally(j);
                    });
                } else if (pendingImportData.fileExt === 'json') {
                    let j = JSON.parse(pendingImportData.rawContent);
                    // LLMが { draft_thought: "...", data: [...] } の形式で返した場合、配列部分を取り出す
                    if (!Array.isArray(j) && j.data && Array.isArray(j.data)) {
                        j = j.data;
                    } else if (!Array.isArray(j)) {
                        j = [j];
                    }
                    items = j.map(detectFormatLocally);
                }
                // CSV could be handled but complex on client side without library. We'll skip client parsing for CSV if complicated, 
                // but since the server already previewed it, ideally the server should just import it.
                // For simplicity, we send the file again.
            } catch(e) {
                console.warn(e);
            }

            // 抽出した items配列をそのままサーバーへ送る
            const fd = new FormData();
            fd.append('action', 'frontend_learning_data_import_execute');
            fd.append('nonce', uploadNonce);
            // We pass the raw items as JSON string if parsed locally, OR we just let the server parse the file again.
            // Server expects `items` JSON.
            if (items.length > 0) {
                fd.append('items', JSON.stringify(items));
            } else {
                alert('クライアントでのパースエラー。開発者に連絡してください。');
                return;
            }

            fetch(ajaxUrl, {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(res => {
                progressFill.style.width = '100%';
                progressText.textContent = '100%';
                
                setTimeout(() => {
                    if (res.success) {
                        alert(res.data.message);
                        window.location.reload();
                    } else {
                        alert(res.data.message || 'インポート失敗');
                        document.getElementById('btn-execute-import').disabled = false;
                    }
                }, 500);
            }).catch(e => {
                alert('通信エラー');
                document.getElementById('btn-execute-import').disabled = false;
            });
        });

        function detectFormatLocally(raw) {
            const forceFormat = document.getElementById('import-force-format') ? document.getElementById('import-force-format').value : 'auto';
            let format = 'structured';
            
            let checkTarget = (raw.data && Array.isArray(raw.data)) ? raw.data : raw;
            
            if (forceFormat !== 'auto') {
                format = forceFormat;
            } else if (checkTarget.instruction && checkTarget.output) {
                format = 'instruction';
            } else if (checkTarget.messages && Array.isArray(checkTarget.messages)) {
                format = 'chatml';
            } else if (checkTarget.conversations && Array.isArray(checkTarget.conversations)) {
                format = 'sharegpt';
            } else if (checkTarget.question && checkTarget.thought && checkTarget.answer) {
                format = 'cot';
            } else if (checkTarget.prompt && checkTarget.chosen && checkTarget.rejected) {
                format = 'dpo';
            } else if (checkTarget.html || checkTarget.css || checkTarget.js) {
                format = 'frontend_code';
            } else if (checkTarget.text && Object.keys(checkTarget).length === 1) {
                format = 'plain';
            } else if (Array.isArray(checkTarget) && checkTarget.length > 0) {
                const first = checkTarget[0];
                if (first.instruction && first.output) {
                    format = 'instruction';
                } else if (first.role) {
                    format = 'chatml';
                } else if (first.from) {
                    format = 'sharegpt';
                } else if (first.question && first.thought && first.answer) {
                    format = 'cot';
                } else if (first.prompt && first.chosen && first.rejected) {
                    format = 'dpo';
                }
            }

            return {
                title: raw.title || '',
                format: format,
                data: checkTarget
            };
        }

        // Export checkboxes logic
        const chkAll = document.getElementById('export-format-all');
        const chkItems = document.querySelectorAll('.export-format-item');
        if (chkAll) {
            chkAll.addEventListener('change', function() {
                chkItems.forEach(c => c.checked = false);
            });
            chkItems.forEach(c => {
                c.addEventListener('change', function() {
                    if (this.checked) chkAll.checked = false;
                });
            });
        }

        // ==========================================
        // Hugging Face Open Dataset Import Logic
        // ==========================================
        const hfPresetSelect = document.getElementById('hf-preset-select');
        const hfDatasetIdInput = document.getElementById('hf-dataset-id');
        const btnHfLoad = document.getElementById('btn-hf-load');
        const hfConfigArea = document.getElementById('hf-config-area');
        const hfConfigSelect = document.getElementById('hf-config-select');
        const hfSplitSelect = document.getElementById('hf-split-select');
        const btnHfPreview = document.getElementById('btn-hf-preview');
        const hfPreviewArea = document.getElementById('hf-preview-area');
        const hfPreviewThead = document.getElementById('hf-preview-thead');
        const hfPreviewTbody = document.getElementById('hf-preview-tbody');
        const hfMappingContainer = document.getElementById('hf-mapping-container');
        const hfTargetFormat = document.getElementById('hf-target-format');
        const btnExecuteHfImport = document.getElementById('btn-execute-hf-import');
        
        let currentHfFeatures = [];
        let currentHfRows = [];

        if (hfPresetSelect) {
            hfPresetSelect.addEventListener('change', function() {
                if (this.value) {
                    hfDatasetIdInput.value = this.value;
                }
            });
        }

        if (btnHfLoad) {
            btnHfLoad.addEventListener('click', async function() {
                const datasetId = hfDatasetIdInput.value.trim();
                if (!datasetId) {
                    alert('Dataset IDを入力してください。');
                    return;
                }
                
                btnHfLoad.textContent = '読み込み中...';
                btnHfLoad.disabled = true;
                
                try {
                    const hfToken = document.getElementById('hf-access-token')?.value.trim();
                    const fetchOptions = hfToken ? { headers: { 'Authorization': `Bearer ${hfToken}` } } : {};
                    const res = await fetch(`https://datasets-server.huggingface.co/splits?dataset=${encodeURIComponent(datasetId)}`, fetchOptions);
                    const data = await res.json();
                    
                    if (data.error) {
                        alert('APIエラー: ' + data.error);
                        throw new Error(data.error);
                    }

                    const splits = data.splits || [];
                    if (splits.length === 0) {
                        alert('利用可能なSplitが見つかりませんでした。');
                        return;
                    }

                    // configsを抽出
                    const configs = [...new Set(splits.map(s => s.config))];
                    hfConfigSelect.innerHTML = configs.map(c => `<option value="${c}">${c}</option>`).join('');
                    
                    // configが変わったらsplitを更新する関数
                    const updateSplits = () => {
                        const selectedConfig = hfConfigSelect.value;
                        const availableSplits = splits.filter(s => s.config === selectedConfig).map(s => s.split);
                        hfSplitSelect.innerHTML = availableSplits.map(s => `<option value="${s}">${s}</option>`).join('');
                    };
                    
                    hfConfigSelect.addEventListener('change', updateSplits);
                    updateSplits();
                    
                    hfConfigArea.style.display = 'block';
                    hfPreviewArea.style.display = 'none';

                    // データセットの規模（行数など）を非同期で取得
                    const sizeInfoEl = document.getElementById('hf-dataset-size-info');
                    sizeInfoEl.textContent = '取得中...';
                    fetch(`https://datasets-server.huggingface.co/size?dataset=${encodeURIComponent(datasetId)}`, fetchOptions)
                        .then(r => r.json())
                        .then(sizeData => {
                            if (sizeData && sizeData.size && sizeData.size.dataset) {
                                const rows = sizeData.size.dataset.num_rows || 0;
                                const bytes = sizeData.size.dataset.num_bytes_original_files || 0;
                                const gb = (bytes / (1024 ** 3)).toFixed(2);
                                sizeInfoEl.textContent = `総データ数: ${rows.toLocaleString()} 件 (約 ${gb} GB)`;
                            } else {
                                sizeInfoEl.textContent = '取得失敗';
                            }
                        })
                        .catch(() => {
                            sizeInfoEl.textContent = '取得失敗';
                        });

                } catch (e) {
                    console.error(e);
                } finally {
                    btnHfLoad.textContent = '読み込む';
                    btnHfLoad.disabled = false;
                }
            });
        }

        if (btnHfPreview) {
            btnHfPreview.addEventListener('click', async function() {
                const datasetId = hfDatasetIdInput.value.trim();
                const config = hfConfigSelect.value;
                const split = hfSplitSelect.value;
                const limit = document.getElementById('hf-limit').value || 100;
                const offset = document.getElementById('hf-offset').value || 0;
                
                btnHfPreview.textContent = '取得中...';
                btnHfPreview.disabled = true;
                
                try {
                    const hfToken = document.getElementById('hf-access-token')?.value.trim();
                    const fetchOptions = hfToken ? { headers: { 'Authorization': `Bearer ${hfToken}` } } : {};
                    const url = `https://datasets-server.huggingface.co/rows?dataset=${encodeURIComponent(datasetId)}&config=${encodeURIComponent(config)}&split=${encodeURIComponent(split)}&offset=${offset}&length=${limit}`;
                    const res = await fetch(url, fetchOptions);
                    const data = await res.json();
                    
                    if (data.error) {
                        alert('APIエラー: ' + data.error);
                        throw new Error(data.error);
                    }

                    currentHfFeatures = data.features || [];
                    currentHfRows = (data.rows || []).map(r => r.row);
                    
                    // 自動フォーマット判定 (The Pile等のテキストコーパス向け)
                    const featureNames = currentHfFeatures.map(f => f.name.toLowerCase());
                    const hasInstruction = featureNames.some(f => ['instruction', 'prompt', 'question', 'input'].includes(f));
                    const hasText = featureNames.some(f => ['text', 'content'].includes(f));
                    if (!hasInstruction && hasText) {
                        hfTargetFormat.value = 'text';
                    } else if (hasInstruction) {
                        hfTargetFormat.value = 'instruction';
                    }
                    
                    renderHfPreview();
                    renderHfMapping();
                    hfPreviewArea.style.display = 'block';

                } catch (e) {
                    console.error(e);
                } finally {
                    btnHfPreview.innerHTML = '<span class="material-symbols-outlined" style="font-size: 1.2rem;">visibility</span> プレビュー取得';
                    btnHfPreview.disabled = false;
                }
            });
        }
        
        function renderHfPreview() {
            hfPreviewThead.innerHTML = currentHfFeatures.map(f => `<th>${escHtml(f.name)}</th>`).join('');
            
            let tbodyHtml = '';
            // 最大5件だけプレビュー
            const previewLimit = Math.min(currentHfRows.length, 5);
            for (let i = 0; i < previewLimit; i++) {
                const row = currentHfRows[i];
                const tr = document.createElement('tr');
                let tdHtml = '';
                currentHfFeatures.forEach(f => {
                    let val = row[f.name];
                    if (typeof val === 'object') val = JSON.stringify(val);
                    const displayVal = val !== undefined && val !== null ? String(val).substring(0, 50) + (String(val).length > 50 ? '...' : '') : '';
                    tdHtml += `<td>${escHtml(displayVal)}</td>`;
                });
                tbodyHtml += `<tr>${tdHtml}</tr>`;
            }
            if (currentHfRows.length > 5) {
                tbodyHtml += `<tr><td colspan="${currentHfFeatures.length}" style="text-align:center; color:#999;">... 他 ${currentHfRows.length - 5}件 ...</td></tr>`;
            }
            hfPreviewTbody.innerHTML = tbodyHtml;
        }

        function renderHfMapping() {
            const format = hfTargetFormat.value;
            let fields = [];
            if (format === 'instruction') {
                fields = [{id: 'hf-map-instruction', label: 'Instruction (指示)'}, {id: 'hf-map-output', label: 'Output (回答)'}];
            } else if (format === 'dpo') {
                fields = [{id: 'hf-map-prompt', label: 'Prompt'}, {id: 'hf-map-chosen', label: 'Chosen'}, {id: 'hf-map-rejected', label: 'Rejected'}];
            } else if (format === 'text') {
                fields = [{id: 'hf-map-text', label: 'Text (本文)'}];
            }
            
            const optionsHtml = `<option value="">-- 無視する --</option>` + currentHfFeatures.map(f => `<option value="${f.name}">${f.name}</option>`).join('');
            
            hfMappingContainer.innerHTML = fields.map(field => `
                <div style="margin-bottom: 0.8rem; display: flex; align-items: center; gap: 1rem;">
                    <label style="width: 150px; font-size: 0.85rem;">${field.label}</label>
                    <select id="${field.id}" class="auth-input" style="margin-bottom: 0; width: auto;">
                        ${optionsHtml}
                    </select>
                </div>
            `).join('');
            
            // 自動マッピングの試み (単純なヒューリスティック)
            if (format === 'instruction') {
                autoSelect('hf-map-instruction', ['instruction', 'prompt', 'question', 'input']);
                autoSelect('hf-map-output', ['output', 'response', 'answer']);
            } else if (format === 'text') {
                autoSelect('hf-map-text', ['text', 'content']);
            }
        }
        
        function autoSelect(selectId, hints) {
            const el = document.getElementById(selectId);
            if (!el) return;
            for (const hint of hints) {
                for (let i = 0; i < el.options.length; i++) {
                    const val = el.options[i].value.toLowerCase();
                    if (val.includes(hint)) {
                        el.selectedIndex = i;
                        return;
                    }
                }
            }
        }

        if (hfTargetFormat) {
            hfTargetFormat.addEventListener('change', renderHfMapping);
        }

        if (btnExecuteHfImport) {
            btnExecuteHfImport.addEventListener('click', function() {
                if (currentHfRows.length === 0) return;
                
                const format = hfTargetFormat.value;
                const mapping = {};
                if (format === 'instruction') {
                    mapping.instruction = document.getElementById('hf-map-instruction')?.value;
                    mapping.output = document.getElementById('hf-map-output')?.value;
                    if (!mapping.instruction || !mapping.output) { alert('マッピングが不完全です。'); return; }
                } else if (format === 'dpo') {
                    mapping.prompt = document.getElementById('hf-map-prompt')?.value;
                    mapping.chosen = document.getElementById('hf-map-chosen')?.value;
                    mapping.rejected = document.getElementById('hf-map-rejected')?.value;
                } else if (format === 'text') {
                    mapping.text = document.getElementById('hf-map-text')?.value;
                    if (!mapping.text) { alert('Textのマッピングが必要です。'); return; }
                }
                
                const mappedData = currentHfRows.map(row => {
                    const item = {};
                    if (format === 'instruction') {
                        item.instruction = row[mapping.instruction];
                        item.output = row[mapping.output];
                    } else if (format === 'dpo') {
                        item.prompt = row[mapping.prompt];
                        item.chosen = row[mapping.chosen];
                        item.rejected = row[mapping.rejected];
                    } else if (format === 'text') {
                        item.text = row[mapping.text];
                    }
                    return item;
                });
                
                // 既存のインポートAPIを利用
                const finalItems = mappedData.map(row => {
                    return {
                        title: `[Import] ${hfDatasetIdInput.value}`,
                        format: format,
                        data: row
                    };
                });

                btnExecuteHfImport.disabled = true;
                const progressWrapper = document.getElementById('hf-import-progress');
                const progressFill = document.getElementById('hf-import-progress-fill');
                const progressText = document.getElementById('hf-import-progress-text');
                
                progressWrapper.style.display = 'block';
                progressFill.style.width = '50%';
                progressText.textContent = '送信中...';
                
                const fd = new FormData();
                fd.append('action', 'frontend_learning_data_import_execute');
                fd.append('nonce', uploadNonce);
                fd.append('items', JSON.stringify(finalItems));
                
                fetch(ajaxUrl, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    progressFill.style.width = '100%';
                    progressText.textContent = '100%';
                    setTimeout(() => {
                        if (res.success) {
                            alert(res.data.message);
                            window.location.reload();
                        } else {
                            alert(res.data.message || 'インポート失敗');
                            btnExecuteHfImport.disabled = false;
                        }
                    }, 500);
                }).catch(e => {
                    alert('通信エラー');
                    btnExecuteHfImport.disabled = false;
                });
            });
        }

        // Export form submit
        document.getElementById('export-form')?.addEventListener('submit', function(e) {
            // It's a standard form submission for file download, so we don't preventDefault.
            // Just ensure at least one checkbox is checked
            const checked = this.querySelectorAll('input[name="formats[]"]:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('対象フォーマットを選択してください。');
            }
        });

        // Wikipedia Dump JS
        let wikiPollInterval = null;
        
        function pollWikiStatus() {
            const fd = new FormData();
            fd.append('action', 'check_wiki_dump_status');
            fd.append('nonce', uploadNonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success && res.data) {
                        const d = res.data;
                        document.getElementById('wiki-progress-container').style.display = 'block';
                        document.getElementById('wiki-status-text').textContent = d.state === 'processing' ? '処理中' : (d.state === 'downloading' ? 'ダウンロード中' : d.state);
                        document.getElementById('wiki-message-text').textContent = d.message || '';
                        
                        if(d.state === 'completed' || d.state === 'error') {
                            clearInterval(wikiPollInterval);
                            wikiPollInterval = null;
                            document.getElementById('btn-wiki-start').disabled = false;
                            loadWikiFiles();
                        }
                    }
                });
        }
        
        function loadWikiFiles() {
            const fd = new FormData();
            fd.append('action', 'list_wiki_dump_files');
            fd.append('nonce', uploadNonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success && res.data.files) {
                        const tbody = document.getElementById('wiki-files-tbody');
                        if(res.data.files.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#999;">ファイルがありません</td></tr>';
                            return;
                        }
                        
                        let html = '';
                        res.data.files.forEach(f => {
                            const sizeMb = (f.size / 1024 / 1024).toFixed(2);
                            html += `
                                <tr>
                                    <td>${escHtml(f.name)}</td>
                                    <td>${sizeMb} MB</td>
                                    <td>
                                        <button type="button" class="btn-base" style="background:#4CAF50; color:#fff;" onclick="importLocalFileFromUrl('${f.url}')">インポート実行</button>
                                    </td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;
                    }
                });
        }
        
        window.importLocalFileFromUrl = function(url) {
            if(!confirm('このファイルをインポートしますか？（数分かかる場合があります）')) return;
            document.body.style.cursor = 'wait';
            fetch(url)
                .then(r => r.json())
                .then(data => {
                    const finalItems = data.map(item => {
                        return {
                            title: '[Wiki] ' + item.title,
                            format: 'text',
                            data: { text: item.text }
                        };
                    });
                    
                    const fd = new FormData();
                    fd.append('action', 'frontend_learning_data_import_execute');
                    fd.append('nonce', uploadNonce);
                    fd.append('items', JSON.stringify(finalItems));
                    
                    fetch(ajaxUrl, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            document.body.style.cursor = 'default';
                            if(res.success) {
                                alert('インポート完了');
                                window.location.reload();
                            } else {
                                alert(res.data.message || 'インポート失敗');
                            }
                        }).catch(() => {
                            document.body.style.cursor = 'default';
                            alert('通信エラー');
                        });
                });
        };

        document.getElementById('btn-wiki-start')?.addEventListener('click', function() {
            const url = document.getElementById('wiki-dump-url').value.trim();
            const chunkSize = document.getElementById('wiki-dump-chunk').value;
            
            if(!url) {
                alert('URLを入力してください。');
                return;
            }
            
            this.disabled = true;
            document.getElementById('wiki-progress-container').style.display = 'block';
            document.getElementById('wiki-status-text').textContent = '起動中...';
            document.getElementById('wiki-message-text').textContent = '';
            
            const fd = new FormData();
            fd.append('action', 'start_wiki_dump_process');
            fd.append('nonce', uploadNonce);
            fd.append('url', url);
            fd.append('chunk_size', chunkSize);
            
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        if(wikiPollInterval) clearInterval(wikiPollInterval);
                        wikiPollInterval = setInterval(pollWikiStatus, 3000);
                    } else {
                        alert(res.data.message || 'エラーが発生しました');
                        this.disabled = false;
                    }
                }).catch(() => {
                    alert('通信エラー');
                    this.disabled = false;
                });
        });

        document.getElementById('btn-wiki-refresh')?.addEventListener('click', loadWikiFiles);
        
        // Commons Dump JS
        let commonsPollInterval = null;
        
        function pollCommonsStatus() {
            const fd = new FormData();
            fd.append('action', 'check_commons_dump_status');
            fd.append('nonce', uploadNonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success && res.data) {
                        const d = res.data;
                        document.getElementById('commons-progress-container').style.display = 'block';
                        document.getElementById('commons-status-text').textContent = d.state === 'processing' || d.state === 'running' ? '処理中' : (d.state === 'downloading' ? 'ダウンロード中' : d.state);
                        document.getElementById('commons-message-text').textContent = d.message || '';
                        
                        if(d.state === 'completed' || d.state === 'error') {
                            clearInterval(commonsPollInterval);
                            commonsPollInterval = null;
                            document.getElementById('btn-commons-start').disabled = false;
                            loadCommonsFiles();
                        }
                    }
                });
        }
        
        function loadCommonsFiles() {
            const fd = new FormData();
            fd.append('action', 'list_commons_dump_files');
            fd.append('nonce', uploadNonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success && res.data.files) {
                        const tbody = document.getElementById('commons-files-tbody');
                        if(res.data.files.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#999;">ファイルがありません</td></tr>';
                            return;
                        }
                        
                        let html = '';
                        res.data.files.forEach(f => {
                            const sizeMb = (f.size / 1024 / 1024).toFixed(2);
                            html += `
                                <tr>
                                    <td>${escHtml(f.name)}</td>
                                    <td>${sizeMb} MB</td>
                                    <td>
                                        <button type="button" class="btn-base btn-primary" onclick="importLocalJson('${escHtml(f.path)}', 'structured')" style="padding:0.25rem 0.5rem; font-size:0.8rem;">インポート</button>
                                    </td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;
                    }
                });
        }

        document.getElementById('btn-commons-start')?.addEventListener('click', function() {
            const url = document.getElementById('commons-dump-url').value.trim();
            const chunkSize = document.getElementById('commons-dump-chunk').value;
            
            if(!url) {
                alert('URLを入力してください。');
                return;
            }
            
            this.disabled = true;
            document.getElementById('commons-progress-container').style.display = 'block';
            document.getElementById('commons-status-text').textContent = '起動中...';
            document.getElementById('commons-message-text').textContent = '';
            
            const fd = new FormData();
            fd.append('action', 'start_commons_dump_process');
            fd.append('nonce', uploadNonce);
            fd.append('url', url);
            fd.append('chunk_size', chunkSize);
            
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        if(commonsPollInterval) clearInterval(commonsPollInterval);
                        commonsPollInterval = setInterval(pollCommonsStatus, 3000);
                    } else {
                        alert(res.data.message || 'エラーが発生しました');
                        this.disabled = false;
                    }
                }).catch(() => {
                    alert('通信エラー');
                    this.disabled = false;
                });
        });

        document.getElementById('btn-commons-refresh')?.addEventListener('click', loadCommonsFiles);
        
        // Initial load
        if (document.getElementById('btn-wiki-start')) {
            loadWikiFiles();
        }
        if (document.getElementById('btn-commons-start')) {
            loadCommonsFiles();
        }
        
        // Web Scrape JS
        function checkBrowserlessStatus() {
            const fd = new FormData();
            fd.append('action', 'check_browserless_status');
            fd.append('nonce', uploadNonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(!res.success || !res.data.available) {
                        document.getElementById('webscrape-form').style.display = 'none';
                        document.getElementById('webscrape-alert').style.display = 'block';
                    }
                }).catch(() => {
                    document.getElementById('webscrape-form').style.display = 'none';
                    document.getElementById('webscrape-alert').style.display = 'block';
                });
        }

        document.getElementById('btn-webscrape-start')?.addEventListener('click', function() {
            const url = document.getElementById('webscrape-url').value.trim();
            if(!url) {
                alert('URLを入力してください。');
                return;
            }
            
            this.disabled = true;
            document.getElementById('webscrape-progress-container').style.display = 'block';
            document.getElementById('webscrape-status-text').textContent = '処理中...';
            
            const fd = new FormData();
            fd.append('action', 'start_web_scrape');
            fd.append('nonce', uploadNonce);
            fd.append('url', url);
            
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        document.getElementById('webscrape-status-text').textContent = '完了';
                        alert('データが正常に取得・登録されました。\\n（画面上部のメニュー「シート一覧」から確認できます）');
                        document.getElementById('webscrape-url').value = '';
                    } else {
                        document.getElementById('webscrape-status-text').textContent = 'エラー';
                        alert(res.data.message || 'エラーが発生しました');
                    }
                    this.disabled = false;
                }).catch(() => {
                    document.getElementById('webscrape-status-text').textContent = 'エラー';
                    alert('通信エラー');
                    this.disabled = false;
                });
        });

        if (document.getElementById('btn-webscrape-start')) {
            checkBrowserlessStatus();
        }

        function escHtml(str) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        // LaTeX (KaTeX) レンダリングを実行
        if (typeof renderMathInElement === 'function') {
            renderMathInElement(document.body, {
                delimiters: [
                    {left: '$$', right: '$$', display: true},
                    {left: '$', right: '$', display: false},
                    {left: '\\(', right: '\\)', display: false},
                    {left: '\\[', right: '\\]', display: true}
                ],
                throwOnError: false
            });
        }
    });
</script>

<?php get_footer(); ?>
