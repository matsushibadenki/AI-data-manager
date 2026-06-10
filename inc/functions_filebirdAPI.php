<?php
/**
 * ============================================================
 * File: /inc/functions_filebirdAPI.php
 * Name: functions_filebirdAPI.php
 * Description: FileBird Image REST API
 *   - 既存の画像一覧エンドポイント（バグ修正・拡張済み）
 *   - AI向け画像データ供給エンドポイント（base64/URL/メタデータ）
 *   - フォルダツリー、統計、検索、バッチ取得
 *   - APIキー認証（AI向けエンドポイント）
 *   - 多言語対応
 * ============================================================
 */

// FileBird ヘルパー関数の読み込み
if (! function_exists('get_attachments_by_filebird_folder_id')) {
    require_once __DIR__ . '/functions_filebird-control.php';
}

/* ================================================================
 * 1. 定数 & 設定
 * ================================================================ */

define('FBAPI_NAMESPACE', 'my-image-api/v1');
define('FBAPI_MAX_PER_PAGE', 50);
define('FBAPI_AI_MAX_PER_PAGE', 50);
define('FBAPI_DEFAULT_PER_PAGE', 10);
define('FBAPI_OPTION_KEY', 'fourier_api_key');

/* ================================================================
 * 2. ヘルパー関数群
 * ================================================================ */

/**
 * APIキーの検証
 *
 * @param WP_REST_Request $request
 * @return bool|WP_Error
 */
function fbapi_verify_api_key($request)
{
    $stored_key = get_option(FBAPI_OPTION_KEY, '');

    if (empty($stored_key)) {
        // APIキーが未設定の場合は管理者ログインチェック
        return current_user_can('manage_options');
    }

    // ヘッダーまたはクエリパラメータからAPIキーを取得
    $provided_key = $request->get_header('X-API-Key');
    if (empty($provided_key)) {
        $provided_key = $request->get_param('api_key');
    }

    if (empty($provided_key)) {
        return new WP_Error(
            'missing_api_key',
            __('API key is required. Provide it via X-API-Key header or api_key parameter.', 'fourier'),
            array('status' => 401)
        );
    }

    if (! hash_equals($stored_key, $provided_key)) {
        return new WP_Error(
            'invalid_api_key',
            __('Invalid API key.', 'fourier'),
            array('status' => 403)
        );
    }

    return true;
}

/**
 * description フィールドのJSON解析
 *
 * @param int $attachment_id
 * @return array|null 解析されたJSONデータ、またはnull
 */
function fbapi_parse_description_json($attachment_id)
{
    $description = get_post_field('post_content', $attachment_id);
    if (empty($description)) {
        return null;
    }

    $json_data = json_decode($description, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $json_data;
}

/**
 * 画像の全サイズURLを取得
 *
 * @param int $attachment_id
 * @return array サイズ名 => URL の連想配列
 */
function fbapi_get_image_urls($attachment_id)
{
    $sizes = array('thumbnail', 'medium', 'medium_large', 'large', 'full');
    $urls = array();

    foreach ($sizes as $size) {
        $src = wp_get_attachment_image_src($attachment_id, $size);
        if ($src) {
            $urls[$size] = $src[0];
        }
    }

    return $urls;
}

/**
 * アタッチメントが所属するフォルダ情報を取得
 *
 * @param int $attachment_id
 * @return array|null フォルダ情報
 */
function fbapi_get_attachment_folder($attachment_id)
{
    global $wpdb;
    $af_table = $wpdb->prefix . 'fbv_attachment_folder';
    $fb_table = $wpdb->prefix . 'fbv';

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT af.folder_id, f.name as folder_name
         FROM {$af_table} af
         LEFT JOIN {$fb_table} f ON af.folder_id = f.id
         WHERE af.attachment_id = %d
         LIMIT 1",
        $attachment_id
    ));

    if ($row) {
        return array(
            'id'   => (int) $row->folder_id,
            'name' => $row->folder_name,
        );
    }

    return null;
}

/**
 * 単一画像の詳細情報を構築
 *
 * @param int   $attachment_id
 * @param array $options オプション（include_metadata, include_description, include_folder）
 * @return array|null 画像情報、または存在しない場合null
 */
