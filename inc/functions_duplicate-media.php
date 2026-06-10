<?php
/*
 * Name: functions_duplicate-media.php
 * Description: WordPressのメディア管理画面でMD5を判定し、重複するファイルを検出・削除する機能。多言語対応（日本語・英語）。
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 多言語テキストのヘルパー関数
 *
 * @param string $key キー名
 * @param string $arg1 置換用変数1（任意）
 * @param string $arg2 置換用変数2（任意）
 * @return string 翻訳後の文字列
 */
function fourier_dm_get_text($key, $arg1 = '', $arg2 = '') {
    $locale = get_user_locale();
    $is_ja = (strpos($locale, 'ja') === 0);
    
    $texts = array(
        'page_title' => array(
            'en' => 'Duplicate Media Finder',
            'ja' => '重複ファイル削除'
        ),
        'menu_title' => array(
            'en' => 'Duplicate Media',
            'ja' => '重複ファイル削除'
        ),
        'scan_title' => array(
            'en' => '1. Media Scan & Indexing',
            'ja' => '1. メディアのスキャンとインデックス作成'
        ),
        'scan_desc' => array(
            'en' => 'Before detecting duplicates, we need to calculate MD5 hashes for all media files. Existing hashes are cached.',
            'ja' => '重複を検出する前に、すべてのメディアファイルのMD5ハッシュを計算する必要があります。作成済みのハッシュはキャッシュされます。'
        ),
        'scan_button' => array(
            'en' => 'Scan Media Library',
            'ja' => 'メディアライブラリをスキャン'
        ),
        'scanning' => array(
            'en' => 'Scanning...',
            'ja' => 'スキャン中...'
        ),
        'scan_complete' => array(
            'en' => 'Scan completed successfully!',
            'ja' => 'スキャンが正常に完了しました！'
        ),
        'scan_status' => array(
            'en' => 'Processed %s of %s files.',
            'ja' => '全 %s ファイル中 %s ファイルを処理しました。'
        ),
        'no_duplicates' => array(
            'en' => 'No duplicate files found. Your media library is clean!',
            'ja' => '重複ファイルは見つかりませんでした。メディアライブラリはクリーンです！'
        ),
        'duplicate_groups' => array(
            'en' => 'Duplicate Groups Found',
            'ja' => '検出された重複グループ'
        ),
        'original' => array(
            'en' => 'Original (Oldest)',
            'ja' => 'オリジナル (最古)'
        ),
        'duplicate' => array(
            'en' => 'Duplicate',
            'ja' => '重複コピー'
        ),
        'delete_selected' => array(
            'en' => 'Delete File',
            'ja' => 'このファイルを削除'
        ),
        'delete_confirm' => array(
            'en' => 'Are you sure you want to delete this file from the media library and server?',
            'ja' => 'このファイルをメディアライブラリとサーバーから完全に削除してもよろしいですか？'
        ),
        'bulk_delete_title' => array(
            'en' => '2. Bulk Delete Duplicates',
            'ja' => '2. 重複ファイルの一括削除'
        ),
        'bulk_delete_desc' => array(
            'en' => 'Keep the oldest (original) file in each group and delete all duplicate copies automatically.',
            'ja' => '各重複グループの最古（オリジナル）のファイルのみを残し、それ以外の重複コピーをすべて自動的に一括削除します。'
        ),
        'bulk_delete_button' => array(
            'en' => 'Bulk Delete All Duplicates',
            'ja' => 'すべての重複ファイルを一括削除する'
        ),
        'bulk_delete_confirm' => array(
            'en' => 'Are you sure you want to delete all duplicate files? Only the oldest file in each group will be kept.',
            'ja' => '本当にすべての重複ファイルを削除してもよろしいですか？各グループの最古のファイルだけが残されます。'
        ),
        'bulk_delete_success' => array(
            'en' => 'Successfully deleted %s duplicate files.',
            'ja' => '重複ファイル %s 個の削除に成功しました。'
        ),
        'delete_success' => array(
            'en' => 'File deleted successfully.',
            'ja' => 'ファイルを削除しました。'
        ),
        'file_size' => array(
            'en' => 'Size',
            'ja' => 'サイズ'
        ),
        'upload_date' => array(
            'en' => 'Uploaded',
            'ja' => 'アップロード日'
        ),
        'file_path' => array(
            'en' => 'Path',
            'ja' => 'パス'
        ),
        'permission_error' => array(
            'en' => 'You do not have permission to perform this action.',
            'ja' => 'この操作を実行する権限がありません。'
        ),
        'nonce_error' => array(
            'en' => 'Security check failed. Please refresh the page.',
            'ja' => 'セキュリティチェックに失敗しました。ページを再読み込みしてください。'
        ),
        'invalid_id' => array(
            'en' => 'Invalid file ID.',
            'ja' => '無効なファイルIDです。'
        ),
        'delete_failed' => array(
            'en' => 'Failed to delete the file.',
            'ja' => 'ファイルの削除に失敗しました。'
        )
    );
    
    $lang = $is_ja ? 'ja' : 'en';
    $text = isset($texts[$key][$lang]) ? $texts[$key][$lang] : $key;
    
    if ($arg1 !== '' && $arg2 !== '') {
        return sprintf($text, $arg1, $arg2);
    } elseif ($arg1 !== '') {
        return sprintf($text, $arg1);
    }
    return $text;
}

