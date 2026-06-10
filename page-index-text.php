<?php
/*
 * Name: page-index-text.php
 * Description: 登録されたテキスト学習データをシート（テーブル）形式で表示するページ。LaTeX数式表示に対応。
 * Template Name: Text Data Index
 */

// 認証状態の確認
$is_authenticated = is_user_logged_in();

// ログイン処理
$login_error = '';
if (!$is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_login_submit'])) {
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

// 認証済みの場合、データを取得
$grouped_data = [
    'plain' => [],
    'instruction' => [],
    'chatml' => [],
    'sharegpt' => [],
    'cot' => [],
    'dpo' => [],
    'frontend_code' => [],
    'structured' => []
];

if ($is_authenticated) {
    $args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1, // 全件取得
        'meta_query'     => array(
            array(
                'key'   => 'is_learning_data',
                'value' => '1',
            )
        )
    );

    $query = new WP_Query($args);
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $title = get_the_title();
            $date = get_the_date('Y/m/d H:i');

            $content = get_the_content();
            $decoded = json_decode($content, true);

            if ($decoded && isset($decoded['format']) && isset($decoded['data'])) {
                $format = $decoded['format'];
                $data = $decoded['data'];

                $item = [
                    'id' => $post_id,
                    'title' => $title,
                    'date' => $date,
                    'data' => $data
                ];

                if (isset($grouped_data[$format])) {
                    $grouped_data[$format][] = $item;
                } else {
                    $grouped_data['structured'][] = $item; // 不明なフォーマットは構造化データへ
                }
            }
        }
    }
    wp_reset_postdata();
}

get_header();
?>