function fbapi_get_image_detail($attachment_id, $options = array())
{
    $post = get_post($attachment_id);
    if (! $post || $post->post_type !== 'attachment') {
        return null;
    }

    $defaults = array(
        'include_metadata'    => true,
        'include_description' => true,
        'include_folder'      => true,
        'include_urls'        => true,
    );
    $opts = wp_parse_args($options, $defaults);

    $mime_type = get_post_mime_type($attachment_id);
    $file_url  = wp_get_attachment_url($attachment_id);
    $file_path = get_attached_file($attachment_id);
    $file_name = basename($file_url);

    $data = array(
        'id'         => (int) $attachment_id,
        'title'      => get_the_title($attachment_id),
        'filename'   => $file_name,
        'mime_type'  => $mime_type,
        'url'        => $file_url,
        'date'       => $post->post_date,
        'modified'   => $post->post_modified,
        'file_size'  => file_exists($file_path) ? filesize($file_path) : null,
    );

    // 画像寸法
    $wp_meta = wp_get_attachment_metadata($attachment_id);
    if ($wp_meta && isset($wp_meta['width'])) {
        $data['dimensions'] = array(
            'width'  => (int) $wp_meta['width'],
            'height' => (int) $wp_meta['height'],
        );
    }

    // 全サイズのURL
    if ($opts['include_urls']) {
        $data['urls'] = fbapi_get_image_urls($attachment_id);
    }

    // WPメタデータ（EXIF等）
    if ($opts['include_metadata'] && $wp_meta) {
        $meta_info = array();

        if (isset($wp_meta['image_meta'])) {
            $im = $wp_meta['image_meta'];
            if (! empty($im['camera']))        $meta_info['camera']       = $im['camera'];
            if (! empty($im['aperture']))       $meta_info['aperture']     = 'f/' . $im['aperture'];
            if (! empty($im['focal_length']))   $meta_info['focal_length'] = $im['focal_length'] . 'mm';
            if (! empty($im['iso']))            $meta_info['iso']          = $im['iso'];
            if (! empty($im['shutter_speed']))  $meta_info['shutter_speed'] = $im['shutter_speed'];
            if (! empty($im['created_timestamp'])) {
                $meta_info['date_taken'] = date('Y-m-d H:i:s', $im['created_timestamp']);
            }
            if (! empty($im['copyright']))      $meta_info['copyright']    = $im['copyright'];
            if (! empty($im['credit']))         $meta_info['credit']       = $im['credit'];
            if (! empty($im['orientation']))    $meta_info['orientation']  = $im['orientation'];
            if (! empty($im['keywords']))       $meta_info['keywords']     = $im['keywords'];
        }

        // 利用可能なサイズ情報
        if (isset($wp_meta['sizes'])) {
            $available_sizes = array();
            foreach ($wp_meta['sizes'] as $size_name => $size_data) {
                $available_sizes[$size_name] = array(
                    'width'  => (int) $size_data['width'],
                    'height' => (int) $size_data['height'],
                );
            }
            $meta_info['available_sizes'] = $available_sizes;
        }

        $data['metadata'] = $meta_info;
    }

    // JSONデスクリプション解析
    if ($opts['include_description']) {
        $json_desc = fbapi_parse_description_json($attachment_id);
        if ($json_desc) {
            $data['description'] = $json_desc;
        } else {
            $raw_desc = get_post_field('post_content', $attachment_id);
            $data['description'] = ! empty($raw_desc) ? $raw_desc : null;
        }
        // ALTテキスト
        $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $data['alt'] = ! empty($alt) ? $alt : null;
        // キャプション
        $data['caption'] = ! empty($post->post_excerpt) ? $post->post_excerpt : null;
    }

    // フォルダ情報
    if ($opts['include_folder']) {
        $data['folder'] = fbapi_get_attachment_folder($attachment_id);
    }

    return $data;
}

/**
 * AI向け画像データを構築（base64対応）
 *
 * @param int   $attachment_id
 * @param array $options format, size, max_dimension, include_metadata, include_description
 * @return array|null
 */
function fbapi_get_ai_image_data($attachment_id, $options = array())
{
    $defaults = array(
        'format'              => 'url',
        'size'                => 'medium',
        'max_dimension'       => null,
        'include_metadata'    => true,
        'include_description' => true,
    );
    $opts = wp_parse_args($options, $defaults);

    // 基本情報を取得
    $detail = fbapi_get_image_detail($attachment_id, array(
        'include_metadata'    => $opts['include_metadata'],
        'include_description' => $opts['include_description'],
        'include_folder'      => true,
        'include_urls'        => true,
    ));

    if (! $detail) {
        return null;
    }

    $data = $detail;

    // base64エンコード
    if ($opts['format'] === 'base64') {
        $image_path = null;

        // 指定サイズのパスを取得
        if ($opts['size'] !== 'full') {
            $wp_meta = wp_get_attachment_metadata($attachment_id);
            if ($wp_meta && isset($wp_meta['sizes'][$opts['size']])) {
                $upload_dir = wp_upload_dir();
                $file_dir = dirname(get_attached_file($attachment_id));
                $image_path = $file_dir . '/' . $wp_meta['sizes'][$opts['size']]['file'];
            }
        }

        // フォールバック: フルサイズ
        if (! $image_path || ! file_exists($image_path)) {
            $image_path = get_attached_file($attachment_id);
        }

        if ($image_path && file_exists($image_path)) {
            // max_dimension が指定されている場合はリサイズ
            if ($opts['max_dimension'] && function_exists('imagecreatefromstring')) {
                $resized = fbapi_resize_image_to_max_dimension($image_path, (int) $opts['max_dimension']);
                if ($resized !== null) {
                    $mime = get_post_mime_type($attachment_id);
                    $data['base64'] = 'data:' . $mime . ';base64,' . base64_encode($resized);
                    $data['base64_size'] = strlen($resized);
                }
            }

            // リサイズしなかった場合、そのままエンコード
            if (! isset($data['base64'])) {
                $file_content = file_get_contents($image_path);
                if ($file_content !== false) {
                    $mime = get_post_mime_type($attachment_id);
                    $data['base64'] = 'data:' . $mime . ';base64,' . base64_encode($file_content);
                    $data['base64_size'] = strlen($file_content);
                }
            }
        }
    } elseif ($opts['format'] === 'metadata_only') {
        // URLも省略（メタデータのみ）
        unset($data['url']);
        unset($data['urls']);
    }

    return $data;
}

