<?php
/**
 * URLからAI学習データを作る非同期パイプライン。
 * 1 URL = 1 pending投稿。結果はJSONとpost_metaに保存するため、レビュー・再実行が可能。
 */
if (!defined('ABSPATH')) exit;

function fourier_pipeline_stages() {
    return ['scraping', 'extraction', 'summary', 'instruction', 'qa', 'chat', 'tags', 'concepts', 'knowledge', 'review'];
}

function fourier_concept_pipeline_stages() {
    return ['concept_map', 'branch_questions', 'branch_answers', 'knowledge', 'review'];
}

function fourier_pipeline_update($id, $stage, $status = 'processing', $message = '') {
    update_post_meta($id, '_fourier_pipeline_stage', sanitize_key($stage));
    update_post_meta($id, '_fourier_pipeline_status', sanitize_key($status));
    update_post_meta($id, '_fourier_pipeline_message', sanitize_textarea_field($message));
    update_post_meta($id, '_fourier_pipeline_updated', current_time('mysql'));
}

function fourier_pipeline_enqueue($id, $delay = 1) {
    wp_schedule_single_event(time() + max(1, (int) $delay), 'fourier_pipeline_worker', [(int) $id]);
}
add_action('fourier_pipeline_worker', 'fourier_pipeline_worker');

function fourier_pipeline_start($url, $user_id = 0) {
    $url = esc_url_raw(trim($url));
    if (!$url || !wp_http_validate_url($url)) return new WP_Error('invalid_url', '有効なURLを入力してください。');
    $existing = get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_fourier_pipeline_url', 'meta_value' => $url, 'fields' => 'ids', 'numberposts' => 1]);
    if ($existing) return new WP_Error('duplicate_url', 'このURLはすでにパイプラインに登録されています。', ['post_id' => $existing[0]]);
    $id = wp_insert_post(['post_title' => 'Pipeline: ' . wp_parse_url($url, PHP_URL_HOST), 'post_content' => '', 'post_status' => 'pending', 'post_type' => 'post', 'post_author' => $user_id ?: get_current_user_id()]);
    if (is_wp_error($id)) return $id;
    update_post_meta($id, '_fourier_pipeline_url', $url);
    update_post_meta($id, '_fourier_pipeline_owner', $user_id ?: get_current_user_id());
    $provider = sanitize_key($_POST['provider'] ?? 'openai');
    if (!in_array($provider, ['openai', 'gemini', 'ollama', 'custom'], true)) $provider = 'openai';
    update_post_meta($id, '_fourier_pipeline_provider', $provider);
    if (!empty($_POST['knowledge_url'])) update_user_meta($user_id ?: get_current_user_id(), 'fourier_knowledge_server_url', esc_url_raw($_POST['knowledge_url']));
    if (!empty($_POST['knowledge_token'])) update_user_meta($user_id ?: get_current_user_id(), 'fourier_knowledge_server_token', sanitize_text_field($_POST['knowledge_token']));
    update_post_meta($id, '_fourier_pipeline_data', []);
    fourier_pipeline_update($id, 'scraping', 'queued', '処理待ち');
    fourier_pipeline_enqueue($id);
    return $id;
}

/** 概念を起点に観念の枝・質問・複数回答を生成するパイプラインを登録します。 */
function fourier_concept_pipeline_start($concept, $user_id = 0) {
    $concept = sanitize_text_field(trim($concept));
    if (!$concept || mb_strlen($concept) > 120) return new WP_Error('invalid_concept', '概念名は1〜120文字で入力してください。');
    $user_id = $user_id ?: get_current_user_id();
    $existing = get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_fourier_pipeline_concept', 'meta_value' => $concept, 'fields' => 'ids', 'numberposts' => 1]);
    if ($existing) return new WP_Error('duplicate_concept', 'この概念はすでに蒸留キューに登録されています。', ['post_id' => $existing[0]]);
    $id = wp_insert_post(['post_title' => 'Concept Distillation: ' . $concept, 'post_content' => '', 'post_status' => 'pending', 'post_type' => 'post', 'post_author' => $user_id]);
    if (is_wp_error($id)) return $id;
    $provider = sanitize_key($_POST['provider'] ?? 'openai');
    if (!in_array($provider, ['openai', 'gemini', 'ollama', 'custom'], true)) $provider = 'openai';
    update_post_meta($id, '_fourier_pipeline_kind', 'concept');
    update_post_meta($id, '_fourier_pipeline_concept', $concept);
    update_post_meta($id, '_fourier_pipeline_owner', $user_id);
    update_post_meta($id, '_fourier_pipeline_provider', $provider);
    update_post_meta($id, '_fourier_pipeline_data', ['concept' => $concept]);
    if (!empty($_POST['knowledge_url'])) update_user_meta($user_id, 'fourier_knowledge_server_url', esc_url_raw($_POST['knowledge_url']));
    if (!empty($_POST['knowledge_token'])) update_user_meta($user_id, 'fourier_knowledge_server_token', sanitize_text_field($_POST['knowledge_token']));
    fourier_pipeline_update($id, 'concept_map', 'queued', '概念マップの生成待ち');
    fourier_pipeline_enqueue($id);
    return $id;
}