<style>
    /* 既存のレイアウト制限を上書きして全画面幅にする */
    body {
        margin: 0;
        padding: 0;
    }

    .site-content-wrapper,
    #primary,
    .upload-page-container {
        max-width: 100% !important;
        width: 100% !important;
        padding: 2rem 5% !important;
        box-sizing: border-box;
    }

    button.btn-black {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.85rem;
        line-height: 1;
        text-decoration: none;
        background-color: var(--bg-surface, #fff);
        color: var(--text-primary, #333);
        border: 1px solid var(--border-subtle, #ccc);
        padding: 0.7rem 1.8rem;
        border-radius: var(--radius-full, 999px);
        font-weight: 400;
        letter-spacing: 0.03em;
        transition: all var(--transition-base, 0.2s);
        cursor: pointer;
    }
    button.btn-black:hover:not(:disabled) {
        color: var(--accent, #C9A96E);
        border-color: var(--accent, #C9A96E);
        background-color: var(--accent-subtle, rgba(201,169,110,0.1));
        box-shadow: var(--shadow-gold, 0 2px 8px rgba(201,169,110,0.2));
        transform: translateY(-1px);
    }
    button.btn-black:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .action-btn {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        width: 2rem;
        height: 2rem;
        padding: 0;
        margin: 0;
        border-radius: 4px;
        text-decoration: none;
        color: var(--text-primary, #333);
        border: 1px solid var(--border-subtle, #ccc);
        background: var(--bg-surface, #fff);
        transition: all 0.2s;
        cursor: pointer;
    }
    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .action-btn span {
        font-size: 1.1rem !important;
        line-height: 1;
    }

    /* ヘッダー周りの調整 */
    .full-screen-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid var(--border-subtle, #eee);
    }

    .learning-tabs {
        display: flex;
        gap: 0.5rem;
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
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border-subtle, #eee);
    }

    .learning-tab-content {
        display: none;
        background: var(--bg-surface, #fff);
        /* シート部分が画面いっぱいに広がるように高さを調整 */
        height: calc(100vh - 250px);
        overflow: auto;
        border: 1px solid var(--border-subtle, #eee);
        border-radius: var(--radius-lg, 8px);
    }

    .learning-tab-content.active {
        display: block;
    }

    /* Data Table Styles (Excel/Spreadsheet like) */
    .data-sheet {
        width: 100%;
        border-collapse: collapse;
        min-width: 1200px;
        /* 横スクロールを許容する最小幅 */
        font-size: 0.85rem;
    }

    .data-sheet th,
    .data-sheet td {
        border: 1px solid var(--border-subtle, #ccc);
        padding: 0.5rem 0.75rem;
        text-align: left;
        vertical-align: top;
    }

    .data-sheet th {
        background: var(--bg-surface-hover, #f5f5f5);
        font-weight: 600;
        color: var(--text-primary, #000);
        position: sticky;
        top: 0;
        z-index: 10;
        box-shadow: 0 1px 0 var(--border-subtle, #ccc);
    }

    .data-sheet td {
        background: var(--bg-surface, #fff);
        color: var(--text-secondary, #333);
        word-break: break-word;
    }

    .data-sheet td pre,
    .data-sheet td .sheet-pre {
        margin: 0;
        white-space: pre-wrap;
        font-size: 0.85rem;
        max-height: 200px;
        overflow-y: auto;
        background: transparent;
        padding: 0;
        border-radius: 0;
    }

    .data-sheet td pre {
        font-family: monospace;
        font-size: 0.8rem;
    }

    .data-sheet td .sheet-pre {
        font-family: var(--font-primary, 'Inter', 'Noto Sans JP', sans-serif);
    }

    .col-id {
        width: 60px;
    }

    .col-date {
        width: 140px;
    }

    .col-title {
        width: 200px;
    }

    /* 特定の列で改行を防ぐ */
    .data-sheet th:nth-child(1),
    .data-sheet td:nth-child(1),
    .data-sheet th:nth-child(2),
    .data-sheet td:nth-child(2) {
        white-space: nowrap;
    }

    /* その他UIの調整 */
    .upload-title {
        margin-top: 1rem;
        margin-bottom: 0.25rem;
    }

    .upload-desc {
        margin-bottom: 0;
    </style>

    <!-- バリエーション生成モーダル -->
    <div id="variation-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--bg-surface, #fff); padding: 2rem; border-radius: 8px; max-width: 500px; width: 90%; position: relative;">
            <h2 style="margin-top:0; border-bottom: 2px solid var(--border-subtle, #eee); padding-bottom: 0.5rem;">🪄 バリエーション生成</h2>
            <input type="hidden" id="var-post-id" value="">
            
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem;">LLMプロバイダ</label>
                <select id="var-provider" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-subtle, #ccc); border-radius: 4px;">
                    <option value="openai">OpenAI (設定したDefault Model)</option>
                    <option value="gemini">Google Gemini (設定したDefault Model)</option>
                    <option value="ollama">Ollama (ローカル)</option>
                    <option value="custom">Llama.cpp / Custom (ローカル)</option>
                </select>
                <div style="font-size: 0.8rem; color: var(--text-secondary, #666); margin-top: 0.3rem;">※ 事前に「API設定」でAPIキーやURLを設定してください。</div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem;">生成数</label>
                <input type="number" id="var-count" value="3" min="1" max="10" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-subtle, #ccc); border-radius: 4px;">
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem;">追加の指示 (任意)</label>
                <textarea id="var-prompt" rows="3" placeholder="例: よりカジュアルなトーンで。 / 初心者向けにやさしく。" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-subtle, #ccc); border-radius: 4px;"></textarea>
            </div>

            <div id="var-status-message" style="margin-bottom: 1rem; font-size: 0.9rem; font-weight: bold;"></div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button type="button" id="btn-var-cancel" class="btn-black" style="opacity: 0.7; background: #666;">キャンセル</button>
                <button type="button" id="btn-var-submit" class="btn-black">生成して保存</button>
            </div>
        </div>
    </div>

    <!-- 蒸留用モーダル -->
    <div id="distill-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--bg-surface, #fff); padding: 2rem; border-radius: 8px; max-width: 500px; width: 90%; position: relative;">
            <h2 style="margin-top:0; border-bottom: 2px solid var(--border-subtle, #eee); padding-bottom: 0.5rem;">🧪 データ蒸留 (Distillation)</h2>
            <input type="hidden" id="distill-post-id" value="">
            
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem;">LLMプロバイダ</label>
                <select id="distill-provider" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-subtle, #ccc); border-radius: 4px;">
                    <option value="openai">OpenAI (設定したDefault Model)</option>
                    <option value="gemini">Google Gemini (設定したDefault Model)</option>
                    <option value="ollama">Ollama (ローカル)</option>
                    <option value="custom">Llama.cpp / Custom (ローカル)</option>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem;">蒸留アプローチ (変換モード)</label>
                <select id="distill-strategy" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-subtle, #ccc); border-radius: 4px;">
                    <option value="refine">🌟 高品質化 (内容の精製・詳細化)</option>
                    <option value="extract">✂️ Q&A抽出 (Instruction形式へ変換)</option>
                    <option value="cot">🧠 CoT付与 (思考プロセスの追加)</option>
                </select>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.3rem;">追加の指示 (任意)</label>
                <textarea id="distill-prompt" rows="3" placeholder="例: 特に技術的な正確性を重視して出力して。" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-subtle, #ccc); border-radius: 4px;"></textarea>
            </div>

            <div id="distill-status-message" style="margin-bottom: 1rem; font-size: 0.9rem; font-weight: bold;"></div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button type="button" id="btn-distill-cancel" class="btn-black" style="opacity: 0.7; background: #666;">キャンセル</button>
                <button type="button" id="btn-distill-submit" class="btn-black">蒸留して保存</button>
            </div>
        </div>
    </div>

    <div id="primary" class="upload-page-container">
        <?php if (!$is_authenticated) : ?>
            <!-- ログイン画面 -->
            <div class="upload-login-wrapper">
                <div class="upload-login-box">
                    <?php
                    $logo_url = get_template_directory_uri() . '/assets/img/logo_tokushiikusya_main.svg';
                    ?>
                    <img src="<?php echo esc_url($logo_url); ?>" alt="Site Logo" class="upload-login-logo" />
                    <h2 class="upload-login-title"><?php echo esc_html__('データ閲覧ログイン', 'fourier'); ?></h2>
                    <p class="upload-login-subtitle"><?php echo esc_html__('認証情報を入力してログインしてください。', 'fourier'); ?></p>

                    <?php if (!empty($login_error)) : ?>
                        <div class="upload-login-error"><?php echo $login_error; ?></div>
                    <?php endif; ?>

                    <form method="post" action="" class="upload-login-form" autocomplete="off">
                        <div class="upload-form-group">
                            <label for="username"><?php echo esc_html__('ユーザー名', 'fourier'); ?></label>
                            <input type="text" name="username" id="username" class="upload-form-input" required autofocus />
                        </div>
                        <div class="upload-form-group">
                            <label for="password"><?php echo esc_html__('パスワード', 'fourier'); ?></label>
                            <input type="password" name="password" id="password" class="upload-form-input" required />
                        </div>
                        <button type="submit" name="upload_login_submit" class="btn-black upload-login-btn">
                            <?php echo esc_html__('ログイン', 'fourier'); ?>
                        </button>
                    </form>
                    <div style="margin-top: 1.5rem; text-align: center; font-size: 0.8rem; color: var(--text-secondary);">
                        <p style="margin-bottom: 0.5rem;"><?php echo esc_html__('※新規登録は管理者へお問い合わせください', 'fourier'); ?></p>
                        <a href="#" style="color: var(--text-secondary); text-decoration: underline; margin-right: 0.5rem;"><?php echo esc_html__('プライバシーポリシー', 'fourier'); ?></a>
                        <a href="#" style="color: var(--text-secondary); text-decoration: underline;"><?php echo esc_html__('利用規約', 'fourier'); ?></a>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <!-- メイン画面 -->


            <div class="full-screen-header" style="border-bottom: none; margin-bottom: 1.5rem; padding-bottom: 0;">
                <div>
                    <h2 class="upload-title" style="margin-top: 0; margin-bottom: 0.25rem;"><?php echo esc_html__('登録データ一覧シート', 'fourier'); ?></h2>
                    <p class="upload-desc" style="margin-bottom: 0;"><?php echo esc_html__('フォーマット別に登録された学習データを閲覧できます。', 'fourier'); ?></p>
                </div>
            </div>

            <div class="upload-controls" style="flex-direction: column; align-items: stretch; margin-bottom: 2rem;">
                <div class="learning-tabs">
                    <button type="button" class="learning-tab active" data-target="tab-plain">プレーンテキスト (<?php echo count($grouped_data['plain']); ?>)</button>
                    <button type="button" class="learning-tab" data-target="tab-instruction">Instruction (<?php echo count($grouped_data['instruction']); ?>)</button>
                    <button type="button" class="learning-tab" data-target="tab-chatml">ChatML (<?php echo count($grouped_data['chatml']); ?>)</button>
                    <button type="button" class="learning-tab" data-target="tab-sharegpt">ShareGPT (<?php echo count($grouped_data['sharegpt']); ?>)</button>
                    <button type="button" class="learning-tab" data-target="tab-cot">CoT (<?php echo count($grouped_data['cot']); ?>)</button>
                    <button type="button" class="learning-tab" data-target="tab-dpo">DPO / RLHF (<?php echo count($grouped_data['dpo']); ?>)</button>
                    <button type="button" class="learning-tab" data-target="tab-frontend">HTML/CSS/JS (<?php echo count($grouped_data['frontend_code']); ?>)</button>
                    <button type="button" class="learning-tab" data-target="tab-structured">構造化データ (<?php echo count($grouped_data['structured']); ?>)</button>
                </div>

                <!-- 1. プレーンテキスト -->
                <div id="tab-plain" class="learning-tab-content active">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-date">登録日時</th>
                                <th class="col-title">タイトル</th>
                                <th>テキスト本文</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_data['plain'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id']); ?></td>
                                    <td><?php echo esc_html($item['date']); ?></td>
                                    <td><?php echo esc_html($item['title']); ?></td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['text']) ? $item['data']['text'] : ''); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($grouped_data['plain'])): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;">データがありません</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 2. Instruction形式 -->
                <div id="tab-instruction" class="learning-tab-content">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-date">登録日時</th>
                                <th class="col-title">タイトル</th>
                                <th style="width: 25%;">Instruction</th>
                                <th style="width: 25%;">Input</th>
                                <th>Output</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_data['instruction'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id']); ?></td>
                                    <td><?php echo esc_html($item['date']); ?></td>
                                    <td><?php echo esc_html($item['title']); ?></td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['instruction']) ? $item['data']['instruction'] : ''); ?></div>
                                    </td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['input']) ? $item['data']['input'] : ''); ?></div>
                                    </td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['output']) ? $item['data']['output'] : ''); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($grouped_data['instruction'])): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;">データがありません</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 3. ChatML形式 -->
                <div id="tab-chatml" class="learning-tab-content">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-date">登録日時</th>
                                <th class="col-title">タイトル</th>
                                <th>対話データ (Messages)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_data['chatml'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id']); ?></td>
                                    <td><?php echo esc_html($item['date']); ?></td>
                                    <td><?php echo esc_html($item['title']); ?></td>
                                    <td>
                                        <?php
                                        if (isset($item['data']['messages']) && is_array($item['data']['messages'])) {
                                            foreach ($item['data']['messages'] as $msg) {
                                                echo '<div style="margin-bottom:0.5rem;">';
                                                echo '<strong>' . esc_html($msg['role']) . ':</strong><br>';
                                                echo nl2br(esc_html($msg['content']));
                                                echo '</div>';
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($grouped_data['chatml'])): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;">データがありません</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 4. ShareGPT形式 -->
                <div id="tab-sharegpt" class="learning-tab-content">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-date">登録日時</th>
                                <th class="col-title">タイトル</th>
                                <th>対話データ (Conversations)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_data['sharegpt'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id']); ?></td>
                                    <td><?php echo esc_html($item['date']); ?></td>
                                    <td><?php echo esc_html($item['title']); ?></td>
                                    <td>
                                        <?php
                                        if (isset($item['data']['conversations']) && is_array($item['data']['conversations'])) {
                                            foreach ($item['data']['conversations'] as $conv) {
                                                echo '<div style="margin-bottom:0.5rem;">';
                                                echo '<strong>' . esc_html($conv['from']) . ':</strong><br>';
                                                echo nl2br(esc_html($conv['value']));
                                                echo '</div>';
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($grouped_data['sharegpt'])): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;">データがありません</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 5. 思考過程(CoT) -->
                <div id="tab-cot" class="learning-tab-content">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-date">登録日時</th>
                                <th class="col-title">タイトル</th>
                                <th style="width: 25%;">Question</th>
                                <th style="width: 30%;">Thought</th>
                                <th>Answer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_data['cot'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id']); ?></td>
                                    <td><?php echo esc_html($item['date']); ?></td>
                                    <td><?php echo esc_html($item['title']); ?></td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['question']) ? $item['data']['question'] : ''); ?></div>
                                    </td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['thought']) ? $item['data']['thought'] : ''); ?></div>
                                    </td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['answer']) ? $item['data']['answer'] : ''); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($grouped_data['cot'])): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;">データがありません</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 5.5 DPO / RLHF -->
                <div id="tab-dpo" class="learning-tab-content">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-date">登録日時</th>
                                <th class="col-title">タイトル</th>
                                <th style="width: 25%;">Prompt</th>
                                <th style="width: 25%;">Chosen</th>
                                <th>Rejected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_data['dpo'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id']); ?></td>
                                    <td><?php echo esc_html($item['date']); ?></td>
                                    <td><?php echo esc_html($item['title']); ?></td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['prompt']) ? $item['data']['prompt'] : ''); ?></div>
                                    </td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['chosen']) ? $item['data']['chosen'] : ''); ?></div>
                                    </td>
                                    <td>
                                        <div class="sheet-pre"><?php echo esc_html(isset($item['data']['rejected']) ? $item['data']['rejected'] : ''); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($grouped_data['dpo'])): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;">データがありません</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 6. フロントエンドコード (HTML/CSS/JS) -->
                <div id="tab-frontend" class="learning-tab-content">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-date">登録日時</th>
                                <th class="col-title">タイトル</th>
                                <th style="width: 15%;">説明</th>
                                <th style="width: 25%;">HTML</th>
                                <th style="width: 20%;">CSS</th>
                                <th style="width: 20%;">JavaScript</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_data['frontend_code'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id']); ?></td>
                                    <td><?php echo esc_html($item['date']); ?></td>
                                    <td><?php echo esc_html($item['title']); ?></td>
                                    <td><?php echo nl2br(esc_html(isset($item['data']['explanation']) ? $item['data']['explanation'] : '')); ?></td>
                                    <td>
                                        <pre><?php echo esc_html(isset($item['data']['html']) ? $item['data']['html'] : ''); ?></pre>
                                    </td>
                                    <td>
                                        <pre><?php echo esc_html(isset($item['data']['css']) ? $item['data']['css'] : ''); ?></pre>
                                    </td>
                                    <td>
                                        <pre><?php echo esc_html(isset($item['data']['js']) ? $item['data']['js'] : ''); ?></pre>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($grouped_data['frontend_code'])): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center;">データがありません</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 7. 構造化データ -->
                <div id="tab-structured" class="learning-tab-content">
                    <table class="data-sheet">
                        <thead>
                            <tr>
                                <th class="col-id">ID</th>
                                <th class="col-date">登録日時</th>
                                <th class="col-title">タイトル</th>
                                <th>JSONデータ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_data['structured'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id']); ?></td>
                                    <td><?php echo esc_html($item['date']); ?></td>
                                    <td><?php echo esc_html($item['title']); ?></td>
                                    <td>
                                        <pre><?php echo esc_html(json_encode($item['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($grouped_data['structured'])): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;">データがありません</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="back-home">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="btn-black">
                    <span class="material-symbols-outlined">arrow_back</span>
                    <?php echo esc_html__('フロントページに戻る', 'fourier'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.learning-tab');
        const contents = document.querySelectorAll('.learning-tab-content');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));

                tab.classList.add('active');
                const targetId = tab.getAttribute('data-target');
                document.getElementById(targetId).classList.add('active');
            });
        });

        // 動的にアクション列を追加
        const tables = document.querySelectorAll('.data-sheet');
        const editBaseUrl = '<?php echo esc_url(home_url('/text-based-learning/')); ?>';

        tables.forEach(table => {
            const theadTr = table.querySelector('thead tr');
            if (theadTr) {
                const th = document.createElement('th');
                th.textContent = 'アクション';
                th.style.width = '140px';
                th.style.textAlign = 'center';
                th.style.verticalAlign = 'middle';
                th.style.padding = '0.5rem';
                th.style.whiteSpace = 'nowrap';
                theadTr.appendChild(th);
            }

            const tbodyTrs = table.querySelectorAll('tbody tr');
            tbodyTrs.forEach(tr => {
                const tdFirst = tr.querySelector('td:first-child');
                // データがない場合のcolspan行をスキップ
                if (tr.querySelector('td[colspan]')) {
                    const tdColspan = tr.querySelector('td[colspan]');
                    tdColspan.setAttribute('colspan', parseInt(tdColspan.getAttribute('colspan')) + 1);
                    return;
                }
                if (tdFirst) {
                    const id = tdFirst.textContent.trim();
                    const td = document.createElement('td');
                    td.style.textAlign = 'center';
                    td.style.verticalAlign = 'middle';
                    td.style.padding = '0.2rem';
                    td.innerHTML = `
                        <div style="display: flex; gap: 0.5rem; justify-content: center; align-items: center;">
                            <a href="${editBaseUrl}?edit_id=${id}" class="action-btn" title="編集">
                                <span class="material-symbols-outlined">edit</span>
                            </a>
                            <button type="button" class="action-btn btn-open-var" data-id="${id}" style="background: #C9A96E; border-color: #C9A96E; color: #fff;" title="バリエーション生成">
                                <span class="material-symbols-outlined">auto_awesome</span>
                            </button>
                            <button type="button" class="action-btn btn-open-distill" data-id="${id}" style="background: #6b4c9a; border-color: #6b4c9a; color: #fff;" title="データ蒸留">
                                <span class="material-symbols-outlined">science</span>
                            </button>
                        </div>
                    `;
                    tr.appendChild(td);
                }
            });
        });

        // LaTeX (KaTeX) レンダリングを実行
        if (typeof renderMathInElement === 'function') {
            renderMathInElement(document.body, {
                delimiters: [{
                        left: '$$',
                        right: '$$',
                        display: true
                    },
                    {
                        left: '$',
                        right: '$',
                        display: false
                    },
                    {
                        left: '\\(',
                        right: '\\)',
                        display: false
                    },
                    {
                        left: '\\[',
                        right: '\\]',
                        display: true
                    }
                ],
                throwOnError: false
            });
        }

        // バリエーション生成ロジック
        const varModal = document.getElementById('variation-modal');
        const varPostIdInput = document.getElementById('var-post-id');
        const btnVarCancel = document.getElementById('btn-var-cancel');
        const btnVarSubmit = document.getElementById('btn-var-submit');
        const varStatusMsg = document.getElementById('var-status-message');
        const varNonce = "<?php echo wp_create_nonce('learning_data_action'); ?>";
        const ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";

        document.querySelectorAll('.btn-open-var').forEach(btn => {
            btn.addEventListener('click', function() {
                varPostIdInput.value = this.getAttribute('data-id');
                varModal.style.display = 'flex';
                varStatusMsg.textContent = '';
                varStatusMsg.style.color = '';
            });
        });

        btnVarCancel.addEventListener('click', () => {
            varModal.style.display = 'none';
        });

        btnVarSubmit.addEventListener('click', () => {
            const postId = varPostIdInput.value;
            const provider = document.getElementById('var-provider').value;
            const count = document.getElementById('var-count').value;
            const extraPrompt = document.getElementById('var-prompt').value;

            btnVarSubmit.disabled = true;
            btnVarSubmit.innerHTML = '<span class="material-symbols-outlined" style="animation: spin 1s linear infinite;">autorenew</span> 生成中...';
            varStatusMsg.textContent = 'LLMにリクエストを送信しています...しばらくお待ちください。';
            varStatusMsg.style.color = 'var(--text-primary)';

            const formData = new FormData();
            formData.append('action', 'frontend_learning_data_generate_variation');
            formData.append('nonce', varNonce);
            formData.append('post_id', postId);
            formData.append('provider', provider);
            formData.append('count', count);
            formData.append('extra_prompt', extraPrompt);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    varStatusMsg.textContent = data.data.message;
                    varStatusMsg.style.color = 'green';
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    varStatusMsg.textContent = 'エラー: ' + data.data.message;
                    varStatusMsg.style.color = 'red';
                    btnVarSubmit.disabled = false;
                    btnVarSubmit.textContent = '生成して保存';
                }
            })
            .catch(err => {
                varStatusMsg.textContent = '通信エラーが発生しました。';
                varStatusMsg.style.color = 'red';
                btnVarSubmit.disabled = false;
                btnVarSubmit.textContent = '生成して保存';
            });
        });

        // 蒸留ロジック
        const distillModal = document.getElementById('distill-modal');
        const distillPostIdInput = document.getElementById('distill-post-id');
        const btnDistillCancel = document.getElementById('btn-distill-cancel');
        const btnDistillSubmit = document.getElementById('btn-distill-submit');
        const distillStatusMsg = document.getElementById('distill-status-message');

        document.querySelectorAll('.btn-open-distill').forEach(btn => {
            btn.addEventListener('click', function() {
                distillPostIdInput.value = this.getAttribute('data-id');
                distillModal.style.display = 'flex';
                distillStatusMsg.textContent = '';
                distillStatusMsg.style.color = '';
            });
        });

        btnDistillCancel.addEventListener('click', () => {
            distillModal.style.display = 'none';
        });

        btnDistillSubmit.addEventListener('click', () => {
            const postId = distillPostIdInput.value;
            const provider = document.getElementById('distill-provider').value;
            const strategy = document.getElementById('distill-strategy').value;
            const extraPrompt = document.getElementById('distill-prompt').value;

            btnDistillSubmit.disabled = true;
            btnDistillSubmit.innerHTML = '<span class="material-symbols-outlined" style="animation: spin 1s linear infinite;">autorenew</span> 蒸留中...';
            distillStatusMsg.textContent = 'LLMにリクエストを送信しています...しばらくお待ちください。';
            distillStatusMsg.style.color = 'var(--text-primary)';

            const formData = new FormData();
            formData.append('action', 'frontend_learning_data_distill');
            formData.append('nonce', varNonce);
            formData.append('post_id', postId);
            formData.append('provider', provider);
            formData.append('strategy', strategy);
            formData.append('extra_prompt', extraPrompt);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    distillStatusMsg.textContent = data.data.message;
                    distillStatusMsg.style.color = 'green';
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    distillStatusMsg.textContent = 'エラー: ' + data.data.message;
                    distillStatusMsg.style.color = 'red';
                    btnDistillSubmit.disabled = false;
                    btnDistillSubmit.textContent = '蒸留して保存';
                }
            })
            .catch(err => {
                distillStatusMsg.textContent = '通信エラーが発生しました。';
                distillStatusMsg.style.color = 'red';
                btnDistillSubmit.disabled = false;
                btnDistillSubmit.textContent = '蒸留して保存';
            });
        });

        // Add keyframes for spinner if not exists
        if (!document.getElementById('spinner-style')) {
            const style = document.createElement('style');
            style.id = 'spinner-style';
            style.textContent = `@keyframes spin { 100% { transform: rotate(360deg); } }`;
            document.head.appendChild(style);
        }
    });
</script>

<?php
get_footer();
?>