/**
 * 画像を最大辺に合わせてリサイズし、バイナリデータを返す
 *
 * @param string $file_path 元ファイルパス
 * @param int    $max_dimension 最大辺px
 * @return string|null リサイズ後のバイナリデータ、または失敗時null
 */
function fbapi_resize_image_to_max_dimension($file_path, $max_dimension)
{
    // WordPress の WP_Image_Editor を使用
    $editor = wp_get_image_editor($file_path);
    if (is_wp_error($editor)) {
        return null;
    }

    $size = $editor->get_size();
    $orig_w = $size['width'];
    $orig_h = $size['height'];

    // すでに制限内なら何もしない
    if ($orig_w <= $max_dimension && $orig_h <= $max_dimension) {
        return null; // 呼び出し元でオリジナルを使用
    }

    // アスペクト比を維持してリサイズ
    if ($orig_w >= $orig_h) {
        $new_w = $max_dimension;
        $new_h = (int) round($orig_h * ($max_dimension / $orig_w));
    } else {
        $new_h = $max_dimension;
        $new_w = (int) round($orig_w * ($max_dimension / $orig_h));
    }

    $editor->resize($new_w, $new_h, false);

    // 一時ファイルに保存して読み込む
    $upload_dir = wp_upload_dir();
    $tmp_file = $upload_dir['basedir'] . '/fbapi_tmp_' . uniqid() . '.jpg';
    $saved = $editor->save($tmp_file, 'image/jpeg');

    if (is_wp_error($saved) || ! file_exists($saved['path'])) {
        return null;
    }

    $binary = file_get_contents($saved['path']);
    @unlink($saved['path']); // 一時ファイル削除

    return $binary;
}

/**
 * フォルダツリーを再帰的に構築
 *
 * @param int   $parent_id 親フォルダID（0 = ルート）
 * @param array $all_folders 全フォルダデータ
 * @param bool  $include_count 画像数を含めるか
 * @return array
 */
function fbapi_build_folder_tree($parent_id = 0, $all_folders = null, $include_count = true)
{
    if ($all_folders === null) {
        $all_folders = get_all_filebird_folders();
    }

    $tree = array();

    foreach ($all_folders as $folder) {
        if ((int) $folder->parent === (int) $parent_id) {
            $node = array(
                'id'   => (int) $folder->id,
                'name' => $folder->name,
            );

            if ($include_count) {
                $ids = get_attachments_by_filebird_folder_id($folder->id);
                $node['image_count'] = is_array($ids) ? count($ids) : 0;
            }

            // 子フォルダの再帰取得
            $children = fbapi_build_folder_tree($folder->id, $all_folders, $include_count);
            if (! empty($children)) {
                $node['children'] = $children;
            }

            $tree[] = $node;
        }
    }

    return $tree;
}

/**
 * フォルダIDからフォルダ名を取得（既存関数名の互換ラッパー）
 *
 * @param int $folder_id
 * @return string|null
 */
function fbapi_get_folder_name_by_id($folder_id)
{
    return get_filebird_folder_name_by_id($folder_id);
}

/**
 * フォルダIDを解決（folder_name / folder_id パラメータから）
 *
 * @param WP_REST_Request $request
 * @return array ['folder_id' => int, 'folder_name' => string] または WP_Error
 */
function fbapi_resolve_folder($request)
{
    $folder_name = $request->get_param('folder_name');
    $folder_id   = $request->get_param('folder_id');

    if ($folder_name) {
        $sanitized_name = sanitize_text_field($folder_name);
        $resolved_id = get_folder_id_by_folder_name($sanitized_name);

        if (! $resolved_id) {
            return new WP_Error(
                'folder_not_found',
                __('Folder not found: ', 'fourier') . esc_html($sanitized_name),
                array('status' => 404)
            );
        }

        return array(
            'folder_id'   => (int) $resolved_id,
            'folder_name' => $sanitized_name,
        );
    } elseif ($folder_id) {
        $id = absint($folder_id);
        $name = fbapi_get_folder_name_by_id($id);

        if (! $name) {
            return new WP_Error(
                'folder_not_found',
                __('Folder not found for ID: ', 'fourier') . $id,
                array('status' => 404)
            );
        }

        return array(
            'folder_id'   => $id,
            'folder_name' => $name,
        );
    }

    return new WP_Error(
        'missing_parameter',
        __('Please provide folder_name or folder_id.', 'fourier'),
        array('status' => 400)
    );
}


/* ================================================================
 * 3. REST API エンドポイント登録
 * ================================================================ */

