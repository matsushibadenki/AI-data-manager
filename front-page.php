<?php
/*
 * Template Name: front-page
 * Description: LLM学習データの統計ダッシュボード。LaTeX数式表示に対応。
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

// 認証されている場合はサーバーサイドでデータ取得
$dashboard_data = null;
if ($is_authenticated) {
    global $wpdb;
    
    $total_count = 0;
    $format_counts = [
        'plain' => 0, 'instruction' => 0, 'chatml' => 0,
        'sharegpt' => 0, 'cot' => 0, 'frontend_code' => 0, 'structured' => 0
    ];
    $total_chars = 0;

    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [['key' => 'is_learning_data', 'value' => '1']]
    ];
    $query = new WP_Query($args);
    
    if ($query->have_posts()) {
        $total_count = $query->found_posts;
        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();
            $fmt = get_post_meta($id, 'learning_format', true);
            if ($fmt && isset($format_counts[$fmt])) {
                $format_counts[$fmt]++;
            } else {
                // fallbacks
                $content = json_decode(get_the_content(), true);
                if ($content && isset($content['format']) && isset($format_counts[$content['format']])) {
                    $format_counts[$content['format']]++;
                } else {
                    $format_counts['structured']++;
                }
            }

            $chars = get_post_meta($id, 'learning_char_count', true);
            if ($chars) {
                $total_chars += intval($chars);
            } else {
                $total_chars += mb_strlen(get_the_content());
            }
        }
    }

    $daily_counts = [];
    $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
    $sql = "SELECT DATE(post_date) as date, COUNT(*) as count 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'post' AND p.post_status = 'publish' 
            AND pm.meta_key = 'is_learning_data' AND pm.meta_value = '1'
            AND p.post_date >= %s
            GROUP BY DATE(post_date) ORDER BY date ASC";
    $results = $wpdb->get_results($wpdb->prepare($sql, $thirty_days_ago));
    
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $daily_counts[$d] = 0;
    }
    foreach ($results as $row) {
        $daily_counts[$row->date] = intval($row->count);
    }

    $recent = [];
    $query_recent = new WP_Query([
        'post_type' => 'post', 'post_status' => 'publish',
        'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC',
        'meta_query' => [['key' => 'is_learning_data', 'value' => '1']]
    ]);
    if ($query_recent->have_posts()) {
        while ($query_recent->have_posts()) {
            $query_recent->the_post();
            $fmt = get_post_meta(get_the_ID(), 'learning_format', true);
            if (!$fmt) {
                $content = json_decode(get_the_content(), true);
                $fmt = isset($content['format']) ? $content['format'] : 'unknown';
            }
            $recent[] = [
                'ID' => get_the_ID(),
                'title' => get_the_title(),
                'format' => $fmt,
                'date' => get_the_date('Y/m/d H:i')
            ];
        }
    }

    $active_formats_count = 0;
    foreach ($format_counts as $c) {
        if ($c > 0) $active_formats_count++;
    }

    $dashboard_data = [
        'total_count' => $total_count,
        'active_formats' => $active_formats_count,
        'format_counts' => $format_counts,
        'total_chars' => $total_chars,
        'estimated_tokens' => floor($total_chars / 3),
        'daily_counts' => $daily_counts,
        'recent' => $recent
    ];
}

get_header();
?>

<style>
.dashboard-container {
    max-width: 1200px;
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

/* Dashboard Grid */
.dash-grid-top {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}
@media (max-width: 900px) {
    .dash-grid-top { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 500px) {
    .dash-grid-top { grid-template-columns: 1fr; }
}

.stat-card {
    background: var(--bg-surface, #fff);
    padding: 1.5rem;
    border-radius: var(--radius-lg, 8px);
    border: 1px solid var(--border-subtle, #eee);
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    position: relative;
    overflow: hidden;
}
.stat-card::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 3px;
    background: var(--accent, #C9A96E);
    opacity: 0.5;
}
.stat-card .icon {
    position: absolute;
    top: 1.5rem; right: 1.5rem;
    font-size: 2.5rem;
    color: var(--border-subtle, #eee);
}
.stat-label {
    font-size: 0.9rem;
    color: var(--text-secondary, #666);
    font-weight: 600;
    margin-bottom: 0.5rem;
    display: block;
}
.stat-value {
    font-size: 2.2rem;
    font-weight: 700;
    color: var(--text-primary, #000);
    margin: 0;
    line-height: 1;
}

.dash-grid-charts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
}
@media (max-width: 900px) {
    .dash-grid-charts { grid-template-columns: 1fr; }
}

.chart-card {
    background: var(--bg-surface, #fff);
    padding: 1.5rem;
    border-radius: var(--radius-lg, 8px);
    border: 1px solid var(--border-subtle, #eee);
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.chart-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-top: 0;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid var(--border-subtle, #eee);
    padding-bottom: 0.5rem;
}

/* Format Distribution Bars */
.format-bar-row {
    margin-bottom: 1rem;
}
.format-bar-label {
    display: flex;
    justify-content: space-between;
    font-size: 0.85rem;
    margin-bottom: 0.3rem;
}
.format-bar-bg {
    width: 100%;
    height: 12px;
    background: #f1f1f1;
    border-radius: 6px;
    overflow: hidden;
}
.format-bar-fill {
    height: 100%;
    background: var(--accent, #C9A96E);
    width: 0%;
    transition: width 1s ease-out;
    border-radius: 6px;
}

/* Daily Chart */
.daily-chart-container {
    height: 250px;
    display: flex;
    align-items: flex-end;
    gap: 2px;
    padding-top: 20px;
}
.daily-bar {
    flex: 1;
    background: var(--accent, #C9A96E);
    min-height: 1px;
    transition: height 1s ease-out;
    border-radius: 2px 2px 0 0;
    opacity: 0.8;
    position: relative;
}
.daily-bar:hover {
    opacity: 1;
}
.daily-bar-tooltip {
    display: none;
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #333;
    color: #fff;
    padding: 4px 8px;
    font-size: 0.75rem;
    border-radius: 4px;
    white-space: nowrap;
    margin-bottom: 5px;
    z-index: 10;
}
.daily-bar:hover .daily-bar-tooltip {
    display: block;
}

/* Tables */
.table-card {
    background: var(--bg-surface, #fff);
    padding: 1.5rem;
    border-radius: var(--radius-lg, 8px);
    border: 1px solid var(--border-subtle, #eee);
    margin-bottom: 2rem;
}
.data-sheet {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}
.data-sheet th, .data-sheet td {
    border: 1px solid var(--border-subtle, #eee);
    padding: 0.75rem;
    text-align: left;
}
.data-sheet th {
    background: var(--bg-body, #fafafa);
    font-weight: 600;
}

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
}
.btn-black:hover {
    color: var(--accent);
    border-color: var(--accent);
    background-color: var(--accent-subtle);
}
</style>

<main>
    <div class="dashboard-container">
        
        <?php if (!$is_authenticated): ?>
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

            <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <h2 style="margin: 0; display:flex; align-items:center; gap:0.5rem; font-size: 1.8rem;">
                        <span class="material-symbols-outlined" style="font-size: 2rem;">dashboard</span>
                        <?php echo esc_html__('データダッシュボード', 'fourier'); ?>
                    </h2>
                    <p style="margin: 0.5rem 0 0 0; color: var(--text-secondary);">
                        <?php echo esc_html__('LLM学習データの全体的な統計状況を確認します。', 'fourier'); ?>
                    </p>
                </div>
                
                <div style="text-align: right;">
                    <p style="margin: 0; font-size: 0.9rem;">
                        <?php echo esc_html__('ようこそ', 'fourier'); ?>, <strong><?php $current_user = wp_get_current_user(); echo esc_html($current_user->display_name); ?></strong>
                    </p>
                </div>
            </div>

            <!-- Top Stats -->
            <div class="dash-grid-top">
                <div class="stat-card">
                    <span class="material-symbols-outlined icon">database</span>
                    <span class="stat-label"><?php echo esc_html__('総データ数', 'fourier'); ?></span>
                    <p class="stat-value animate-number" data-target="<?php echo esc_attr($dashboard_data['total_count']); ?>">0</p>
                </div>
                <div class="stat-card">
                    <span class="material-symbols-outlined icon">category</span>
                    <span class="stat-label"><?php echo esc_html__('利用フォーマット数', 'fourier'); ?></span>
                    <p class="stat-value animate-number" data-target="<?php echo esc_attr($dashboard_data['active_formats']); ?>">0</p>
                </div>
                <div class="stat-card">
                    <span class="material-symbols-outlined icon">text_fields</span>
                    <span class="stat-label"><?php echo esc_html__('総文字数', 'fourier'); ?></span>
                    <p class="stat-value animate-number" data-target="<?php echo esc_attr($dashboard_data['total_chars']); ?>">0</p>
                </div>
                <div class="stat-card">
                    <span class="material-symbols-outlined icon">token</span>
                    <span class="stat-label"><?php echo esc_html__('推定トークン数', 'fourier'); ?></span>
                    <p class="stat-value animate-number" data-target="<?php echo esc_attr($dashboard_data['estimated_tokens']); ?>">0</p>
                </div>
            </div>

            <!-- Charts -->
            <div class="dash-grid-charts">
                <!-- Left: Formats -->
                <div class="chart-card">
                    <h3 class="chart-title"><?php echo esc_html__('フォーマット分布', 'fourier'); ?></h3>
                    <div id="format-chart-container">
                        <!-- JS renders here -->
                    </div>
                </div>

                <!-- Right: Daily -->
                <div class="chart-card">
                    <h3 class="chart-title"><?php echo esc_html__('直近30日の登録推移', 'fourier'); ?></h3>
                    <div class="daily-chart-container" id="daily-chart-container">
                        <!-- JS renders here -->
                    </div>
                </div>
            </div>

            <!-- Recent -->
            <div class="table-card">
                <h3 class="chart-title"><?php echo esc_html__('最近登録されたデータ', 'fourier'); ?></h3>
                <table class="data-sheet">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?php echo esc_html__('タイトル', 'fourier'); ?></th>
                            <th><?php echo esc_html__('フォーマット', 'fourier'); ?></th>
                            <th><?php echo esc_html__('登録日時', 'fourier'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dashboard_data['recent'])): ?>
                            <tr><td colspan="4" style="text-align:center;"><?php echo esc_html__('データがありません。', 'fourier'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($dashboard_data['recent'] as $post): ?>
                            <tr>
                                <td><?php echo esc_html($post['ID']); ?></td>
                                <td><?php echo esc_html($post['title']); ?></td>
                                <td><span style="background:var(--accent-subtle); padding:2px 6px; border-radius:4px; font-size:0.85rem;"><?php echo esc_html($post['format']); ?></span></td>
                                <td><?php echo esc_html($post['date']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Account Deletion Section (For App Store Compliance) -->
            <?php if (!in_array('administrator', (array) wp_get_current_user()->roles)): ?>
            <div class="table-card" style="border-color: #fecaca; background: #fef2f2;">
                <h3 class="chart-title" style="color: #991b1b; border-bottom-color: #fecaca;"><?php echo esc_html__('アカウント管理', 'fourier'); ?></h3>
                <p style="font-size: 0.9rem; color: #7f1d1d; margin-bottom: 1rem;">
                    <?php echo esc_html__('アカウントを削除すると、紐づくすべてのデータが完全に消去され、復元することはできません。', 'fourier'); ?>
                </p>
                <button type="button" id="btn-delete-account" class="btn-black" style="background: #ef4444; border-color: #ef4444; color: #fff;">
                    <span class="material-symbols-outlined">delete_forever</span>
                    <?php echo esc_html__('アカウントとデータを削除', 'fourier'); ?>
                </button>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</main>

<?php if ($is_authenticated && $dashboard_data): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const data = <?php echo json_encode($dashboard_data); ?>;

        // Animate Numbers
        const elements = document.querySelectorAll('.animate-number');
        elements.forEach(el => {
            const target = parseInt(el.getAttribute('data-target'), 10);
            if (target === 0) return;
            const duration = 1500;
            const stepTime = Math.abs(Math.floor(duration / Math.min(target, 100)));
            let current = 0;
            const timer = setInterval(() => {
                const increment = Math.max(1, Math.floor(target / 50));
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                el.textContent = current.toLocaleString();
            }, stepTime);
        });

        // Format Chart
        const formatContainer = document.getElementById('format-chart-container');
        if (formatContainer && data.total_count > 0) {
            let html = '';
            const formats = Object.entries(data.format_counts).sort((a, b) => b[1] - a[1]);
            formats.forEach(([fmt, count]) => {
                if (count === 0) return;
                const percent = ((count / data.total_count) * 100).toFixed(1);
                html += `
                    <div class="format-bar-row">
                        <div class="format-bar-label">
                            <span style="font-weight:600;">${fmt}</span>
                            <span>${count}件 (${percent}%)</span>
                        </div>
                        <div class="format-bar-bg">
                            <div class="format-bar-fill" style="width: 0%" data-target-width="${percent}%"></div>
                        </div>
                    </div>
                `;
            });
            formatContainer.innerHTML = html;
            // animate width
            setTimeout(() => {
                formatContainer.querySelectorAll('.format-bar-fill').forEach(bar => {
                    bar.style.width = bar.getAttribute('data-target-width');
                });
            }, 100);
        } else if (formatContainer) {
            formatContainer.innerHTML = '<p>データがありません。</p>';
        }

        // Daily Chart
        const dailyContainer = document.getElementById('daily-chart-container');
        if (dailyContainer) {
            const days = Object.entries(data.daily_counts);
            let maxCount = 0;
            days.forEach(([_, count]) => { if (count > maxCount) maxCount = count; });
            if (maxCount === 0) maxCount = 10; // default scale
            
            let html = '';
            days.forEach(([date, count]) => {
                const heightPercent = (count / maxCount) * 100;
                // Add minimum height of 2px if 0 so the bar is at least slightly visible as a line
                const h = count > 0 ? heightPercent + '%' : '2px';
                
                html += `
                    <div class="daily-bar" style="height: 0%" data-target-height="${h}">
                        <div class="daily-bar-tooltip">${date}<br><b>${count}件</b></div>
                    </div>
                `;
            });
            dailyContainer.innerHTML = html;
            
            // animate height
            setTimeout(() => {
                dailyContainer.querySelectorAll('.daily-bar').forEach(bar => {
                    bar.style.height = bar.getAttribute('data-target-height');
                });
            }, 100);
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
    
    // Account Deletion Logic
    const btnDeleteAccount = document.getElementById('btn-delete-account');
    if (btnDeleteAccount) {
        btnDeleteAccount.addEventListener('click', function() {
            if (confirm('<?php echo esc_js(__('本当にアカウントとすべてのデータを削除しますか？この操作は取り消せません。', 'fourier')); ?>')) {
                const fd = new FormData();
                fd.append('action', 'frontend_delete_account');
                fd.append('nonce', '<?php echo wp_create_nonce("frontend_delete_action"); ?>');
                
                fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    alert(res.data.message || (res.success ? '削除しました。' : 'エラーが発生しました。'));
                    if (res.success) {
                        window.location.href = '<?php echo esc_url(home_url("/")); ?>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('<?php echo esc_js(__('通信エラーが発生しました。', 'fourier')); ?>');
                });
            }
        });
    }
</script>
<?php endif; ?>

<?php get_footer(); ?>