function fourier_pipeline_fetch($url) {
    $response = wp_safe_remote_get($url, ['timeout' => 45, 'redirection' => 5, 'headers' => ['User-Agent' => 'Fourier Learning Data Pipeline/1.0']]);
    if (is_wp_error($response)) throw new Exception($response->get_error_message());
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 400) throw new Exception('取得先がHTTP ' . $code . 'を返しました。');
    $html = wp_remote_retrieve_body($response);
    if (!$html) throw new Exception('本文を取得できませんでした。');
    $html = preg_replace('/<(script|style|noscript|svg|nav|footer|header)[^>]*>.*?<\/\1>/is', ' ', $html);
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $title = trim(wp_strip_all_tags(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')));
    $text = preg_replace('/\s+/u', ' ', trim(wp_strip_all_tags($html)));
    return ['title' => $title, 'text' => mb_substr($text, 0, 120000), 'html' => mb_substr($html, 0, 200000)];
}

function fourier_pipeline_llm($provider, $instruction, $source) {
    $system = 'あなたはAI学習データの編集者です。入力にない事実を追加せず、日本語で正確に処理してください。必ずJSONオブジェクトだけを返してください。';
    return llm_api_call_raw($provider, $system, $instruction . "\n\n対象本文:\n" . mb_substr($source, 0, 50000));
}

function fourier_pipeline_json($value) {
    $decoded = is_array($value) ? $value : _parse_json_from_llm_response($value);
    return is_array($decoded) ? $decoded : ['value' => $decoded];
}

function fourier_concept_branch_seed() {
    return [
        ['id' => 'characteristics', 'label' => '特徴'],
        ['id' => 'taxonomy', 'label' => '生物分類・構造'],
        ['id' => 'behavior', 'label' => '習性・機能'],
        ['id' => 'history', 'label' => '歴史・変遷'],
        ['id' => 'human_relation', 'label' => '人間との関係・利用'],
        ['id' => 'related_terms', 'label' => '関連語・周辺概念'],
        ['id' => 'examples', 'label' => '例・具体化'],
        ['id' => 'misconceptions', 'label' => 'よくある誤解・限界'],
        ['id' => 'comparison', 'label' => '他概念・他生物との比較'],
        ['id' => 'reasoning', 'label' => '推論問題・応用'],
    ];
}

function fourier_concept_pipeline_worker($id) {
    $post = get_post((int) $id);
    if (!$post || get_post_meta($id, '_fourier_pipeline_status', true) === 'review') return;
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $data = is_array($data) ? $data : [];
    $stage = get_post_meta($id, '_fourier_pipeline_stage', true) ?: 'concept_map';
    $concept = get_post_meta($id, '_fourier_pipeline_concept', true);
    $provider = get_post_meta($id, '_fourier_pipeline_provider', true) ?: 'openai';
    try {
        if ($stage === 'concept_map') {
            $seed = wp_json_encode(fourier_concept_branch_seed(), JSON_UNESCAPED_UNICODE);
            $prompt = "概念『{$concept}』の知識を、概念単位で漏れなく引き出すための観念マップを作成してください。\n" .
                "次の10カテゴリを必ず検討し、概念に不要な枝は理由付きで除外し、必要な枝を最大3つ追加してください。\n" . $seed .
                "\n出力キーは concept, definition, branches。branchesはid,label,scope,priority（1〜3）,question_angles（配列）を持つ配列にしてください。";
            $data['concept_map'] = fourier_pipeline_json(fourier_pipeline_llm($provider, $prompt, $concept));
            fourier_pipeline_update($id, 'branch_questions', 'queued', '枝ごとの質問を生成します');
        } elseif ($stage === 'branch_questions') {
            $prompt = "概念『{$concept}』について、以下の観念マップの各branchごとに、学習に有効な質問を2〜3問作ってください。単なる定義の反復を避け、事実確認、関係理解、具体例、誤解訂正、比較、推論・応用をバランスよく含めてください。各質問にquestion_id、branch_id、question_type、question、expected_answer_pointsを付けてください。出力キーは questions のみです。\n" . wp_json_encode($data['concept_map'], JSON_UNESCAPED_UNICODE);
            $data['branch_questions'] = fourier_pipeline_json(fourier_pipeline_llm($provider, $prompt, $concept));
            fourier_pipeline_update($id, 'branch_answers', 'queued', '質問ごとの複数回答を生成します');
        } elseif ($stage === 'branch_answers') {
            $prompt = "概念『{$concept}』の各質問に対し、独立した回答候補を3種類ずつ作ってください。1つ目は短い直接回答、2つ目は背景・理由を含む説明、3つ目は具体例・反例・比較・推論のいずれかを含む応用回答です。各回答はquestion_id, branch_id, answer_variants（style,answer,confidence, caveatsの配列）を持つオブジェクトにしてください。入力にない固有事実は断定せず、確信度と注意点を明示してください。出力キーは items のみです。\n" . wp_json_encode(['map' => $data['concept_map'], 'questions' => $data['branch_questions']], JSON_UNESCAPED_UNICODE);
            $data['branch_answers'] = fourier_pipeline_json(fourier_pipeline_llm($provider, $prompt, $concept));
            fourier_pipeline_update($id, 'knowledge', 'queued', 'Knowledge Server登録を準備します');
        } elseif ($stage === 'knowledge') {
            $owner = (int) get_post_meta($id, '_fourier_pipeline_owner', true);
            $ks_url = get_user_meta($owner, 'fourier_knowledge_server_url', true);
            $ks_token = get_user_meta($owner, 'fourier_knowledge_server_token', true);
            if ($ks_url) {
                $res = wp_safe_remote_post(rtrim($ks_url, '/') . '/items', ['timeout' => 45, 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $ks_token], 'body' => wp_json_encode(['type' => 'concept_distillation', 'concept' => $concept, 'data' => $data], JSON_UNESCAPED_UNICODE)]);
                if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 400) throw new Exception('Knowledge Serverへの概念データ登録に失敗しました。');
                $data['knowledge'] = ['registered' => true, 'response_code' => wp_remote_retrieve_response_code($res)];
            } else $data['knowledge'] = ['registered' => false, 'message' => 'Knowledge Server未設定のためスキップ'];
            fourier_pipeline_update($id, 'review', 'review', '概念蒸留のレビュー待ち');
        }
        update_post_meta($id, '_fourier_pipeline_data', $data);
        wp_update_post(['ID' => $id, 'post_content' => wp_slash(wp_json_encode(['format' => 'concept_distillation', 'data' => $data], JSON_UNESCAPED_UNICODE))]);
        if (get_post_meta($id, '_fourier_pipeline_status', true) !== 'review') fourier_pipeline_enqueue($id, 2);
    } catch (Throwable $e) {
        fourier_pipeline_update($id, $stage, 'error', $e->getMessage());
        update_post_meta($id, '_fourier_pipeline_error', $e->getMessage());
    }
}