add_action('rest_api_init', function () {
    // CORS ヘッダー
    add_filter('rest_pre_serve_request', function ($value) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: X-API-Key, Content-Type');
        return $value;
    });

    /* ──────────────────────────────────────
     * 3-1. GET /images — フォルダ内画像一覧（パブリック）
     * ────────────────────────────────────── */
    register_rest_route(FBAPI_NAMESPACE, '/images', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'fbapi_get_images_callback',
        'permission_callback' => '__return_true',
        'args'                => array(
            'folder_name' => array(
                'description' => 'FileBird folder name',
                'type'        => 'string',
                'required'    => false,
            ),
            'folder_id' => array(
                'description'       => 'FileBird folder ID',
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
            ),
            'per_page' => array(
                'description'       => 'Items per page (max ' . FBAPI_MAX_PER_PAGE . ')',
                'type'              => 'integer',
                'default'           => FBAPI_DEFAULT_PER_PAGE,
                'sanitize_callback' => 'absint',
            ),
            'page' => array(
                'description'       => 'Page number',
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ),
            'orderby' => array(
                'description' => 'Sort field: date, title, modified',
                'type'        => 'string',
                'default'     => 'date',
                'enum'        => array('date', 'title', 'modified'),
            ),
            'order' => array(
                'description' => 'Sort order: asc, desc',
                'type'        => 'string',
                'default'     => 'desc',
                'enum'        => array('asc', 'desc'),
            ),
            'mime_type' => array(
                'description' => 'Filter by MIME type (e.g., image, video, image/jpeg)',
                'type'        => 'string',
                'required'    => false,
            ),
        ),
    ));

    /* ──────────────────────────────────────
     * 3-2. GET /images/(?P<id>\d+) — 単一画像詳細（パブリック）
     * ────────────────────────────────────── */
    register_rest_route(FBAPI_NAMESPACE, '/images/(?P<id>\d+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'fbapi_get_single_image_callback',
        'permission_callback' => '__return_true',
        'args'                => array(
            'id' => array(
                'description'       => 'Attachment ID',
                'type'              => 'integer',
                'required'          => true,
                'sanitize_callback' => 'absint',
            ),
        ),
    ));

    /* ──────────────────────────────────────
     * 3-3. GET /ai/images — AI向けバッチ画像供給（認証必須）
     * ────────────────────────────────────── */
    register_rest_route(FBAPI_NAMESPACE, '/ai/images', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'fbapi_ai_images_callback',
        'permission_callback' => 'fbapi_verify_api_key',
        'args'                => array(
            'folder_name' => array(
                'description' => 'FileBird folder name',
                'type'        => 'string',
                'required'    => false,
            ),
            'folder_id' => array(
                'description'       => 'FileBird folder ID',
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
            ),
            'format' => array(
                'description' => 'Response format: url, base64, metadata_only',
                'type'        => 'string',
                'default'     => 'url',
                'enum'        => array('url', 'base64', 'metadata_only'),
            ),
            'size' => array(
                'description' => 'Image size: thumbnail, medium, large, full',
                'type'        => 'string',
                'default'     => 'medium',
                'enum'        => array('thumbnail', 'medium', 'medium_large', 'large', 'full'),
            ),
            'max_dimension' => array(
                'description'       => 'Max dimension in px (for AI token optimization)',
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
            ),
            'include_metadata' => array(
                'description' => 'Include EXIF/image metadata',
                'type'        => 'boolean',
                'default'     => true,
            ),
            'include_description' => array(
                'description' => 'Include parsed JSON description',
                'type'        => 'boolean',
                'default'     => true,
            ),
            'mime_type' => array(
                'description' => 'Filter by MIME type (e.g., image, image/jpeg)',
                'type'        => 'string',
                'default'     => 'image',
            ),
            'per_page' => array(
                'description'       => 'Items per page (max ' . FBAPI_AI_MAX_PER_PAGE . ')',
                'type'              => 'integer',
                'default'           => FBAPI_DEFAULT_PER_PAGE,
                'sanitize_callback' => 'absint',
            ),
            'page' => array(
                'description'       => 'Page number',
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ),
        ),
    ));

    /* ──────────────────────────────────────
     * 3-4. GET /ai/image/(?P<id>\d+) — AI向け単一画像（認証必須）
     * ────────────────────────────────────── */
    register_rest_route(FBAPI_NAMESPACE, '/ai/image/(?P<id>\d+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'fbapi_ai_single_image_callback',
        'permission_callback' => 'fbapi_verify_api_key',
        'args'                => array(
            'id' => array(
                'description'       => 'Attachment ID',
                'type'              => 'integer',
                'required'          => true,
                'sanitize_callback' => 'absint',
            ),
            'format' => array(
                'description' => 'Response format: url, base64',
                'type'        => 'string',
                'default'     => 'base64',
                'enum'        => array('url', 'base64'),
            ),
            'size' => array(
                'description' => 'Image size',
                'type'        => 'string',
                'default'     => 'medium',
                'enum'        => array('thumbnail', 'medium', 'medium_large', 'large', 'full'),
            ),
            'max_dimension' => array(
                'description'       => 'Max dimension in px',
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
            ),
        ),
    ));

    /* ──────────────────────────────────────
     * 3-5. GET /folders — フォルダツリー（パブリック）
     * ────────────────────────────────────── */
    register_rest_route(FBAPI_NAMESPACE, '/folders', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'fbapi_get_folders_callback',
        'permission_callback' => '__return_true',
        'args'                => array(
            'include_count' => array(
                'description' => 'Include image count per folder',
                'type'        => 'boolean',
                'default'     => true,
            ),
            'flat' => array(
                'description' => 'Return flat list instead of tree',
                'type'        => 'boolean',
                'default'     => false,
            ),
        ),
    ));

    /* ──────────────────────────────────────
     * 3-6. GET /stats — メディア統計（パブリック）
     * ────────────────────────────────────── */
    register_rest_route(FBAPI_NAMESPACE, '/stats', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'fbapi_get_stats_callback',
        'permission_callback' => '__return_true',
    ));

    /* ──────────────────────────────────────
     * 3-7. GET /search — メディア横断検索（パブリック）
     * ────────────────────────────────────── */
    register_rest_route(FBAPI_NAMESPACE, '/search', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'fbapi_search_callback',
        'permission_callback' => '__return_true',
        'args'                => array(
            'q' => array(
                'description' => 'Search query',
                'type'        => 'string',
                'required'    => true,
            ),
            'mime_type' => array(
                'description' => 'Filter by MIME type',
                'type'        => 'string',
                'required'    => false,
            ),
            'folder_name' => array(
                'description' => 'Filter by folder name',
                'type'        => 'string',
                'required'    => false,
            ),
            'per_page' => array(
                'description'       => 'Items per page',
                'type'              => 'integer',
                'default'           => FBAPI_DEFAULT_PER_PAGE,
                'sanitize_callback' => 'absint',
            ),
            'page' => array(
                'description'       => 'Page number',
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ),
        ),
    ));

    /* ──────────────────────────────────────
     * 3-8. POST /batch — バッチ画像情報取得（認証必須）
     * ────────────────────────────────────── */
    register_rest_route(FBAPI_NAMESPACE, '/batch', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'fbapi_batch_callback',
        'permission_callback' => 'fbapi_verify_api_key',
        'args'                => array(
            'ids' => array(
                'description' => 'Array of attachment IDs',
                'type'        => 'array',
                'required'    => true,
                'items'       => array('type' => 'integer'),
            ),
            'format' => array(
                'description' => 'Response format: url, base64, metadata_only',
                'type'        => 'string',
                'default'     => 'url',
                'enum'        => array('url', 'base64', 'metadata_only'),
            ),
            'size' => array(
                'description' => 'Image size for base64',
                'type'        => 'string',
                'default'     => 'medium',
            ),
        ),
    ));
});


