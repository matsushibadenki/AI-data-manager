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

/* ドラッグ&ドロップ領域 */
.drop-zone {
    border: 2px dashed var(--border-subtle, #ccc);
    border-radius: var(--radius-lg, 8px);
    padding: 3rem 1rem;
    text-align: center;
    background: var(--bg-body, #fafafa);
    transition: all 0.3s ease;
    cursor: pointer;
    margin-bottom: 1.5rem;
}
.drop-zone.dragover {
    border-color: var(--accent, #C9A96E);
    background: var(--accent-subtle, #fcfaf5);
}
.drop-zone .material-symbols-outlined {
    font-size: 3rem;
    color: var(--text-secondary, #999);
    margin-bottom: 1rem;
}
.drop-zone input[type="file"] {
    display: none;
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
                    <button type="submit" name="login_submit" class="btn-black" style="width:100%;">
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

            <!-- インポートセクション -->
            <section class="panel-section">
                <h3 class="panel-title">
                    <span class="material-symbols-outlined">upload_file</span>
                    <?php echo esc_html__('データインポート', 'fourier'); ?>
                </h3>

                <div class="drop-zone" id="drop-zone">
                    <span class="material-symbols-outlined">cloud_upload</span>
                    <p style="margin:0; font-weight:600; font-size:1.1rem;"><?php echo esc_html__('ここにファイルをドラッグ＆ドロップ', 'fourier'); ?></p>
                    <p style="color:var(--text-secondary); font-size:0.85rem; margin-top:0.5rem;">
                        <?php echo esc_html__('対応フォーマット: JSONL, JSON, CSV', 'fourier'); ?>
                    </p>
                    <button class="btn-black" style="margin-top: 1rem;" onclick="document.getElementById('file-input').click()">
                        <?php echo esc_html__('ファイルを選択', 'fourier'); ?>
                    </button>
                    <input type="file" id="file-input" accept=".jsonl,.json,.csv" />
                </div>

                <!-- テンプレートダウンロード -->
                <style>
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
                        <button type="button" id="btn-execute-import" class="btn-black" style="background: var(--text-primary); color: #fff;">
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

            <!-- エクスポートセクション -->
            <section class="panel-section">
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

                    <div style="margin-top: 2rem;">
                        <button type="submit" class="btn-black">
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

            dropZone.innerHTML = '<span class="material-symbols-outlined" style="font-size:3rem; color:var(--accent);">hourglass_empty</span><p>プレビューを生成中...</p>';

            const fd = new FormData();
            fd.append('action', 'frontend_learning_data_import_preview');
            fd.append('nonce', uploadNonce);
            fd.append('import_file', file);

            fetch(ajaxUrl, {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(res => {
                // reset dropzone
                dropZone.innerHTML = '<span class="material-symbols-outlined">cloud_upload</span><p style="margin:0; font-weight:600; font-size:1.1rem;">ここにファイルをドラッグ＆ドロップ</p><p style="color:var(--text-secondary); font-size:0.85rem; margin-top:0.5rem;">対応フォーマット: JSONL, JSON, CSV</p><button class="btn-black" style="margin-top: 1rem;" onclick="document.getElementById(\'file-input\').click()">ファイルを選択</button>';
                
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
            let format = 'structured';
            if (raw.instruction && raw.output) format = 'instruction';
            else if (raw.messages && Array.isArray(raw.messages)) format = 'chatml';
            else if (raw.conversations && Array.isArray(raw.conversations)) format = 'sharegpt';
            else if (raw.question && raw.thought && raw.answer) format = 'cot';
            else if (raw.prompt && raw.chosen && raw.rejected) format = 'dpo';
            else if (raw.html || raw.css || raw.js) format = 'frontend_code';
            else if (raw.text && Object.keys(raw).length === 1) format = 'plain';

            return {
                title: raw.title || '',
                format: format,
                data: raw
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