/**
 * アタッチメントのMD5ハッシュを取得、未計算の場合は計算してキャッシュ
 *
 * @param int $attachment_id アタッチメントID
 * @param bool $force 強制再計算フラグ
 * @return string|false MD5ハッシュ、または失敗時 false
 */
function fourier_dm_get_attachment_md5($attachment_id, $force = false) {
    if (!$force) {
        $md5 = get_post_meta($attachment_id, '_attachment_md5', true);
        if ($md5) {
            return $md5;
        }
    }
    
    $file_path = get_attached_file($attachment_id);
    if ($file_path && file_exists($file_path)) {
        $md5 = md5_file($file_path);
        if ($md5) {
            update_post_meta($attachment_id, '_attachment_md5', $md5);
            return $md5;
        }
    }
    return false;
}

// 新しいファイルのアップロードやメタデータ生成時にMD5ハッシュを自動生成
add_action('add_attachment', 'fourier_dm_save_md5_on_upload');
add_filter('wp_generate_attachment_metadata', 'fourier_dm_save_md5_on_meta_gen', 10, 2);

function fourier_dm_save_md5_on_upload($attachment_id) {
    fourier_dm_get_attachment_md5($attachment_id, true);
}

function fourier_dm_save_md5_on_meta_gen($metadata, $attachment_id) {
    fourier_dm_get_attachment_md5($attachment_id, true);
    return $metadata;
}

/**
 * 管理画面に「重複ファイル削除」メニューを登録
 */
add_action('admin_menu', 'fourier_dm_register_admin_page');
function fourier_dm_register_admin_page() {
    add_submenu_page(
        'upload.php',
        fourier_dm_get_text('page_title'),
        fourier_dm_get_text('menu_title'),
        'manage_options',
        'fourier-duplicate-media',
        'fourier_dm_render_page'
    );
}

/**
 * 未インデックス（MD5未生成）のファイル数を取得
 */
function fourier_dm_get_unindexed_attachments_count() {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(p.ID)
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_attachment_md5'
         WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND pm.meta_value IS NULL"
    );
}

/**
 * 全アタッチメント数を取得
 */
function fourier_dm_get_total_attachments_count() {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(ID)
         FROM {$wpdb->posts}
         WHERE post_type = 'attachment' AND post_status = 'inherit'"
    );
}

/**
 * AJAXアクション: MD5非同期一括スキャン処理
 */