/* ================================================================
 * 4. エンドポイント コールバック関数
 * ================================================================ */

/**
 * 3-1. GET /images — フォルダ内画像一覧（修正済み）
 */
function fbapi_get_images_callback(WP_REST_Request $request)
{
    $per_page = min($request->get_param('per_page'), FBAPI_MAX_PER_PAGE);
    $page     = max(1, $request->get_param('page'));
    $orderby  = $request->get_param('orderby') ?: 'date';
    $order    = strtoupper($request->get_param('order') ?: 'DESC');
    $mime     = $request->get_param('mime_type');

    // フォルダ解決
    $folder = fbapi_resolve_folder($request);
    if (is_wp_error($folder)) {
        return $folder;
    }

    $target_folder_id   = $folder['folder_id'];
    $target_folder_name = $folder['folder_name'];

    // フォルダ内のアタッチメントIDを取得
    $attachment_ids = get_attachments_by_filebird_folder_id($target_folder_id);

    if (! is_array($attachment_ids) || empty($attachment_ids)) {
        return new WP_REST_Response(array(
            'success' => true,
            'data'    => array(
                'images'     => array(),
                'pagination' => array(
                    'total_images'  => 0,
                    'total_pages'   => 0,
                    'current_page'  => $page,
                    'per_page'      => $per_page,
                    'folder_id'     => $target_folder_id,
                    'folder_name'   => $target_folder_name,
                ),
            ),
        ), 200);
    }

    // WP_Query でソート・フィルタ
    $query_args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post__in'       => $attachment_ids,
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => $orderby === 'modified' ? 'modified' : $orderby,
        'order'          => $order,
    );

    if ($mime) {
        $query_args['post_mime_type'] = $mime;
    }

    $query = new WP_Query($query_args);

    $images = array();
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $att_id = get_the_ID();
            $images[] = fbapi_get_image_detail($att_id, array(
                'include_metadata'    => false,
                'include_description' => true,
                'include_folder'      => false,
                'include_urls'        => true,
            ));
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => array(
            'images'     => $images,
            'pagination' => array(
                'total_images'  => (int) $query->found_posts,
                'total_pages'   => (int) $query->max_num_pages,
                'current_page'  => $page,
                'per_page'      => $per_page,
                'folder_id'     => $target_folder_id,
                'folder_name'   => $target_folder_name,
            ),
        ),
    ), 200);
}

/**
 * 3-2. GET /images/{id} — 単一画像詳細
 */
function fbapi_get_single_image_callback(WP_REST_Request $request)
{
    $id = $request->get_param('id');

    $detail = fbapi_get_image_detail($id);
    if (! $detail) {
        return new WP_Error(
            'not_found',
            __('Attachment not found.', 'fourier'),
            array('status' => 404)
        );
    }

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => $detail,
    ), 200);
}

/**
 * 3-3. GET /ai/images — AI向けバッチ画像供給
 */