function fourier_pipeline_worker($id) {
    $post = get_post((int) $id);
    if (!$post || get_post_meta($id, '_fourier_pipeline_status', true) === 'review') return;
    if (get_post_meta($id, '_fourier_pipeline_kind', true) === 'concept') {
        fourier_concept_pipeline_worker($id);
        return;
    }
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $data = is_array($data) ? $data : [];
    $stage = get_post_meta($id, '_fourier_pipeline_stage', true) ?: 'scraping';
    try {
        if ($stage === 'scraping') {
            $data['source'] = fourier_pipeline_fetch(get_post_meta($id, '_fourier_pipeline_url', true));
            fourier_pipeline_update($id, 'extraction', 'queued');
        } elseif ($stage === 'extraction') {
            $data['extraction'] = ['text' => $data['source']['text'], 'title' => $data['source']['title']];
            fourier_pipeline_update($id, 'summary', 'queued');
        } else {
            $provider = get_post_meta($id, '_fourier_pipeline_provider', true) ?: 'openai';
            $text = $data['extraction']['text'] ?? '';
            $prompts = [
                'summary' => '本文を3〜5項目で要約し、重要な用語と出典URLも含めてください。キーは summary, key_points, source_url。',
                'instruction' => '本文から実務で使えるInstruction/Outputを3件作ってください。キーは items（instruction,input,outputの配列）。',
                'qa' => '本文に基づくQ&Aを5件作ってください。推測は禁止。キーは items（question,answerの配列）。',
                'chat' => '本文に基づく自然な対話データを3件作ってください。キーは items（messages配列）の配列。',
                'tags' => '本文の検索用タグを最大12個と、1文の説明を作ってください。キーは tags, description。',
                'concepts' => '本文から概念・関係を抽出してください。キーは concepts（name, definition, relationsの配列）。',
            ];
            if (isset($prompts[$stage])) {
                $data[$stage] = fourier_pipeline_json(fourier_pipeline_llm($provider, $prompts[$stage], $text));
                $next = ['summary' => 'instruction', 'instruction' => 'qa', 'qa' => 'chat', 'chat' => 'tags', 'tags' => 'concepts', 'concepts' => 'knowledge'][$stage];
                fourier_pipeline_update($id, $next, 'queued');
            } elseif ($stage === 'knowledge') {
                $ks_url = get_user_meta((int) get_post_meta($id, '_fourier_pipeline_owner', true), 'fourier_knowledge_server_url', true);
                $ks_token = get_user_meta((int) get_post_meta($id, '_fourier_pipeline_owner', true), 'fourier_knowledge_server_token', true);
                if ($ks_url) {
                    $res = wp_safe_remote_post(rtrim($ks_url, '/') . '/items', ['timeout' => 30, 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $ks_token], 'body' => wp_json_encode(['source_url' => get_post_meta($id, '_fourier_pipeline_url', true), 'data' => $data])]);
                    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 400) throw new Exception('Knowledge Serverへの登録に失敗しました。');
                    $data['knowledge'] = ['registered' => true, 'response_code' => wp_remote_retrieve_response_code($res)];
                } else $data['knowledge'] = ['registered' => false, 'message' => 'Knowledge Server未設定のためスキップ'];
                fourier_pipeline_update($id, 'review', 'review');
            }
        }
        update_post_meta($id, '_fourier_pipeline_data', $data);
        wp_update_post(['ID' => $id, 'post_title' => sanitize_text_field(($data['source']['title'] ?? '') ?: get_the_title($id)), 'post_content' => wp_slash(wp_json_encode(['format' => 'pipeline', 'data' => $data], JSON_UNESCAPED_UNICODE))]);
        if (get_post_meta($id, '_fourier_pipeline_status', true) !== 'review') fourier_pipeline_enqueue($id, 2);
    } catch (Throwable $e) {
        fourier_pipeline_update($id, $stage, 'error', $e->getMessage());
        update_post_meta($id, '_fourier_pipeline_error', $e->getMessage());
    }
}