add_action('wp_ajax_fourier_dm_scan', 'fourier_dm_scan_ajax_handler');
function fourier_dm_scan_ajax_handler() {
    // 権限チェック
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => fourier_dm_get_text('permission_error')));
    }
    
    // セキュリティチェック
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'fourier_dm_nonce')) {
        wp_send_json_error(array('message' => fourier_dm_get_text('nonce_error')));
    }
    
    global $wpdb;
    // インデックスされていないアタッチメントを最大50件検索
    $attachment_ids = $wpdb->get_col(
        "SELECT p.ID
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_attachment_md5'
         WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND pm.meta_value IS NULL
         LIMIT 50"
    );
    
    $processed = 0;
    if (!empty($attachment_ids)) {
        foreach ($attachment_ids as $id) {
            $md5 = fourier_dm_get_attachment_md5($id, true);
            if ($md5) {
                $processed++;
            } else {
                // ファイルが存在しない場合は placeholder を保存し再スキャンを回避
                update_post_meta($id, '_attachment_md5', 'error_missing_file');
                $processed++;
            }
        }
    }
    
    $total = fourier_dm_get_total_attachments_count();
    $unindexed = fourier_dm_get_unindexed_attachments_count();
    $indexed = $total - $unindexed;
    
    wp_send_json_success(array(
        'processed' => $processed,
        'indexed'   => $indexed,
        'total'     => $total,
        'done'      => ($unindexed === 0),
        'message'   => fourier_dm_get_text('scan_status', $indexed, $total)
    ));
}

/**
 * 重複しているグループを取得
 */
function fourier_dm_get_duplicate_groups() {
    global $wpdb;
    
    // 複数個存在するMD5を検索（エラー付きファイルを排除）
    $duplicate_hashes = $wpdb->get_results(
        "SELECT pm.meta_value AS md5, COUNT(pm.post_id) as dup_count
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key = '_attachment_md5' 
           AND pm.meta_value != 'error_missing_file'
           AND p.post_type = 'attachment' 
           AND p.post_status = 'inherit'
         GROUP BY pm.meta_value
         HAVING dup_count > 1
         ORDER BY dup_count DESC"
    );
    
    $groups = array();
    
    if (!empty($duplicate_hashes)) {
        foreach ($duplicate_hashes as $row) {
            $md5 = $row->md5;
            
            // MD5に一致する全アタッチメントを取得 (最古のID順)
            $attachments = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_date
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_attachment_md5' AND pm.meta_value = %s
                 ORDER BY p.ID ASC",
                $md5
            ));
            
            $items = array();
            foreach ($attachments as $att) {
                $file_path = get_attached_file($att->ID);
                $file_size = 0;
                $readable_size = 'N/A';
                if ($file_path && file_exists($file_path)) {
                    $file_size = filesize($file_path);
                    $readable_size = size_format($file_size);
                }
                
                $thumbnail = wp_get_attachment_image_url($att->ID, 'thumbnail');
                if (!$thumbnail) {
                    $thumbnail = wp_mime_type_icon($att->ID);
                }
                
                $items[] = array(
                    'id'            => (int) $att->ID,
                    'title'         => $att->post_title,
                    'date'          => $att->post_date,
                    'file_path'     => $file_path ? str_replace(ABSPATH, '', $file_path) : 'N/A',
                    'readable_size' => $readable_size,
                    'thumbnail'     => $thumbnail,
                    'url'           => wp_get_attachment_url($att->ID)
                );
            }
            
            $groups[] = array(
                'md5'         => $md5,
                'dup_count'   => (int) $row->dup_count,
                'attachments' => $items
            );
        }
    }
    
    return $groups;
}

/**
 * AJAXアクション: 単一ファイルの削除
 */
add_action('wp_ajax_fourier_dm_delete_single', 'fourier_dm_delete_single_ajax_handler');
function fourier_dm_delete_single_ajax_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => fourier_dm_get_text('permission_error')));
    }
    
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'fourier_dm_nonce')) {
        wp_send_json_error(array('message' => fourier_dm_get_text('nonce_error')));
    }
    
    $attachment_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($attachment_id <= 0) {
        wp_send_json_error(array('message' => fourier_dm_get_text('invalid_id')));
    }
    
    if (!function_exists('wp_delete_attachment')) {
        require_once(ABSPATH . 'wp-admin/includes/post.php');
    }
    
    $deleted = wp_delete_attachment($attachment_id, true);
    if ($deleted) {
        wp_send_json_success(array('message' => fourier_dm_get_text('delete_success')));
    } else {
        wp_send_json_error(array('message' => fourier_dm_get_text('delete_failed')));
    }
}

/**
 * AJAXアクション: 重複ファイルの一括削除 (最古のファイルを残す)
 */