function fbapi_ai_images_callback(WP_REST_Request $request)
{
    $per_page = min($request->get_param('per_page'), FBAPI_AI_MAX_PER_PAGE);
    $page     = max(1, $request->get_param('page'));
    $format   = $request->get_param('format') ?: 'url';
    $size     = $request->get_param('size') ?: 'medium';
    $max_dim  = $request->get_param('max_dimension');
    $inc_meta = (bool) $request->get_param('include_metadata');
    $inc_desc = (bool) $request->get_param('include_description');
    $mime     = $request->get_param('mime_type') ?: 'image';

    // base64 の場合、ページあたりの上限を厳しくする
    if ($format === 'base64') {
        $per_page = min($per_page, 20);
    }

    // フォルダ解決
    $folder = fbapi_resolve_folder($request);
    if (is_wp_error($folder)) {
        return $folder;
    }

    $target_folder_id   = $folder['folder_id'];
    $target_folder_name = $folder['folder_name'];

    // フォルダ内のアタッチメントIDを取得
    $attachment_ids = get_attachments_by_filebird_folder_id($target_folder_id);

    if (! is_array($attachment_ids) || empty($attachment_ids)) {
        return new WP_REST_Response(array(
            'success' => true,
            'data'    => array(
                'images'     => array(),
                'pagination' => array(
                    'total_images'  => 0,
                    'total_pages'   => 0,
                    'current_page'  => $page,
                    'per_page'      => $per_page,
                    'folder_id'     => $target_folder_id,
                    'folder_name'   => $target_folder_name,
                ),
                'format'  => $format,
                'size'    => $size,
            ),
        ), 200);
    }

    // WP_Query でMIMEタイプフィルタ
    $query_args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post__in'       => $attachment_ids,
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    if ($mime) {
        $query_args['post_mime_type'] = $mime;
    }

    $query = new WP_Query($query_args);

    $images = array();
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $att_id = get_the_ID();

            $img = fbapi_get_ai_image_data($att_id, array(
                'format'              => $format,
                'size'                => $size,
                'max_dimension'       => $max_dim,
                'include_metadata'    => $inc_meta,
                'include_description' => $inc_desc,
            ));

            if ($img) {
                $images[] = $img;
            }
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => array(
            'images'     => $images,
            'pagination' => array(
                'total_images'  => (int) $query->found_posts,
                'total_pages'   => (int) $query->max_num_pages,
                'current_page'  => $page,
                'per_page'      => $per_page,
                'folder_id'     => $target_folder_id,
                'folder_name'   => $target_folder_name,
            ),
            'format'  => $format,
            'size'    => $size,
        ),
    ), 200);
}

/**
 * 3-4. GET /ai/image/{id} — AI向け単一画像
 */
function fbapi_ai_single_image_callback(WP_REST_Request $request)
{
    $id      = $request->get_param('id');
    $format  = $request->get_param('format') ?: 'base64';
    $size    = $request->get_param('size') ?: 'medium';
    $max_dim = $request->get_param('max_dimension');

    $data = fbapi_get_ai_image_data($id, array(
        'format'              => $format,
        'size'                => $size,
        'max_dimension'       => $max_dim,
        'include_metadata'    => true,
        'include_description' => true,
    ));

    if (! $data) {
        return new WP_Error(
            'not_found',
            __('Attachment not found.', 'fourier'),
            array('status' => 404)
        );
    }

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => $data,
    ), 200);
}

/**
 * 3-5. GET /folders — フォルダツリー
 */
function fbapi_get_folders_callback(WP_REST_Request $request)
{
    $include_count = (bool) $request->get_param('include_count');
    $flat          = (bool) $request->get_param('flat');

    if ($flat) {
        // フラットリスト
        $all_folders = get_all_filebird_folders();
        $list = array();

        foreach ($all_folders as $folder) {
            $item = array(
                'id'        => (int) $folder->id,
                'name'      => $folder->name,
                'parent_id' => (int) $folder->parent,
            );

            if ($include_count) {
                $ids = get_attachments_by_filebird_folder_id($folder->id);
                $item['image_count'] = is_array($ids) ? count($ids) : 0;
            }

            $list[] = $item;
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => array(
                'folders'      => $list,
                'total_folders' => count($list),
            ),
        ), 200);
    }

    // ツリー形式
    $tree = fbapi_build_folder_tree(0, null, $include_count);
    $all_folders = get_all_filebird_folders();

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => array(
            'folders'       => $tree,
            'total_folders' => count($all_folders),
        ),
    ), 200);
}

/**
 * 3-6. GET /stats — メディア統計
 */