function fourier_pipeline_ajax_start() {
    check_ajax_referer('learning_data_action', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'ログインが必要です。'], 403);
    $id = fourier_pipeline_start($_POST['url'] ?? '');
    if (is_wp_error($id)) wp_send_json_error(['message' => $id->get_error_message(), 'post_id' => $id->get_error_data()]);
    wp_send_json_success(['post_id' => $id, 'message' => 'パイプラインを開始しました。']);
}
add_action('wp_ajax_fourier_pipeline_start', 'fourier_pipeline_ajax_start');

function fourier_concept_pipeline_ajax_start() {
    check_ajax_referer('learning_data_action', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'ログインが必要です。'], 403);
    $id = fourier_concept_pipeline_start($_POST['concept'] ?? '');
    if (is_wp_error($id)) wp_send_json_error(['message' => $id->get_error_message(), 'post_id' => $id->get_error_data()]);
    wp_send_json_success(['post_id' => $id, 'message' => '概念蒸留を開始しました。']);
}
add_action('wp_ajax_fourier_concept_pipeline_start', 'fourier_concept_pipeline_ajax_start');

function fourier_pipeline_ajax_status() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !get_post($id)) wp_send_json_error(['message' => '対象がありません。']);
    wp_send_json_success(['stage' => get_post_meta($id, '_fourier_pipeline_stage', true), 'status' => get_post_meta($id, '_fourier_pipeline_status', true), 'message' => get_post_meta($id, '_fourier_pipeline_message', true), 'updated' => get_post_meta($id, '_fourier_pipeline_updated', true)]);
}
add_action('wp_ajax_fourier_pipeline_status', 'fourier_pipeline_ajax_status');

function fourier_pipeline_ajax_review() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => '権限がありません。'], 403);
    $decision = sanitize_key($_POST['decision'] ?? '');
    if ($decision === 'approve') { wp_update_post(['ID' => $id, 'post_status' => 'publish']); update_post_meta($id, '_fourier_pipeline_review', 'approved'); fourier_pipeline_update($id, 'review', 'approved', 'レビューで承認されました。'); }
    elseif ($decision === 'reject') { update_post_meta($id, '_fourier_pipeline_review', 'rejected'); fourier_pipeline_update($id, 'review', 'rejected', 'レビューで差し戻されました。'); }
    else wp_send_json_error(['message' => '不正な操作です。']);
    wp_send_json_success(['message' => 'レビュー結果を保存しました。']);
}
add_action('wp_ajax_fourier_pipeline_review', 'fourier_pipeline_ajax_review');