add_action('wp_ajax_fourier_dm_bulk_delete', 'fourier_dm_bulk_delete_ajax_handler');
function fourier_dm_bulk_delete_ajax_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => fourier_dm_get_text('permission_error')));
    }
    
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'fourier_dm_nonce')) {
        wp_send_json_error(array('message' => fourier_dm_get_text('nonce_error')));
    }
    
    global $wpdb;
    
    $duplicate_hashes = $wpdb->get_col(
        "SELECT pm.meta_value AS md5
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key = '_attachment_md5' 
           AND pm.meta_value != 'error_missing_file'
           AND p.post_type = 'attachment' 
           AND p.post_status = 'inherit'
         GROUP BY pm.meta_value
         HAVING COUNT(pm.post_id) > 1"
    );
    
    if (!function_exists('wp_delete_attachment')) {
        require_once(ABSPATH . 'wp-admin/includes/post.php');
    }
    
    $deleted_count = 0;
    
    if (!empty($duplicate_hashes)) {
        foreach ($duplicate_hashes as $md5) {
            $attachment_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_attachment_md5' AND pm.meta_value = %s
                 ORDER BY p.ID ASC",
                $md5
            ));
            
            if (count($attachment_ids) > 1) {
                // 最古の要素 (インデックス0) を除外して残りを削除
                array_shift($attachment_ids);
                foreach ($attachment_ids as $id_to_delete) {
                    if (wp_delete_attachment(intval($id_to_delete), true)) {
                        $deleted_count++;
                    }
                }
            }
        }
    }
    
    wp_send_json_success(array(
        'deleted_count' => $deleted_count,
        'message'       => fourier_dm_get_text('bulk_delete_success', $deleted_count)
    ));
}

/**
 * 管理画面の描画処理
 */