function fbapi_get_stats_callback(WP_REST_Request $request)
{
    global $wpdb;

    // 総メディア数（MIMEタイプ別）
    $mime_counts = $wpdb->get_results(
        "SELECT post_mime_type, COUNT(*) as count
         FROM {$wpdb->posts}
         WHERE post_type = 'attachment' AND post_status = 'inherit'
         GROUP BY post_mime_type
         ORDER BY count DESC"
    );

    $by_mime = array();
    $total   = 0;
    foreach ($mime_counts as $row) {
        $by_mime[$row->post_mime_type] = (int) $row->count;
        $total += (int) $row->count;
    }

    // フォルダ数
    $folder_count = count(get_all_filebird_folders());

    // ストレージ使用量概算
    $total_size = $wpdb->get_var(
        "SELECT SUM(CAST(pm.meta_value AS UNSIGNED))
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE p.post_type = 'attachment'
           AND p.post_status = 'inherit'
           AND pm.meta_key = '_wp_attached_file'"
    );

    // アップロードディレクトリのサイズ概算
    $upload_dir = wp_upload_dir();
    $upload_path = $upload_dir['basedir'];

    // 最新アップロード
    $latest = $wpdb->get_row(
        "SELECT ID, post_title, post_date, post_mime_type
         FROM {$wpdb->posts}
         WHERE post_type = 'attachment' AND post_status = 'inherit'
         ORDER BY post_date DESC
         LIMIT 1"
    );

    $stats = array(
        'total_media'     => $total,
        'by_mime_type'    => $by_mime,
        'total_folders'   => $folder_count,
        'upload_dir'      => $upload_dir['baseurl'],
        'latest_upload'   => $latest ? array(
            'id'        => (int) $latest->ID,
            'title'     => $latest->post_title,
            'date'      => $latest->post_date,
            'mime_type' => $latest->post_mime_type,
        ) : null,
    );

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => $stats,
    ), 200);
}

/**
 * 3-7. GET /search — メディア横断検索
 */
function fbapi_search_callback(WP_REST_Request $request)
{
    $query    = sanitize_text_field($request->get_param('q'));
    $mime     = $request->get_param('mime_type');
    $folder   = $request->get_param('folder_name');
    $per_page = min($request->get_param('per_page'), FBAPI_MAX_PER_PAGE);
    $page     = max(1, $request->get_param('page'));

    if (empty($query)) {
        return new WP_Error(
            'empty_query',
            __('Search query is required.', 'fourier'),
            array('status' => 400)
        );
    }

    $query_args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        's'              => $query,
        'orderby'        => 'relevance',
    );

    if ($mime) {
        $query_args['post_mime_type'] = $mime;
    }

    // フォルダフィルタ
    if ($folder) {
        $folder_id = get_folder_id_by_folder_name(sanitize_text_field($folder));
        if ($folder_id) {
            $folder_attachment_ids = get_attachments_by_filebird_folder_id($folder_id);
            if (is_array($folder_attachment_ids) && ! empty($folder_attachment_ids)) {
                $query_args['post__in'] = $folder_attachment_ids;
            } else {
                // フォルダに何もない場合は空の結果を返す
                return new WP_REST_Response(array(
                    'success' => true,
                    'data'    => array(
                        'results'    => array(),
                        'query'      => $query,
                        'pagination' => array(
                            'total_results' => 0,
                            'total_pages'   => 0,
                            'current_page'  => $page,
                            'per_page'      => $per_page,
                        ),
                    ),
                ), 200);
            }
        }
    }

    $wp_query = new WP_Query($query_args);

    $results = array();
    if ($wp_query->have_posts()) {
        while ($wp_query->have_posts()) {
            $wp_query->the_post();
            $att_id = get_the_ID();
            $results[] = fbapi_get_image_detail($att_id, array(
                'include_metadata'    => false,
                'include_description' => true,
                'include_folder'      => true,
                'include_urls'        => true,
            ));
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => array(
            'results'    => $results,
            'query'      => $query,
            'pagination' => array(
                'total_results' => (int) $wp_query->found_posts,
                'total_pages'   => (int) $wp_query->max_num_pages,
                'current_page'  => $page,
                'per_page'      => $per_page,
            ),
        ),
    ), 200);
}

/**
 * 3-8. POST /batch — バッチ画像情報取得
 */
function fbapi_batch_callback(WP_REST_Request $request)
{
    $ids    = $request->get_param('ids');
    $format = $request->get_param('format') ?: 'url';
    $size   = $request->get_param('size') ?: 'medium';

    if (! is_array($ids) || empty($ids)) {
        return new WP_Error(
            'invalid_ids',
            __('Please provide an array of attachment IDs.', 'fourier'),
            array('status' => 400)
        );
    }

    // base64の場合は上限を厳しくする
    $max_ids = ($format === 'base64') ? 20 : FBAPI_AI_MAX_PER_PAGE;
    $ids = array_slice(array_map('absint', $ids), 0, $max_ids);

    $images = array();
    $errors = array();

    foreach ($ids as $att_id) {
        if ($format === 'url' || $format === 'base64') {
            $data = fbapi_get_ai_image_data($att_id, array(
                'format'              => $format,
                'size'                => $size,
                'include_metadata'    => true,
                'include_description' => true,
            ));
        } else {
            $data = fbapi_get_image_detail($att_id, array(
                'include_metadata'    => true,
                'include_description' => true,
                'include_folder'      => true,
                'include_urls'        => false,
            ));
        }

        if ($data) {
            $images[] = $data;
        } else {
            $errors[] = array(
                'id'      => $att_id,
                'message' => __('Attachment not found.', 'fourier'),
            );
        }
    }

    return new WP_REST_Response(array(
        'success' => true,
        'data'    => array(
            'images'          => $images,
            'errors'          => $errors,
            'total_requested' => count($ids),
            'total_found'     => count($images),
            'format'          => $format,
        ),
    ), 200);
}


/* ================================================================
 * 5. 管理画面 — APIキー設定
 * ================================================================ */

/**
 * 設定メニューにAPIキー管理ページを追加
 */
add_action('admin_menu', function () {
    add_options_page(
        __('FileBird API Settings', 'fourier'),    // ページタイトル
        __('FileBird API', 'fourier'),              // メニュータイトル
        'manage_options',                            // 権限
        'fourier-api-settings',                      // スラッグ
        'fbapi_settings_page_render'                 // コールバック
    );
});

/**
 * 設定の登録
 */
add_action('admin_init', function () {
    register_setting('fourier_api_settings', FBAPI_OPTION_KEY, array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ));

    add_settings_section(
        'fbapi_main_section',
        __('API Key Management', 'fourier'),
        function () {
            echo '<p>' . esc_html__('Manage the API key for authenticated endpoints (AI image data, batch operations).', 'fourier') . '</p>';
        },
        'fourier-api-settings'
    );

    add_settings_field(
        'fourier_api_key_field',
        __('API Key', 'fourier'),
        'fbapi_api_key_field_render',
        'fourier-api-settings',
        'fbapi_main_section'
    );
});

/**
 * APIキー入力フィールドの描画
 */
function fbapi_api_key_field_render()
{
    $value = get_option(FBAPI_OPTION_KEY, '');
    ?>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <input type="text"
               id="fourier_api_key"
               name="<?php echo esc_attr(FBAPI_OPTION_KEY); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               style="font-family: monospace;" />
        <button type="button" class="button" onclick="fbapi_generate_key()">
            <?php esc_html_e('Generate New Key', 'fourier'); ?>
        </button>
        <button type="button" class="button" onclick="fbapi_copy_key()">
            <?php esc_html_e('Copy', 'fourier'); ?>
        </button>
    </div>
    <p class="description">
        <?php esc_html_e('This key is required for AI endpoints (/ai/images, /ai/image/{id}, /batch). Provide it via X-API-Key header or api_key query parameter.', 'fourier'); ?>
    </p>
    <script>
    function fbapi_generate_key() {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var key = 'fapi_';
        for (var i = 0; i < 40; i++) {
            key += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('fourier_api_key').value = key;
    }
    function fbapi_copy_key() {
        var field = document.getElementById('fourier_api_key');
        field.select();
        document.execCommand('copy');
        alert('<?php esc_html_e('API key copied to clipboard.', 'fourier'); ?>');
    }
    </script>
    <?php
}

/**
 * 設定ページの描画
 */
function fbapi_settings_page_render()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $api_base = rest_url(FBAPI_NAMESPACE);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('FileBird API Settings', 'fourier'); ?></h1>

        <form method="post" action="options.php">
            <?php
            settings_fields('fourier_api_settings');
            do_settings_sections('fourier-api-settings');
            submit_button();
            ?>
        </form>

        <hr />

        <h2><?php esc_html_e('API Endpoints Reference', 'fourier'); ?></h2>
        <table class="widefat striped" style="max-width: 900px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Method', 'fourier'); ?></th>
                    <th><?php esc_html_e('Endpoint', 'fourier'); ?></th>
                    <th><?php esc_html_e('Auth', 'fourier'); ?></th>
                    <th><?php esc_html_e('Description', 'fourier'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>GET</code></td>
                    <td><code><?php echo esc_html($api_base); ?>/images</code></td>
                    <td>—</td>
                    <td><?php esc_html_e('Folder images list (with sort/filter)', 'fourier'); ?></td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code><?php echo esc_html($api_base); ?>/images/{id}</code></td>
                    <td>—</td>
                    <td><?php esc_html_e('Single image detail', 'fourier'); ?></td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code><?php echo esc_html($api_base); ?>/ai/images</code></td>
                    <td>🔑</td>
                    <td><?php esc_html_e('AI-ready image data (base64/URL/metadata)', 'fourier'); ?></td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code><?php echo esc_html($api_base); ?>/ai/image/{id}</code></td>
                    <td>🔑</td>
                    <td><?php esc_html_e('AI-ready single image (base64 default)', 'fourier'); ?></td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code><?php echo esc_html($api_base); ?>/folders</code></td>
                    <td>—</td>
                    <td><?php esc_html_e('Folder tree with image counts', 'fourier'); ?></td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code><?php echo esc_html($api_base); ?>/stats</code></td>
                    <td>—</td>
                    <td><?php esc_html_e('Media statistics', 'fourier'); ?></td>
                </tr>
                <tr>
                    <td><code>GET</code></td>
                    <td><code><?php echo esc_html($api_base); ?>/search</code></td>
                    <td>—</td>
                    <td><?php esc_html_e('Search across all media', 'fourier'); ?></td>
                </tr>
                <tr>
                    <td><code>POST</code></td>
                    <td><code><?php echo esc_html($api_base); ?>/batch</code></td>
                    <td>🔑</td>
                    <td><?php esc_html_e('Batch image data retrieval', 'fourier'); ?></td>
                </tr>
            </tbody>
        </table>
        <p class="description" style="margin-top: 12px;">
            🔑 = <?php esc_html_e('API key required (X-API-Key header or api_key parameter)', 'fourier'); ?>
        </p>
    </div>
    <?php
}