function fourier_dm_render_page() {
    $total = fourier_dm_get_total_attachments_count();
    $unindexed = fourier_dm_get_unindexed_attachments_count();
    $indexed = $total - $unindexed;
    $percent = $total > 0 ? round(($indexed / $total) * 100) : 100;
    
    $groups = fourier_dm_get_duplicate_groups();
    $security = wp_create_nonce('fourier_dm_nonce');
    ?>
    <div class="wrap fourier-dm-wrap">
        <h1 class="fourier-dm-title"><?php echo esc_html(fourier_dm_get_text('page_title')); ?></h1>
        
        <div class="fourier-dm-grid">
            <!-- スキャンとインデックス作成カード -->
            <div class="fourier-dm-card fourier-dm-scan-card">
                <h2><?php echo esc_html(fourier_dm_get_text('scan_title')); ?></h2>
                <p class="description"><?php echo esc_html(fourier_dm_get_text('scan_desc')); ?></p>
                
                <div class="fourier-dm-progress-container">
                    <div class="fourier-dm-progress-bar" style="width: <?php echo esc_attr($percent); ?>%;"></div>
                    <span class="fourier-dm-progress-text"><?php echo esc_html(fourier_dm_get_text('scan_status', $indexed, $total)); ?></span>
                </div>
                
                <div class="fourier-dm-actions">
                    <button type="button" id="btn-fourier-dm-scan" class="button button-primary" <?php disabled($unindexed === 0); ?>>
                        <span class="spinner-inline"></span>
                        <span class="btn-text"><?php echo esc_html(fourier_dm_get_text('scan_button')); ?></span>
                    </button>
                </div>
            </div>
            
            <!-- 一括削除カード -->
            <?php if (!empty($groups)) : ?>
            <div class="fourier-dm-card fourier-dm-bulk-card">
                <h2><?php echo esc_html(fourier_dm_get_text('bulk_delete_title')); ?></h2>
                <p class="description"><?php echo esc_html(fourier_dm_get_text('bulk_delete_desc')); ?></p>
                
                <div class="fourier-dm-actions">
                    <button type="button" id="btn-fourier-dm-bulk" class="button button-link-delete">
                        <span class="spinner-inline"></span>
                        <span class="btn-text"><?php echo esc_html(fourier_dm_get_text('bulk_delete_button')); ?></span>
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- 重複グループ一覧 -->
        <div class="fourier-dm-list-container">
            <h2><?php echo esc_html(fourier_dm_get_text('duplicate_groups')); ?></h2>
            
            <?php if (empty($groups)) : ?>
                <div class="fourier-dm-alert fourier-dm-alert-info">
                    <p><?php echo esc_html(fourier_dm_get_text('no_duplicates')); ?></p>
                </div>
            <?php else : ?>
                <?php foreach ($groups as $group_idx => $group) : ?>
                    <div class="fourier-dm-group-card">
                        <div class="fourier-dm-group-header">
                            <span class="fourier-dm-group-index">#<?php echo $group_idx + 1; ?></span>
                            <span class="fourier-dm-group-md5">MD5: <code><?php echo esc_html($group['md5']); ?></code></span>
                            <span class="fourier-dm-group-count"><?php echo esc_html(sprintf(_n('%d duplicate', '%d duplicates', $group['dup_count'], 'fourier'), $group['dup_count'])); ?></span>
                        </div>
                        
                        <div class="fourier-dm-items-grid">
                            <?php foreach ($group['attachments'] as $item_idx => $item) : 
                                $is_original = ($item_idx === 0);
                            ?>
                                <div class="fourier-dm-item-box <?php echo $is_original ? 'fourier-dm-original' : 'fourier-dm-duplicate'; ?>" id="fourier-dm-item-<?php echo esc_attr($item['id']); ?>">
                                    <div class="fourier-dm-item-badge">
                                        <?php if ($is_original) : ?>
                                            <span class="badge badge-success"><?php echo esc_html(fourier_dm_get_text('original')); ?></span>
                                        <?php else : ?>
                                            <span class="badge badge-warning"><?php echo esc_html(fourier_dm_get_text('duplicate')); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="fourier-dm-item-thumbnail">
                                        <a href="<?php echo esc_url($item['url']); ?>" target="_blank" title="<?php echo esc_attr($item['title']); ?>">
                                            <img src="<?php echo esc_url($item['thumbnail']); ?>" alt="<?php echo esc_attr($item['title']); ?>" />
                                        </a>
                                    </div>
                                    
                                    <div class="fourier-dm-item-details">
                                        <h4 class="fourier-dm-item-title" title="<?php echo esc_attr($item['title']); ?>">
                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $item['id'] . '&action=edit')); ?>" target="_blank">
                                                <?php echo esc_html(wp_trim_words($item['title'], 6)); ?> (ID: <?php echo $item['id']; ?>)
                                            </a>
                                        </h4>
                                        <p><strong><?php echo esc_html(fourier_dm_get_text('file_size')); ?>:</strong> <?php echo esc_html($item['readable_size']); ?></p>
                                        <p><strong><?php echo esc_html(fourier_dm_get_text('upload_date')); ?>:</strong> <?php echo esc_html($item['date']); ?></p>
                                        <p class="fourier-dm-item-path" title="<?php echo esc_attr($item['file_path']); ?>">
                                            <strong><?php echo esc_html(fourier_dm_get_text('file_path')); ?>:</strong> <code><?php echo esc_html($item['file_path']); ?></code>
                                        </p>
                                    </div>
                                    
                                    <div class="fourier-dm-item-actions">
                                        <?php if (!$is_original) : ?>
                                            <button type="button" class="button button-link-delete btn-fourier-dm-delete-single" data-id="<?php echo esc_attr($item['id']); ?>">
                                                <span class="spinner-inline"></span>
                                                <span class="btn-text"><?php echo esc_html(fourier_dm_get_text('delete_selected')); ?></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <style>
        .fourier-dm-wrap {
            margin: 20px 20px 0 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        .fourier-dm-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1d2327;
            margin-bottom: 20px;
        }
        .fourier-dm-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .fourier-dm-card {
            background: #fff;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            padding: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .fourier-dm-card:hover {
            box-shadow: 0 6px 12px rgba(0,0,0,0.04);
        }
        .fourier-dm-card h2 {
            margin-top: 0;
            font-size: 1.2rem;
            font-weight: 600;
            color: #1d2327;
        }
        .fourier-dm-card p.description {
            color: #646970;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .fourier-dm-progress-container {
            background: #f0f0f1;
            border-radius: 4px;
            height: 24px;
            position: relative;
            overflow: hidden;
            margin-bottom: 15px;
        }
        .fourier-dm-progress-bar {
            background: linear-gradient(90deg, #c9a96e, #e2cb9c);
            height: 100%;
            transition: width 0.4s ease;
        }
        .fourier-dm-progress-text {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            text-align: center;
            font-size: 0.85rem;
            font-weight: 600;
            line-height: 24px;
            color: #1d2327;
            text-shadow: 0 0 2px rgba(255,255,255,0.8);
        }
        
        .fourier-dm-wrap .button-primary {
            background-color: #c9a96e !important;
            border-color: #c9a96e !important;
            color: #fff !important;
            text-shadow: none !important;
            box-shadow: 0 2px 4px rgba(201, 169, 110, 0.2) !important;
            font-weight: 600 !important;
            padding: 4px 16px !important;
            height: auto !important;
            line-height: 2 !important;
            border-radius: 4px !important;
            transition: background-color 0.2s, border-color 0.2s, transform 0.1s !important;
        }
        .fourier-dm-wrap .button-primary:hover:not(:disabled) {
            background-color: #bfa065 !important;
            border-color: #bfa065 !important;
        }
        .fourier-dm-wrap .button-primary:active:not(:disabled) {
            transform: scale(0.98);
        }
        .fourier-dm-wrap .button-primary:disabled {
            background-color: #e0e0e0 !important;
            border-color: #e0e0e0 !important;
            color: #a0a0a0 !important;
            cursor: not-allowed;
        }
        
        .fourier-dm-wrap .button-link-delete {
            background: transparent !important;
            border: 1px solid #dc3232 !important;
            color: #dc3232 !important;
            font-weight: 600 !important;
            padding: 4px 16px !important;
            height: auto !important;
            line-height: 2 !important;
            border-radius: 4px !important;
            cursor: pointer;
            transition: background-color 0.2s, color 0.2s, transform 0.1s !important;
        }
        .fourier-dm-wrap .button-link-delete:hover:not(:disabled) {
            background-color: rgba(220, 50, 50, 0.05) !important;
        }
        .fourier-dm-wrap .button-link-delete:active:not(:disabled) {
            transform: scale(0.98);
        }
        
        .spinner-inline {
            display: none;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(0,0,0,0.1);
            border-top-color: currentColor;
            border-radius: 50%;
            animation: fourier-dm-spin 0.8s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        .loading .spinner-inline {
            display: inline-block;
        }
        .loading .btn-text {
            vertical-align: middle;
        }
        
        @keyframes fourier-dm-spin {
            to { transform: rotate(360deg); }
        }
        
        .fourier-dm-alert {
            background: #fff;
            border-left: 4px solid #72aee6;
            border-radius: 4px;
            box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);
            margin: 5px 0 15px;
            padding: 1px 12px;
        }
        .fourier-dm-alert-info {
            border-left-color: #72aee6;
            background-color: #f0f6fc;
        }
        
        .fourier-dm-list-container {
            margin-top: 30px;
        }
        .fourier-dm-list-container > h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
        }
        .fourier-dm-group-card {
            background: #fff;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            overflow: hidden;
        }
        .fourier-dm-group-header {
            background: #f6f7f7;
            border-bottom: 1px solid #e0e0e0;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .fourier-dm-group-index {
            font-weight: 700;
            color: #c9a96e;
            font-size: 1.1rem;
        }
        .fourier-dm-group-md5 {
            color: #646970;
            font-size: 0.9rem;
            flex-grow: 1;
        }
        .fourier-dm-group-md5 code {
            background: #eaeaea;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        .fourier-dm-group-count {
            background: #e2ecf5;
            color: #1d2327;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .fourier-dm-items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        .fourier-dm-item-box {
            position: relative;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 15px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: all 0.2s ease;
        }
        .fourier-dm-original {
            border: 1px solid rgba(70, 180, 80, 0.4);
            background-color: rgba(70, 180, 80, 0.01);
            box-shadow: 0 2px 6px rgba(70, 180, 80, 0.05);
        }
        .fourier-dm-original:hover {
            box-shadow: 0 4px 12px rgba(70, 180, 80, 0.1);
            border-color: rgba(70, 180, 80, 0.6);
        }
        .fourier-dm-duplicate {
            border: 1px solid rgba(220, 50, 50, 0.1);
        }
        .fourier-dm-duplicate:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-color: rgba(220, 50, 50, 0.3);
        }
        
        .fourier-dm-item-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }
        .badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        .badge-success {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .badge-warning {
            background-color: #fff3cd;
            color: #664d03;
        }
        
        .fourier-dm-item-thumbnail {
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f6f7f7;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid #eaeaea;
        }
        .fourier-dm-item-thumbnail img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            transition: transform 0.3s;
        }
        .fourier-dm-item-thumbnail img:hover {
            transform: scale(1.05);
        }
        
        .fourier-dm-item-details {
            font-size: 0.85rem;
            color: #646970;
            flex-grow: 1;
        }
        .fourier-dm-item-title {
            margin: 0 0 8px;
            font-size: 0.95rem;
            font-weight: 600;
            color: #1d2327;
            line-height: 1.3;
        }
        .fourier-dm-item-title a {
            color: #1d2327;
            text-decoration: none;
            transition: color 0.2s;
        }
        .fourier-dm-item-title a:hover {
            color: #c9a96e;
        }
        .fourier-dm-item-details p {
            margin: 4px 0;
            line-height: 1.4;
        }
        .fourier-dm-item-details strong {
            color: #1d2327;
        }
        .fourier-dm-item-path {
            word-break: break-all;
            font-size: 0.8rem;
            margin-top: 8px !important;
        }
        .fourier-dm-item-path code {
            background: #f6f7f7;
            padding: 1px 4px;
            border-radius: 3px;
        }
        .fourier-dm-item-actions {
            margin-top: auto;
            text-align: right;
        }
    </style>
    
    <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_js($security); ?>';
            
            // バッチスキャンのトリガー
            $('#btn-fourier-dm-scan').on('click', function() {
                var $btn = $(this);
                if ($btn.prop('disabled') || $btn.parent().hasClass('loading')) return;
                
                $btn.addClass('loading').prop('disabled', true);
                
                function runScanBatch() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'fourier_dm_scan',
                            security: nonce
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                var data = response.data;
                                var percent = data.total > 0 ? Math.round((data.indexed / data.total) * 100) : 100;
                                
                                $('.fourier-dm-progress-bar').css('width', percent + '%');
                                $('.fourier-dm-progress-text').text(data.message);
                                
                                if (data.done) {
                                    $btn.removeClass('loading');
                                    alert('<?php echo esc_js(fourier_dm_get_text('scan_complete')); ?>');
                                    location.reload();
                                } else {
                                    // バッチ処理を継続
                                    runScanBatch();
                                }
                            } else {
                                $btn.removeClass('loading').prop('disabled', false);
                                alert(response.data.message || 'Error occurred during scan.');
                            }
                        },
                        error: function() {
                            $btn.removeClass('loading').prop('disabled', false);
                            alert('Communication error.');
                        }
                    });
                }
                
                runScanBatch();
            });
            
            // 単一ファイルの削除
            $('.btn-fourier-dm-delete-single').on('click', function() {
                var $btn = $(this);
                var id = $btn.data('id');
                
                if (!confirm('<?php echo esc_js(fourier_dm_get_text('delete_confirm')); ?>')) {
                    return;
                }
                
                $btn.addClass('loading').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'fourier_dm_delete_single',
                        id: id,
                        security: nonce
                    },
                    dataType: 'json',
                    success: function(response) {
                        $btn.removeClass('loading').prop('disabled', false);
                        if (response.success) {
                            var $itemBox = $('#fourier-dm-item-' + id);
                            $itemBox.css('background-color', '#ffebe9').fadeOut(300, function() {
                                $(this).remove();
                                location.reload();
                            });
                        } else {
                            alert(response.data.message || 'Delete failed.');
                        }
                    },
                    error: function() {
                        $btn.removeClass('loading').prop('disabled', false);
                        alert('Communication error.');
                    }
                });
            });
            
            // 一括削除のトリガー
            $('#btn-fourier-dm-bulk').on('click', function() {
                var $btn = $(this);
                if (!confirm('<?php echo esc_js(fourier_dm_get_text('bulk_delete_confirm')); ?>')) {
                    return;
                }
                
                $btn.addClass('loading').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'fourier_dm_bulk_delete',
                        security: nonce
                    },
                    dataType: 'json',
                    success: function(response) {
                        $btn.removeClass('loading').prop('disabled', false);
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert(response.data.message || 'Bulk delete failed.');
                        }
                    },
                    error: function() {
                        $btn.removeClass('loading').prop('disabled', false);
                        alert('Communication error.');
                    }
                });
            });
        });
    </script>
    <?php
}
