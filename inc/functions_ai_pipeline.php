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
    return ['concept_map', 'branch_questions', 'branch_answers', 'answer_quality', 'knowledge', 'review'];
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

/** 概念蒸留の用途別品質プリセット。 */
function fourier_concept_quality_profiles() {
    return [
        'exploratory' => ['minimum_score' => 50, 'duplicate_similarity' => 0.75],
        'balanced' => ['minimum_score' => 65, 'duplicate_similarity' => 0.82],
        'strict' => ['minimum_score' => 75, 'duplicate_similarity' => 0.88],
    ];
}

function fourier_concept_resolve_quality_settings($profile = 'balanced', $minimum_score = null, $duplicate_similarity = null) {
    $profiles = fourier_concept_quality_profiles();
    $profile = sanitize_key($profile);
    if (isset($profiles[$profile])) {
        return array_merge(['profile' => $profile], $profiles[$profile]);
    }
    $minimum_score = is_numeric($minimum_score) ? (int) $minimum_score : 65;
    $duplicate_similarity = is_numeric($duplicate_similarity) ? (float) $duplicate_similarity : 0.82;
    if ($duplicate_similarity > 1) $duplicate_similarity /= 100;
    return [
        'profile' => 'custom',
        'minimum_score' => max(40, min(95, $minimum_score)),
        'duplicate_similarity' => round(max(0.6, min(0.98, $duplicate_similarity)), 2),
    ];
}

function fourier_concept_get_quality_settings($id) {
    return fourier_concept_resolve_quality_settings(
        get_post_meta($id, '_fourier_concept_quality_profile', true) ?: 'balanced',
        get_post_meta($id, '_fourier_concept_quality_minimum', true),
        get_post_meta($id, '_fourier_concept_quality_duplicate', true)
    );
}

/** 日本語を含む回答を比較できる形へ正規化します。 */
function fourier_concept_normalize_text($value) {
    $text = wp_strip_all_tags((string) $value);
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
        if (is_string($normalized)) $text = $normalized;
    }
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?: '';
}

function fourier_concept_text_ngrams($value, $size = 3) {
    $text = fourier_concept_normalize_text($value);
    $length = mb_strlen($text, 'UTF-8');
    if (!$length) return [];
    if ($length <= $size) return [$text => true];
    $grams = [];
    for ($i = 0; $i <= $length - $size; $i++) $grams[mb_substr($text, $i, $size, 'UTF-8')] = true;
    return $grams;
}

function fourier_concept_jaccard($left, $right) {
    if (!$left || !$right) return 0.0;
    $intersection = count(array_intersect_key($left, $right));
    $union = count($left) + count($right) - $intersection;
    return $union ? $intersection / $union : 0.0;
}

/** reference側の文字n-gramがanswer内でどれだけ回収されているかを返します。 */
function fourier_concept_text_coverage($answer, $reference, $size = 2) {
    $answer_grams = fourier_concept_text_ngrams($answer, $size);
    $reference_grams = fourier_concept_text_ngrams($reference, $size);
    if (!$answer_grams || !$reference_grams) return 0.0;
    return count(array_intersect_key($answer_grams, $reference_grams)) / count($reference_grams);
}

/** 完全一致と2/3文字n-gramを組み合わせた、言語非依存の類似度です。 */
function fourier_concept_semantic_similarity($left, $right) {
    $normalized_left = fourier_concept_normalize_text($left);
    $normalized_right = fourier_concept_normalize_text($right);
    if (!$normalized_left || !$normalized_right) return 0.0;
    if ($normalized_left === $normalized_right) return 1.0;
    $bigram = fourier_concept_jaccard(fourier_concept_text_ngrams($left, 2), fourier_concept_text_ngrams($right, 2));
    $trigram = fourier_concept_jaccard(fourier_concept_text_ngrams($left, 3), fourier_concept_text_ngrams($right, 3));
    return round(($bigram * 0.35) + ($trigram * 0.65), 4);
}

function fourier_concept_confidence_score($confidence, $caveats) {
    $has_caveat = is_array($caveats) ? count(array_filter($caveats)) > 0 : trim((string) $caveats) !== '';
    if (is_numeric($confidence)) {
        $value = (float) $confidence;
        if ($value > 1) $value /= 100;
        return max(5, min(15, (int) round(8 + (max(0, min(1, $value)) * 5) + ($has_caveat ? 2 : 0))));
    }
    $value = strtolower(trim((string) $confidence));
    if ($value === '') return $has_caveat ? 7 : 3;
    return 11 + ($has_caveat ? 2 : 0);
}

/** 1回答を100点満点で採点し、レビュー可能な内訳を返します。 */
function fourier_concept_score_answer($answer, $question, $expected_points = [], $confidence = '', $caveats = []) {
    $answer = trim((string) $answer);
    $length = mb_strlen(fourier_concept_normalize_text($answer), 'UTF-8');
    $context_parts = array_merge([(string) $question], is_array($expected_points) ? $expected_points : [(string) $expected_points]);
    $context = implode(' ', array_filter(array_map('strval', $context_parts)));
    $relevance_similarity = fourier_concept_text_coverage($answer, $context, 2);
    $relevance = min(30, (int) round(($relevance_similarity / 0.45) * 30));

    $points = array_values(array_filter(is_array($expected_points) ? $expected_points : [(string) $expected_points]));
    if ($points) {
        $point_scores = [];
        foreach ($points as $point) {
            $similarity = fourier_concept_text_coverage($answer, $point, 2);
            $point_scores[] = min(1, $similarity / 0.55);
        }
        $completeness = (int) round((array_sum($point_scores) / count($point_scores)) * 25);
    } else {
        $completeness = min(18, (int) round($length / 4));
    }

    if ($length < 8) $substance = max(0, $length);
    elseif ($length < 35) $substance = 8 + (int) round(($length - 8) / 4);
    else $substance = min(20, 15 + (int) round(($length - 35) / 25));
    $confidence_score = fourier_concept_confidence_score($confidence, $caveats);
    $clarity = $answer === '' ? 0 : 5;
    if (preg_match('/[。.!?！？]/u', $answer)) $clarity += 3;
    if (!preg_match('/(.)\1{5,}/u', $answer)) $clarity += 2;

    $score = max(0, min(100, $relevance + $completeness + $substance + $confidence_score + $clarity));
    $reasons = [];
    if ($length < 15) $reasons[] = '回答が短く、学習に必要な説明が不足しています。';
    if ($relevance < 12) $reasons[] = '質問・期待回答点との関連性が低い可能性があります。';
    if ($completeness < 10 && $points) $reasons[] = '期待される回答点を十分に含んでいません。';
    if ($confidence_score < 8) $reasons[] = '確信度または注意点が不足しています。';
    if (!$reasons) $reasons[] = '主要な品質基準を満たしています。';

    return [
        'score' => $score,
        'breakdown' => [
            'relevance' => $relevance,
            'completeness' => $completeness,
            'substance' => $substance,
            'confidence' => $confidence_score,
            'clarity' => $clarity,
        ],
        'reasons' => $reasons,
    ];
}

/**
 * 回答候補を採点し、低品質・重複を除いたKnowledge Server向け回答を作ります。
 * 元のbranch_answersは変更せず、判定根拠をanswer_qualityへ保存します。
 */
function fourier_concept_evaluate_answers($concept, $branch_questions, $branch_answers, $minimum_score = 65, $duplicate_threshold = 0.82) {
    $questions = is_array($branch_questions['questions'] ?? null) ? $branch_questions['questions'] : [];
    $question_lookup = [];
    foreach ($questions as $question) {
        $question_id = sanitize_key($question['question_id'] ?? '');
        if ($question_id !== '') $question_lookup[$question_id] = $question;
    }

    $items = is_array($branch_answers['items'] ?? null) ? $branch_answers['items'] : [];
    $candidates = [];
    foreach ($items as $item_index => $item) {
        $question_id = sanitize_key($item['question_id'] ?? ('question-' . ($item_index + 1)));
        $question_data = $question_lookup[$question_id] ?? [];
        $question_text = trim((string) ($question_data['question'] ?? ''));
        $expected_points = $question_data['expected_answer_points'] ?? [];
        $variants = is_array($item['answer_variants'] ?? null) ? $item['answer_variants'] : [];
        foreach ($variants as $variant_index => $variant) {
            $answer = trim((string) ($variant['answer'] ?? ''));
            $quality = fourier_concept_score_answer($answer, trim($concept . ' ' . $question_text), $expected_points, $variant['confidence'] ?? '', $variant['caveats'] ?? []);
            $variant_id = $question_id . '-v' . ($variant_index + 1);
            $candidates[] = [
                'variant_id' => $variant_id,
                'question_id' => $question_id,
                'branch_id' => sanitize_key($item['branch_id'] ?? ($question_data['branch_id'] ?? '')),
                'question' => $question_text,
                'variant' => array_merge($variant, ['quality_score' => $quality['score'], 'quality_breakdown' => $quality['breakdown'], 'quality_reasons' => $quality['reasons']]),
                'score' => $quality['score'],
                'source_order' => count($candidates),
            ];
        }
    }

    usort($candidates, static function ($left, $right) {
        return $right['score'] <=> $left['score'] ?: $left['source_order'] <=> $right['source_order'];
    });
    $accepted = [];
    $rejected = [];
    foreach ($candidates as $candidate) {
        if ($candidate['score'] < $minimum_score || fourier_concept_normalize_text($candidate['variant']['answer'] ?? '') === '') {
            $candidate['variant']['review_status'] = 'rejected_low_quality';
            $rejected[] = $candidate;
            continue;
        }
        $duplicate = null;
        foreach ($accepted as $accepted_candidate) {
            $same_question = $candidate['question_id'] === $accepted_candidate['question_id'];
            $threshold = $same_question ? $duplicate_threshold : max(0.9, $duplicate_threshold);
            $similarity = fourier_concept_semantic_similarity($candidate['variant']['answer'] ?? '', $accepted_candidate['variant']['answer'] ?? '');
            if ($similarity >= $threshold) {
                $duplicate = ['variant_id' => $accepted_candidate['variant_id'], 'similarity' => $similarity];
                break;
            }
        }
        if ($duplicate) {
            $candidate['variant']['review_status'] = 'rejected_duplicate';
            $candidate['variant']['duplicate_of'] = $duplicate['variant_id'];
            $candidate['variant']['duplicate_similarity'] = $duplicate['similarity'];
            $candidate['variant']['quality_reasons'][] = '採用済み回答と意味的に重複しています。';
            $rejected[] = $candidate;
        } else {
            $candidate['variant']['review_status'] = 'accepted';
            $accepted[] = $candidate;
        }
    }

    $grouped = [];
    foreach (array_merge($accepted, $rejected) as $candidate) {
        $question_id = $candidate['question_id'];
        if (!isset($grouped[$question_id])) $grouped[$question_id] = ['question_id' => $question_id, 'branch_id' => $candidate['branch_id'], 'question' => $candidate['question'], 'accepted_variants' => [], 'rejected_variants' => []];
        $target = $candidate['variant']['review_status'] === 'accepted' ? 'accepted_variants' : 'rejected_variants';
        $grouped[$question_id][$target][] = array_merge(['variant_id' => $candidate['variant_id']], $candidate['variant']);
    }
    $duplicate_rejected = count(array_filter($rejected, static function ($candidate) {
        return ($candidate['variant']['review_status'] ?? '') === 'rejected_duplicate';
    }));
    $low_quality_rejected = count($rejected) - $duplicate_rejected;
    $average = $candidates ? round(array_sum(array_column($candidates, 'score')) / count($candidates), 1) : 0;
    $curated_items = [];
    foreach ($grouped as $group) {
        if (empty($group['accepted_variants'])) continue;
        $curated_items[] = [
            'question_id' => $group['question_id'],
            'branch_id' => $group['branch_id'],
            'question' => $group['question'],
            'answer_variants' => $group['accepted_variants'],
        ];
    }

    return [
        'schema_version' => '1.0',
        'thresholds' => ['minimum_score' => (int) $minimum_score, 'duplicate_similarity' => (float) $duplicate_threshold],
        'summary' => [
            'total_variants' => count($candidates),
            'accepted_variants' => count($accepted),
            'rejected_variants' => count($rejected),
            'low_quality_rejected' => $low_quality_rejected,
            'duplicate_rejected' => $duplicate_rejected,
            'average_score' => $average,
        ],
        'items' => array_values($grouped),
        'curated_items' => $curated_items,
    ];
}

/** LLMの表記揺れを吸収し、確信度を0〜100へ正規化します。 */
function fourier_concept_confidence_percent($confidence) {
    if (is_numeric($confidence)) {
        $value = (float) $confidence;
        if ($value <= 1) $value *= 100;
        return max(0, min(100, (int) round($value)));
    }
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $confidence), 'UTF-8') : strtolower(trim((string) $confidence));
    if ($value === '') return 50;
    if (preg_match('/high|高|strong|確実|可靠|高置信/u', $value)) return 90;
    if (preg_match('/low|低|weak|不確実|不确定/u', $value)) return 35;
    return 65;
}

/** 質問の認知的な難しさを、カリキュラム用の1〜7段階へ割り当てます。 */
function fourier_concept_difficulty($branch_id, $question, $answer = '') {
    $text = (string) $question . ' ' . (string) $answer;
    $level = [
        'characteristics' => 1, 'taxonomy' => 1, 'related_terms' => 2, 'examples' => 2,
        'behavior' => 2, 'history' => 2, 'human_relation' => 2, 'comparison' => 3,
        'misconceptions' => 4, 'reasoning' => 4,
    ][sanitize_key($branch_id)] ?? 2;
    if (preg_match('/因果|原因|結果|なぜ|causal|cause|why|因果|为什么/u', $text)) $level = max($level, 4);
    if (preg_match('/反実仮想|もし.*なら|counterfactual|what if|假如|如果/u', $text)) $level = max($level, 5);
    if (preg_match('/矛盾|対立|反証|conflict|contradiction|冲突|矛盾/u', $text)) $level = max($level, 6);
    if (preg_match('/仮説|新しい説明|hypothesis|假设/u', $text)) $level = max($level, 7);
    return ['level' => $level, 'score' => (int) round(($level / 7) * 100)];
}

function fourier_concept_has_negation($value) {
    return (bool) preg_match('/ではない|とは限らない|しない|できない|ません|ない|not\b|never\b|cannot\b|isn[\'’]t\b|不是|并非|不会|不能/u', (string) $value);
}

function fourier_concept_without_negation($value) {
    return preg_replace('/ではない|とは限らない|しない|できない|ません|ない|not\b|never\b|cannot\b|isn[\'’]t\b|不是|并非|不会|不能/iu', '', (string) $value);
}

/**
 * 現在の概念空間に対する学習価値を、外部APIを使わず再現可能な形で算出します。
 * 指標は選別支援であり、事実性やライセンスを自動的に保証するものではありません。
 */
function fourier_concept_build_training_value($concept, $concept_map, $branch_questions, $quality, $context = []) {
    $branches = is_array($concept_map['branches'] ?? null) ? $concept_map['branches'] : [];
    $questions = is_array($branch_questions['questions'] ?? null) ? $branch_questions['questions'] : [];
    $question_lookup = [];
    $branch_question_counts = [];
    foreach ($questions as $question) {
        $question_id = sanitize_key($question['question_id'] ?? '');
        $branch_id = sanitize_key($question['branch_id'] ?? '');
        if ($question_id !== '') $question_lookup[$question_id] = $question;
        if ($branch_id !== '') $branch_question_counts[$branch_id] = ($branch_question_counts[$branch_id] ?? 0) + 1;
    }

    $variants = [];
    foreach (($quality['items'] ?? []) as $item) {
        foreach (['accepted_variants' => true, 'rejected_variants' => false] as $key => $accepted) {
            foreach (($item[$key] ?? []) as $variant) {
                $variants[] = [
                    'variant_id' => sanitize_key($variant['variant_id'] ?? ''),
                    'question_id' => sanitize_key($item['question_id'] ?? ''),
                    'branch_id' => sanitize_key($item['branch_id'] ?? ''),
                    'question' => (string) ($item['question'] ?? ''),
                    'answer' => (string) ($variant['answer'] ?? ''),
                    'quality_score' => (int) ($variant['quality_score'] ?? 0),
                    'confidence' => fourier_concept_confidence_percent($variant['confidence'] ?? ''),
                    'accepted' => $accepted,
                    'review_status' => sanitize_key($variant['review_status'] ?? ($accepted ? 'accepted' : 'rejected')),
                    'duplicate_similarity' => (float) ($variant['duplicate_similarity'] ?? 0),
                ];
            }
        }
    }
    $accepted_variants = array_values(array_filter($variants, static function ($variant) { return $variant['accepted']; }));
    $accepted_by_branch = [];
    $accepted_questions_by_branch = [];
    foreach ($accepted_variants as $variant) {
        $accepted_by_branch[$variant['branch_id']] = ($accepted_by_branch[$variant['branch_id']] ?? 0) + 1;
        $accepted_questions_by_branch[$variant['branch_id']][$variant['question_id']] = true;
    }

    $source_id = sanitize_key($context['source_id'] ?? ('concept-' . substr(sha1((string) $concept), 0, 16)));
    $knowledge_id = sanitize_key($context['knowledge_id'] ?? ('knowledge-' . substr(sha1(fourier_concept_normalize_text($concept)), 0, 16)));
    $sample_profiles = [];
    foreach ($variants as $variant) {
        $max_similarity = 0.0;
        foreach ($accepted_variants as $comparison) {
            if ($variant['variant_id'] === $comparison['variant_id']) continue;
            $max_similarity = max($max_similarity, fourier_concept_semantic_similarity($variant['answer'], $comparison['answer']));
        }
        $novelty = (int) round((1 - min(1, $max_similarity)) * 100);
        $reliability = (int) round(($variant['quality_score'] * 0.7) + ($variant['confidence'] * 0.3));
        $branch_total = max(1, (int) ($accepted_by_branch[$variant['branch_id']] ?? 0));
        $question_is_unique = count(array_filter($accepted_variants, static function ($candidate) use ($variant) {
            return $candidate['question_id'] === $variant['question_id'];
        })) <= 1;
        $coverage_gain = min(100, max(15, (int) round(100 / $branch_total)) + ($question_is_unique ? 20 : 0));
        $difficulty = fourier_concept_difficulty($variant['branch_id'], $variant['question'], $variant['answer']);
        $contradiction = 'none_detected';
        foreach ($variants as $comparison) {
            if ($variant['variant_id'] === $comparison['variant_id'] || $variant['question_id'] !== $comparison['question_id']) continue;
            if (fourier_concept_has_negation($variant['answer']) === fourier_concept_has_negation($comparison['answer'])) continue;
            $polarity_similarity = fourier_concept_semantic_similarity(fourier_concept_without_negation($variant['answer']), fourier_concept_without_negation($comparison['answer']));
            if ($polarity_similarity >= 0.45) { $contradiction = 'possible_conflict'; break; }
        }
        $information_gain = round(($novelty * $reliability * $coverage_gain) / 10000, 1);
        if (!$variant['accepted']) $eligibility = 'excluded';
        elseif ($contradiction === 'possible_conflict' || $reliability < 65 || $information_gain < 35) $eligibility = 'review_required';
        else $eligibility = 'eligible';
        $recommendation = $eligibility === 'eligible' && $information_gain >= 55 ? 'add' : ($eligibility === 'excluded' ? 'hold' : 'review');
        $sample_id = 'sample-' . substr(sha1($variant['variant_id'] . '|' . fourier_concept_normalize_text($variant['answer'])), 0, 16);
        $sample_profiles[] = array_merge($variant, [
            'sample_id' => $sample_id,
            'novelty' => $novelty,
            'reliability' => $reliability,
            'coverage_gain' => $coverage_gain,
            'redundancy' => (int) round($max_similarity * 100),
            'difficulty_level' => $difficulty['level'],
            'difficulty' => $difficulty['score'],
            'contradiction_status' => $contradiction,
            'information_gain' => $information_gain,
            'training_eligibility' => $eligibility,
            'recommendation' => $recommendation,
            'lineage' => ['source_id' => $source_id, 'knowledge_id' => $knowledge_id, 'sample_id' => $sample_id],
        ]);
    }

    $branch_profiles = [];
    foreach ($branches as $branch) {
        if (array_key_exists('enabled', $branch) && !$branch['enabled']) continue;
        $branch_id = sanitize_key($branch['id'] ?? '');
        if ($branch_id === '') continue;
        $angles = fourier_concept_sanitize_question_angles($branch['question_angles'] ?? []);
        $target_questions = max(2, min(5, count($angles) ?: 2));
        $question_count = (int) ($branch_question_counts[$branch_id] ?? 0);
        $answered_questions = count($accepted_questions_by_branch[$branch_id] ?? []);
        $question_coverage = min(100, (int) round(($question_count / $target_questions) * 100));
        $answer_coverage = $question_count ? min(100, (int) round(($answered_questions / $question_count) * 100)) : 0;
        $coverage = (int) round(($question_coverage * 0.45) + ($answer_coverage * 0.55));
        $branch_samples = array_values(array_filter($sample_profiles, static function ($sample) use ($branch_id) { return $sample['branch_id'] === $branch_id && $sample['accepted']; }));
        $average_metric = static function ($items, $key) {
            return $items ? round(array_sum(array_column($items, $key)) / count($items), 1) : 0;
        };
        $branch_profiles[] = [
            'branch_id' => $branch_id,
            'label' => sanitize_text_field($branch['label'] ?? $branch_id),
            'priority' => max(1, min(3, (int) ($branch['priority'] ?? 2))),
            'coverage' => $coverage,
            'question_count' => $question_count,
            'answered_question_count' => $answered_questions,
            'accepted_sample_count' => count($branch_samples),
            'reliability' => $average_metric($branch_samples, 'reliability'),
            'novelty' => $average_metric($branch_samples, 'novelty'),
            'redundancy' => $average_metric($branch_samples, 'redundancy'),
            'difficulty' => $average_metric($branch_samples, 'difficulty'),
            'information_gain' => $average_metric($branch_samples, 'information_gain'),
            'conflict_count' => count(array_filter($branch_samples, static function ($sample) { return $sample['contradiction_status'] === 'possible_conflict'; })),
        ];
    }
    usort($branch_profiles, static function ($left, $right) {
        return $left['coverage'] <=> $right['coverage'] ?: $left['priority'] <=> $right['priority'];
    });
    $eligible = count(array_filter($sample_profiles, static function ($sample) { return $sample['training_eligibility'] === 'eligible'; }));
    $review = count(array_filter($sample_profiles, static function ($sample) { return $sample['training_eligibility'] === 'review_required'; }));
    $active_branch_count = count($branch_profiles);
    $covered_branch_count = count(array_filter($branch_profiles, static function ($branch) { return $branch['accepted_sample_count'] > 0; }));
    $average_information_gain = $accepted_variants ? round(array_sum(array_column(array_filter($sample_profiles, static function ($sample) { return $sample['accepted']; }), 'information_gain')) / count($accepted_variants), 1) : 0;
    $average_reliability = $accepted_variants ? round(array_sum(array_column(array_filter($sample_profiles, static function ($sample) { return $sample['accepted']; }), 'reliability')) / count($accepted_variants), 1) : 0;
    $coverage = $active_branch_count ? round(($covered_branch_count / $active_branch_count) * 100, 1) : 0;
    $training_value = round(($average_information_gain * 0.55) + ($average_reliability * 0.25) + ($coverage * 0.2), 1);
    return [
        'schema_version' => '1.0',
        'method' => 'deterministic_heuristic_v1',
        'summary' => [
            'training_value' => $training_value,
            'information_gain' => $average_information_gain,
            'reliability' => $average_reliability,
            'concept_coverage' => $coverage,
            'active_branches' => $active_branch_count,
            'covered_branches' => $covered_branch_count,
            'eligible_samples' => $eligible,
            'review_required_samples' => $review,
            'conflict_samples' => count(array_filter($sample_profiles, static function ($sample) { return $sample['contradiction_status'] === 'possible_conflict'; })),
        ],
        'provenance' => [
            'source_id' => $source_id,
            'knowledge_id' => $knowledge_id,
            'source_type' => sanitize_key($context['source_type'] ?? 'llm_generated'),
            'provider' => sanitize_key($context['provider'] ?? ''),
            'pipeline_post_id' => (int) ($context['pipeline_post_id'] ?? 0),
            'license_status' => sanitize_key($context['license_status'] ?? 'review_required'),
            'temporal_validity' => sanitize_key($context['temporal_validity'] ?? 'unassessed'),
        ],
        'branches' => $branch_profiles,
        'samples' => $sample_profiles,
        'coverage_gaps' => array_slice(array_values(array_filter($branch_profiles, static function ($branch) { return $branch['coverage'] < 80; })), 0, 5),
        'generated_at' => current_time('mysql'),
    ];
}

/** 保存済み回答を指定設定で再評価し、監査履歴と投稿JSONを更新します。 */
function fourier_concept_apply_quality_evaluation($id, $settings, $mark_knowledge_stale = false) {
    $id = (int) $id;
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $data = is_array($data) ? $data : [];
    if (empty($data['branch_answers']['items'])) return new WP_Error('missing_answers', '再評価できる回答データがありません。');
    $concept = get_post_meta($id, '_fourier_pipeline_concept', true) ?: ($data['concept'] ?? '');
    $settings = fourier_concept_resolve_quality_settings(
        $settings['profile'] ?? 'balanced',
        $settings['minimum_score'] ?? null,
        $settings['duplicate_similarity'] ?? null
    );
    $previous_quality = is_array($data['answer_quality'] ?? null) ? $data['answer_quality'] : [];
    $history = get_post_meta($id, '_fourier_concept_quality_history', true);
    $history = is_array($history) ? $history : [];
    if (!empty($previous_quality['summary'])) {
        $history[] = [
            'evaluated_at' => $previous_quality['evaluated_at'] ?? get_post_meta($id, '_fourier_pipeline_updated', true),
            'profile' => $previous_quality['profile'] ?? 'balanced',
            'thresholds' => $previous_quality['thresholds'] ?? [],
            'summary' => $previous_quality['summary'],
        ];
        $history = array_slice($history, -10);
    }

    $quality_questions = $data['branch_questions'] ?? [];
    $quality_answers = $data['branch_answers'] ?? [];
    $branches = is_array($data['concept_map']['branches'] ?? null) ? $data['concept_map']['branches'] : [];
    if ($branches) {
        $disabled_branch_ids = [];
        foreach ($branches as $branch) {
            if (array_key_exists('enabled', $branch) && !$branch['enabled']) $disabled_branch_ids[] = sanitize_key($branch['id'] ?? '');
        }
        if ($disabled_branch_ids) {
            $quality_questions['questions'] = array_values(array_filter($quality_questions['questions'] ?? [], static function ($question) use ($disabled_branch_ids) {
                return !in_array(sanitize_key($question['branch_id'] ?? ''), $disabled_branch_ids, true);
            }));
            $quality_answers['items'] = array_values(array_filter($quality_answers['items'] ?? [], static function ($item) use ($disabled_branch_ids) {
                return !in_array(sanitize_key($item['branch_id'] ?? ''), $disabled_branch_ids, true);
            }));
        }
    }
    $quality = fourier_concept_evaluate_answers(
        $concept,
        $quality_questions,
        $quality_answers,
        $settings['minimum_score'],
        $settings['duplicate_similarity']
    );
    if (!empty($data['curation_decisions']['items'])) {
        $quality = fourier_concept_apply_curation_decisions($quality, $data['curation_decisions']['items']);
    }
    $training_value = fourier_concept_build_training_value(
        $concept,
        $data['concept_map'] ?? [],
        $quality_questions,
        $quality,
        [
            'source_id' => 'pipeline-' . $id,
            'source_type' => 'llm_generated',
            'provider' => get_post_meta($id, '_fourier_pipeline_provider', true),
            'pipeline_post_id' => $id,
        ]
    );
    $quality['profile'] = $settings['profile'];
    $quality['evaluated_at'] = current_time('mysql');
    $quality['evaluation_revision'] = (int) ($previous_quality['evaluation_revision'] ?? 0) + 1;
    $data['answer_quality'] = $quality;
    $data['training_value'] = $training_value;
    if ($mark_knowledge_stale && !empty($data['knowledge']['registered'])) {
        $data['knowledge']['sync_required'] = true;
        if (empty($data['knowledge']['sync_reason'])) $data['knowledge']['sync_reason'] = 'quality_re_evaluated';
    }

    $summary = $quality['summary'];
    update_post_meta($id, '_fourier_concept_quality_profile', $settings['profile']);
    update_post_meta($id, '_fourier_concept_quality_minimum', $settings['minimum_score']);
    update_post_meta($id, '_fourier_concept_quality_duplicate', $settings['duplicate_similarity']);
    update_post_meta($id, '_fourier_concept_quality_score', $summary['average_score']);
    update_post_meta($id, '_fourier_concept_quality_accepted', $summary['accepted_variants']);
    update_post_meta($id, '_fourier_concept_quality_rejected', $summary['rejected_variants']);
    update_post_meta($id, '_fourier_concept_duplicate_rejected', $summary['duplicate_rejected']);
    update_post_meta($id, '_fourier_concept_training_value', $training_value['summary']['training_value']);
    update_post_meta($id, '_fourier_concept_training_eligible', $training_value['summary']['eligible_samples']);
    update_post_meta($id, '_fourier_concept_quality_history', $history);
    update_post_meta($id, '_fourier_pipeline_data', $data);
    update_post_meta($id, '_fourier_pipeline_message', sprintf('品質再評価完了: 採用%d件、除外%d件', $summary['accepted_variants'], $summary['rejected_variants']));
    update_post_meta($id, '_fourier_pipeline_updated', current_time('mysql'));
    wp_update_post(['ID' => $id, 'post_content' => wp_slash(wp_json_encode(['format' => 'concept_distillation', 'data' => $data], JSON_UNESCAPED_UNICODE))]);
    return ['settings' => $settings, 'quality' => $quality, 'knowledge_sync_required' => !empty($data['knowledge']['sync_required'])];
}

function fourier_concept_sanitize_question_angles($value) {
    $angles = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string) $value);
    $angles = array_values(array_filter(array_map(static function ($angle) {
        return sanitize_text_field(mb_substr(trim((string) $angle), 0, 160));
    }, $angles)));
    return array_slice(array_values(array_unique($angles)), 0, 12);
}

/** 概念グラフの枝を追加・更新し、履歴と下流データの失効状態を保存します。 */
function fourier_concept_save_graph_branch($id, $input) {
    $id = (int) $id;
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $data = is_array($data) ? $data : [];
    $map = is_array($data['concept_map'] ?? null) ? $data['concept_map'] : [];
    $branches = is_array($map['branches'] ?? null) ? array_values($map['branches']) : [];
    $mode = sanitize_key($input['mode'] ?? 'update');
    $label = sanitize_text_field(mb_substr(trim((string) ($input['label'] ?? '')), 0, 80));
    if ($label === '') return new WP_Error('missing_label', '枝の名称を入力してください。');
    $scope = sanitize_textarea_field(mb_substr(trim((string) ($input['scope'] ?? '')), 0, 600));
    $priority = max(1, min(3, (int) ($input['priority'] ?? 2)));
    $enabled = filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $question_angles = fourier_concept_sanitize_question_angles($input['question_angles'] ?? []);
    $branch_id = sanitize_key($input['branch_id'] ?? '');
    $before = null;

    if ($mode === 'create') {
        $base = sanitize_title($label);
        if ($base === '' || strpos($base, '%') !== false) $base = 'custom-' . substr(md5($label), 0, 10);
        else $base = sanitize_key($base);
        $branch_id = $base;
        $existing_ids = array_map(static function ($branch) { return sanitize_key($branch['id'] ?? ''); }, $branches);
        $suffix = 2;
        while (in_array($branch_id, $existing_ids, true)) $branch_id = $base . '-' . $suffix++;
        $branches[] = [
            'id' => $branch_id,
            'label' => $label,
            'scope' => $scope,
            'priority' => $priority,
            'question_angles' => $question_angles,
            'enabled' => $enabled,
            'source' => 'manual',
        ];
    } else {
        if ($branch_id === '') return new WP_Error('missing_branch', '編集する枝を選択してください。');
        $found = false;
        foreach ($branches as $index => $branch) {
            if (sanitize_key($branch['id'] ?? '') !== $branch_id) continue;
            $before = $branch;
            $branches[$index] = array_merge($branch, [
                'id' => $branch_id,
                'label' => $label,
                'scope' => $scope,
                'priority' => $priority,
                'question_angles' => $question_angles,
                'enabled' => $enabled,
            ]);
            $found = true;
            break;
        }
        if (!$found) return new WP_Error('unknown_branch', '指定された枝が見つかりません。');
    }

    $map['branches'] = $branches;
    $data['concept_map'] = $map;
    $previous_graph = is_array($data['concept_graph'] ?? null) ? $data['concept_graph'] : [];
    $data['concept_graph'] = [
        'revision' => (int) ($previous_graph['revision'] ?? 0) + 1,
        'edited_at' => current_time('mysql'),
        'changed_branch_id' => $branch_id,
        'change_type' => $mode === 'create' ? 'branch_created' : 'branch_updated',
        'distillation_refresh_required' => true,
    ];
    if (!empty($data['knowledge']['registered'])) {
        $data['knowledge']['sync_required'] = true;
        $data['knowledge']['sync_reason'] = 'concept_graph_edited';
    }

    $history = get_post_meta($id, '_fourier_concept_graph_history', true);
    $history = is_array($history) ? $history : [];
    $history[] = [
        'revision' => $data['concept_graph']['revision'],
        'edited_at' => $data['concept_graph']['edited_at'],
        'change_type' => $data['concept_graph']['change_type'],
        'branch_id' => $branch_id,
        'before' => $before,
        'after' => $branches[array_search($branch_id, array_map(static function ($branch) { return sanitize_key($branch['id'] ?? ''); }, $branches), true)],
    ];
    update_post_meta($id, '_fourier_concept_graph_history', array_slice($history, -20));
    update_post_meta($id, '_fourier_pipeline_data', $data);

    if (!empty($data['branch_answers']['items'])) {
        $evaluation = fourier_concept_apply_quality_evaluation($id, fourier_concept_get_quality_settings($id), true);
        if (is_wp_error($evaluation)) return $evaluation;
        $data = get_post_meta($id, '_fourier_pipeline_data', true);
    }
    update_post_meta($id, '_fourier_pipeline_message', '概念グラフを更新しました。質問・回答の再蒸留が必要です。');
    update_post_meta($id, '_fourier_pipeline_updated', current_time('mysql'));
    wp_update_post(['ID' => $id, 'post_content' => wp_slash(wp_json_encode(['format' => 'concept_distillation', 'data' => $data], JSON_UNESCAPED_UNICODE))]);
    return ['branch_id' => $branch_id, 'concept_graph' => $data['concept_graph'], 'answer_quality' => $data['answer_quality'] ?? [], 'knowledge_sync_required' => !empty($data['knowledge']['sync_required'])];
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
            fourier_pipeline_update($id, 'answer_quality', 'queued', '回答品質と意味的重複を評価します');
        } elseif ($stage === 'answer_quality' || ($stage === 'knowledge' && empty($data['answer_quality']))) {
            $evaluation = fourier_concept_apply_quality_evaluation($id, fourier_concept_get_quality_settings($id));
            if (is_wp_error($evaluation)) throw new Exception($evaluation->get_error_message());
            $data = get_post_meta($id, '_fourier_pipeline_data', true);
            $summary = $evaluation['quality']['summary'];
            fourier_pipeline_update($id, 'knowledge', 'queued', sprintf('品質評価完了: 採用%d件、除外%d件', $summary['accepted_variants'], $summary['rejected_variants']));
        } elseif ($stage === 'knowledge') {
            $owner = (int) get_post_meta($id, '_fourier_pipeline_owner', true);
            $ks_url = get_user_meta($owner, 'fourier_knowledge_server_url', true);
            $ks_token = get_user_meta($owner, 'fourier_knowledge_server_token', true);
            $accepted_count = (int) ($data['answer_quality']['summary']['accepted_variants'] ?? 0);
            if ($ks_url && $accepted_count > 0) {
                $res = wp_safe_remote_post(rtrim($ks_url, '/') . '/items', ['timeout' => 45, 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $ks_token], 'body' => wp_json_encode(['type' => 'concept_distillation', 'concept' => $concept, 'curated_answers' => $data['answer_quality']['curated_items'] ?? [], 'quality_summary' => $data['answer_quality']['summary'] ?? [], 'data' => $data], JSON_UNESCAPED_UNICODE)]);
                if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 400) throw new Exception('Knowledge Serverへの概念データ登録に失敗しました。');
                $data['knowledge'] = ['registered' => true, 'response_code' => wp_remote_retrieve_response_code($res)];
            } elseif ($accepted_count === 0) {
                $data['knowledge'] = ['registered' => false, 'message' => '採用品質を満たす回答がないため登録をスキップ'];
            } else {
                $data['knowledge'] = ['registered' => false, 'message' => 'Knowledge Server未設定のためスキップ'];
            }
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

function fourier_concept_pipeline_ajax_reevaluate() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => '権限がありません。'], 403);
    if (get_post_meta($id, '_fourier_pipeline_kind', true) !== 'concept') wp_send_json_error(['message' => '概念蒸留データではありません。'], 400);
    $settings = fourier_concept_resolve_quality_settings(
        $_POST['profile'] ?? 'balanced',
        $_POST['minimum_score'] ?? null,
        $_POST['duplicate_similarity'] ?? null
    );
    $result = fourier_concept_apply_quality_evaluation($id, $settings, true);
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
    wp_send_json_success([
        'message' => '品質を再評価しました。',
        'settings' => $result['settings'],
        'summary' => $result['quality']['summary'],
        'knowledge_sync_required' => $result['knowledge_sync_required'],
    ]);
}
add_action('wp_ajax_fourier_concept_pipeline_reevaluate', 'fourier_concept_pipeline_ajax_reevaluate');

function fourier_concept_pipeline_ajax_save_graph_branch() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => '権限がありません。'], 403);
    if (get_post_meta($id, '_fourier_pipeline_kind', true) !== 'concept') wp_send_json_error(['message' => '概念蒸留データではありません。'], 400);
    $result = fourier_concept_save_graph_branch($id, [
        'mode' => $_POST['mode'] ?? 'update',
        'branch_id' => $_POST['branch_id'] ?? '',
        'label' => $_POST['label'] ?? '',
        'scope' => $_POST['scope'] ?? '',
        'priority' => $_POST['priority'] ?? 2,
        'question_angles' => $_POST['question_angles'] ?? '',
        'enabled' => $_POST['enabled'] ?? 'false',
    ]);
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
    wp_send_json_success(array_merge(['message' => '概念グラフを保存しました。'], $result));
}
add_action('wp_ajax_fourier_concept_pipeline_save_graph_branch', 'fourier_concept_pipeline_ajax_save_graph_branch');

/** Return judge providers without exposing credentials. */
function fourier_concept_multi_judge_provider_status($user_id = 0) {
    $user_id = (int) ($user_id ?: get_current_user_id());
    $definitions = [
        'openai' => ['label' => 'OpenAI', 'model_key' => 'llm_openai_model', 'default_model' => 'gpt-5.5'],
        'gemini' => ['label' => 'Gemini', 'model_key' => 'llm_gemini_model', 'default_model' => 'gemini-3.1-pro-preview'],
        'ollama' => ['label' => 'Ollama', 'model_key' => 'llm_ollama_model', 'default_model' => 'gemma4:12b-mlx'],
        'custom' => ['label' => 'Custom', 'model_key' => 'llm_custom_model', 'default_model' => 'Server default'],
    ];
    $result = [];
    foreach ($definitions as $provider => $definition) {
        $model = trim((string) get_user_meta($user_id, $definition['model_key'], true));
        $configured = false;
        if ($provider === 'openai') $configured = (bool) get_user_meta($user_id, 'llm_openai_api_key', true);
        if ($provider === 'gemini') $configured = (bool) get_user_meta($user_id, 'llm_gemini_api_key', true);
        if ($provider === 'ollama') $configured = (bool) get_user_meta($user_id, 'llm_ollama_url', true) && $model !== '';
        if ($provider === 'custom') $configured = (bool) get_user_meta($user_id, 'llm_custom_url', true);
        $result[$provider] = [
            'provider' => $provider,
            'label' => $definition['label'],
            'model' => $model !== '' ? $model : $definition['default_model'],
            'configured' => $configured,
        ];
    }
    return $result;
}

/** Build stable, blind answer candidates for questions having at least two variants. */
function fourier_concept_multi_judge_candidates($data, $limit = 3) {
    $limit = max(1, min(10, (int) $limit));
    $question_lookup = [];
    foreach (($data['branch_questions']['questions'] ?? []) as $question) {
        $question_id = sanitize_key($question['question_id'] ?? '');
        if ($question_id !== '') $question_lookup[$question_id] = $question;
    }
    $result = [];
    foreach (($data['branch_answers']['items'] ?? []) as $item_index => $item) {
        $question_id = sanitize_key($item['question_id'] ?? ('question-' . ($item_index + 1)));
        $variants = [];
        foreach (($item['answer_variants'] ?? []) as $variant_index => $variant) {
            $answer = trim((string) ($variant['answer'] ?? ''));
            if ($answer === '') continue;
            $variants[] = [
                'variant_id' => $question_id . '-v' . ($variant_index + 1),
                'label' => chr(65 + min(25, $variant_index)),
                'answer' => $answer,
            ];
        }
        if (count($variants) < 2) continue;
        $question = $question_lookup[$question_id] ?? [];
        $result[] = [
            'question_id' => $question_id,
            'branch_id' => sanitize_key($item['branch_id'] ?? ($question['branch_id'] ?? '')),
            'question' => trim((string) ($question['question'] ?? $question_id)),
            'expected_answer_points' => array_values(array_map('strval', $question['expected_answer_points'] ?? [])),
            'variants' => $variants,
        ];
        if (count($result) >= $limit) break;
    }
    return $result;
}

function fourier_concept_multi_judge_prompt($concept, $candidates) {
    $system = 'You are an impartial dataset answer judge. Evaluate only the supplied candidates. Do not reveal chain-of-thought. Return valid JSON only. Use concise evidence-based reasons. Scores must total 0-100 using accuracy 25, relevance 20, completeness 20, clarity 15, uncertainty_handling 10, usefulness 10.';
    $schema = [
        'judgments' => [[
            'question_id' => 'question-id',
            'ranking' => ['variant-id-best-first'],
            'scores' => [[
                'variant_id' => 'variant-id', 'accuracy' => 0, 'relevance' => 0,
                'completeness' => 0, 'clarity' => 0, 'uncertainty_handling' => 0,
                'usefulness' => 0, 'total' => 0, 'reason' => 'brief reason',
            ]],
            'winner_variant_id' => 'variant-id', 'confidence' => 0.0,
            'dissent_or_risk' => 'brief caveat',
        ]],
        'overall_notes' => 'brief notes',
    ];
    $user = "Concept: {$concept}\n\nJudge every question independently. Candidate labels are blind and carry no quality meaning. Use only listed question_id and variant_id values.\n\nINPUT:\n";
    $user .= wp_json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $user .= "\n\nREQUIRED JSON SHAPE:\n" . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return [$system, $user];
}

/** Normalize one provider response and reject unknown IDs. */
function fourier_concept_multi_judge_normalize($raw, $candidates) {
    $known = [];
    foreach ($candidates as $candidate) {
        $question_id = $candidate['question_id'];
        $known[$question_id] = [];
        foreach ($candidate['variants'] as $variant) $known[$question_id][$variant['variant_id']] = true;
    }
    $normalized = [];
    foreach (($raw['judgments'] ?? []) as $judgment) {
        $question_id = sanitize_key($judgment['question_id'] ?? '');
        if (!isset($known[$question_id])) continue;
        $scores = [];
        foreach (($judgment['scores'] ?? []) as $score) {
            $variant_id = sanitize_key($score['variant_id'] ?? '');
            if (!isset($known[$question_id][$variant_id])) continue;
            $parts = [];
            foreach (['accuracy' => 25, 'relevance' => 20, 'completeness' => 20, 'clarity' => 15, 'uncertainty_handling' => 10, 'usefulness' => 10] as $key => $maximum) {
                $parts[$key] = max(0, min($maximum, (float) ($score[$key] ?? 0)));
            }
            $total = isset($score['total']) ? max(0, min(100, (float) $score['total'])) : array_sum($parts);
            $scores[$variant_id] = array_merge(['variant_id' => $variant_id], $parts, [
                'total' => round($total, 1),
                'reason' => sanitize_textarea_field($score['reason'] ?? ''),
            ]);
        }
        $ranking = [];
        foreach (($judgment['ranking'] ?? []) as $variant_id) {
            $variant_id = sanitize_key($variant_id);
            if (isset($known[$question_id][$variant_id]) && !in_array($variant_id, $ranking, true)) $ranking[] = $variant_id;
        }
        if (!$ranking && $scores) {
            uasort($scores, static function ($a, $b) { return $b['total'] <=> $a['total']; });
            $ranking = array_keys($scores);
        }
        foreach (array_keys($known[$question_id]) as $variant_id) if (!in_array($variant_id, $ranking, true)) $ranking[] = $variant_id;
        $winner = sanitize_key($judgment['winner_variant_id'] ?? '');
        if (!isset($known[$question_id][$winner])) $winner = $ranking[0] ?? '';
        $normalized[] = [
            'question_id' => $question_id,
            'ranking' => $ranking,
            'scores' => array_values($scores),
            'winner_variant_id' => $winner,
            'confidence' => round(max(0, min(1, (float) ($judgment['confidence'] ?? 0))), 2),
            'dissent_or_risk' => sanitize_textarea_field($judgment['dissent_or_risk'] ?? ''),
        ];
    }
    return ['judgments' => $normalized, 'overall_notes' => sanitize_textarea_field($raw['overall_notes'] ?? '')];
}

function fourier_concept_multi_judge_normalize_weights($weights, $providers = []) {
    $normalized = [];
    $providers = $providers ?: array_keys((array) $weights);
    foreach ($providers as $provider) {
        $provider = sanitize_key($provider);
        if ($provider === '') continue;
        $normalized[$provider] = round(max(0.25, min(2.0, (float) ($weights[$provider] ?? 1))), 2);
    }
    return $normalized;
}

/** Aggregate model rankings with weighted Borda points and rubric scores. */
function fourier_concept_multi_judge_aggregate($results, $candidates, $weights = []) {
    $weights = fourier_concept_multi_judge_normalize_weights($weights, array_keys((array) $results));
    $items = [];
    foreach ($candidates as $candidate) {
        $question_id = $candidate['question_id'];
        $variant_lookup = [];
        foreach ($candidate['variants'] as $variant) $variant_lookup[$variant['variant_id']] = $variant;
        $stats = [];
        foreach ($variant_lookup as $variant_id => $variant) $stats[$variant_id] = ['variant_id' => $variant_id, 'answer' => $variant['answer'], 'borda' => 0, 'score_sum' => 0, 'score_weight' => 0, 'wins' => 0, 'weighted_wins' => 0, 'judge_reasons' => []];
        $judge_votes = [];
        foreach ($results as $provider => $result) {
            $provider_weight = $weights[$provider] ?? 1;
            foreach (($result['judgments'] ?? []) as $judgment) {
                if (($judgment['question_id'] ?? '') !== $question_id) continue;
                $ranking = array_values(array_filter($judgment['ranking'] ?? [], static function ($id) use ($variant_lookup) { return isset($variant_lookup[$id]); }));
                $count = count($ranking);
                foreach ($ranking as $rank => $variant_id) $stats[$variant_id]['borda'] += max(0, $count - $rank - 1) * $provider_weight;
                $winner = $judgment['winner_variant_id'] ?? ($ranking[0] ?? '');
                if (isset($stats[$winner])) { $stats[$winner]['wins']++; $stats[$winner]['weighted_wins'] += $provider_weight; $judge_votes[$provider] = $winner; }
                foreach (($judgment['scores'] ?? []) as $score) {
                    $variant_id = $score['variant_id'] ?? '';
                    if (!isset($stats[$variant_id])) continue;
                    $stats[$variant_id]['score_sum'] += (float) ($score['total'] ?? 0) * $provider_weight;
                    $stats[$variant_id]['score_weight'] += $provider_weight;
                    $stats[$variant_id]['judge_reasons'][] = ['provider' => $provider, 'weight' => $provider_weight, 'reason' => $score['reason'] ?? ''];
                }
            }
        }
        $judge_count = count($judge_votes);
        $total_weight = 0;
        foreach (array_keys($judge_votes) as $provider) $total_weight += $weights[$provider] ?? 1;
        $maximum_borda = max(1, $total_weight * max(1, count($variant_lookup) - 1));
        foreach ($stats as &$stat) {
            $stat['average_score'] = $stat['score_weight'] ? round($stat['score_sum'] / $stat['score_weight'], 1) : 0;
            $stat['consensus_score'] = round(($stat['average_score'] * 0.7) + (($stat['borda'] / $maximum_borda) * 30), 1);
            $stat['borda'] = round($stat['borda'], 2);
            $stat['weighted_wins'] = round($stat['weighted_wins'], 2);
            unset($stat['score_sum'], $stat['score_weight']);
        }
        unset($stat);
        usort($stats, static function ($a, $b) { return $b['consensus_score'] <=> $a['consensus_score'] ?: $b['weighted_wins'] <=> $a['weighted_wins']; });
        $winner = $stats[0]['variant_id'] ?? '';
        $winner_votes = isset($stats[0]) ? (int) $stats[0]['wins'] : 0;
        $weighted_winner_votes = isset($stats[0]) ? (float) $stats[0]['weighted_wins'] : 0;
        $agreement = $judge_count > 0 && $winner_votes === $judge_count ? 'unanimous' : ($total_weight > 0 && $weighted_winner_votes > $total_weight / 2 ? 'majority' : 'split');
        $items[] = [
            'question_id' => $question_id, 'question' => $candidate['question'],
            'judge_count' => $judge_count, 'winner_variant_id' => $winner,
            'winner_votes' => $winner_votes, 'weighted_winner_votes' => round($weighted_winner_votes, 2),
            'vote_share' => $total_weight ? round($weighted_winner_votes / $total_weight, 2) : 0,
            'agreement' => $agreement, 'ranking' => $stats, 'judge_votes' => $judge_votes,
        ];
    }
    $summary = ['question_count' => count($items), 'unanimous_count' => 0, 'majority_count' => 0, 'split_count' => 0];
    foreach ($items as $item) $summary[$item['agreement'] . '_count']++;
    return ['schema_version' => '1.1', 'weights' => $weights, 'summary' => $summary, 'items' => $items];
}

function fourier_concept_multi_judge_call_provider($provider, $concept, $candidates, $post_id) {
    $mock = apply_filters('fourier_concept_multi_judge_provider_result', null, $provider, $concept, $candidates, $post_id);
    if (is_array($mock)) return fourier_concept_multi_judge_normalize($mock, $candidates);
    [$system, $user] = fourier_concept_multi_judge_prompt($concept, $candidates);
    $raw = llm_api_call_raw($provider, $system, $user);
    $parsed = _parse_json_from_llm_response($raw);
    if (!is_array($parsed)) throw new Exception('LLMの審査結果をJSONとして解釈できませんでした。');
    return fourier_concept_multi_judge_normalize($parsed, $candidates);
}

function fourier_concept_multi_judge_start($id, $providers, $max_questions = 3) {
    $id = (int) $id;
    $post = get_post($id);
    if (!$post || get_post_meta($id, '_fourier_pipeline_kind', true) !== 'concept') return new WP_Error('invalid_concept', '概念蒸留データではありません。');
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $data = is_array($data) ? $data : [];
    $candidates = fourier_concept_multi_judge_candidates($data, $max_questions);
    if (!$candidates) return new WP_Error('missing_candidates', '比較できる複数回答を持つ質問がありません。');
    $statuses = fourier_concept_multi_judge_provider_status($post->post_author);
    $providers = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $providers), static function ($provider) use ($statuses) { return !empty($statuses[$provider]['configured']); })));
    if (count($providers) < 2) return new WP_Error('judges_required', '設定済みのLLMを2つ以上選択してください。');
    $previous = is_array($data['multi_judge'] ?? null) ? $data['multi_judge'] : [];
    if (in_array($previous['status'] ?? '', ['queued', 'running'], true)) return new WP_Error('already_running', '複数LLM審査はすでに実行中です。');
    if (!empty($previous['run_id'])) {
        $history = get_post_meta($id, '_fourier_concept_multi_judge_history', true);
        $history = is_array($history) ? $history : [];
        $history[] = $previous;
        update_post_meta($id, '_fourier_concept_multi_judge_history', array_slice($history, -10));
    }
    $run_id = wp_generate_uuid4();
    $stored_weights = get_post_meta($id, '_fourier_concept_judge_weights', true);
    $stored_weights = fourier_concept_multi_judge_normalize_weights(is_array($stored_weights) ? $stored_weights : [], $providers);
    $data['multi_judge'] = [
        'schema_version' => '1.0', 'run_id' => $run_id, 'status' => 'queued',
        'providers' => $providers, 'max_questions' => max(1, min(10, (int) $max_questions)),
        'queued_at' => current_time('mysql'), 'current_provider' => '',
        'weights' => $stored_weights, 'completed_providers' => [], 'results' => [], 'errors' => [], 'consensus' => [],
    ];
    update_post_meta($id, '_fourier_pipeline_data', $data);
    wp_schedule_single_event(time() + 1, 'fourier_concept_multi_judge_worker', [$id, $run_id]);
    return $data['multi_judge'];
}

function fourier_concept_multi_judge_worker($id, $run_id) {
    $id = (int) $id;
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $job = is_array($data['multi_judge'] ?? null) ? $data['multi_judge'] : [];
    if (($job['run_id'] ?? '') !== $run_id || !in_array($job['status'] ?? '', ['queued', 'running'], true)) return;
    $remaining = array_values(array_diff($job['providers'] ?? [], array_merge($job['completed_providers'] ?? [], array_keys($job['errors'] ?? []))));
    if ($remaining) {
        $provider = $remaining[0];
        $job['status'] = 'running';
        $job['current_provider'] = $provider;
        $data['multi_judge'] = $job;
        update_post_meta($id, '_fourier_pipeline_data', $data);
        $post = get_post($id);
        $previous_user = get_current_user_id();
        if ($post) wp_set_current_user((int) $post->post_author);
        try {
            $candidates = fourier_concept_multi_judge_candidates($data, $job['max_questions'] ?? 3);
            $concept = get_post_meta($id, '_fourier_pipeline_concept', true) ?: ($data['concept'] ?? '');
            $result = fourier_concept_multi_judge_call_provider($provider, $concept, $candidates, $id);
            if (empty($result['judgments'])) throw new Exception('有効な質問評価が返されませんでした。');
            $status = fourier_concept_multi_judge_provider_status((int) ($post->post_author ?? 0));
            $job['results'][$provider] = array_merge($result, ['provider' => $provider, 'model' => $status[$provider]['model'] ?? '', 'completed_at' => current_time('mysql')]);
            $job['completed_providers'][] = $provider;
        } catch (Throwable $error) {
            $job['errors'][$provider] = ['message' => sanitize_text_field($error->getMessage()), 'failed_at' => current_time('mysql')];
        }
        wp_set_current_user($previous_user);
        $job['current_provider'] = '';
        $data = get_post_meta($id, '_fourier_pipeline_data', true);
        if (($data['multi_judge']['run_id'] ?? '') !== $run_id) return;
        $data['multi_judge'] = $job;
        update_post_meta($id, '_fourier_pipeline_data', $data);
        $remaining = array_values(array_diff($job['providers'] ?? [], array_merge($job['completed_providers'] ?? [], array_keys($job['errors'] ?? []))));
        if ($remaining) { wp_schedule_single_event(time() + 1, 'fourier_concept_multi_judge_worker', [$id, $run_id]); return; }
    }
    $candidates = fourier_concept_multi_judge_candidates($data, $job['max_questions'] ?? 3);
    $job['consensus'] = fourier_concept_multi_judge_aggregate($job['results'] ?? [], $candidates, $job['weights'] ?? []);
    $valid_count = count($job['results'] ?? []);
    $job['status'] = $valid_count >= 2 ? 'completed' : ($valid_count === 1 ? 'partial' : 'error');
    $job['completed_at'] = current_time('mysql');
    $job['current_provider'] = '';
    $data['multi_judge'] = $job;
    if (!empty($data['knowledge']['registered'])) {
        $data['knowledge']['sync_required'] = true;
        $data['knowledge']['sync_reason'] = 'multi_llm_consensus_updated';
    }
    update_post_meta($id, '_fourier_pipeline_data', $data);
    update_post_meta($id, '_fourier_pipeline_updated', current_time('mysql'));
    wp_update_post(['ID' => $id, 'post_content' => wp_slash(wp_json_encode(['format' => 'concept_distillation', 'data' => $data], JSON_UNESCAPED_UNICODE))]);
}
add_action('fourier_concept_multi_judge_worker', 'fourier_concept_multi_judge_worker', 10, 2);

function fourier_concept_multi_judge_ajax_start() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => '権限がありません。'], 403);
    $providers = isset($_POST['providers']) ? explode(',', sanitize_text_field(wp_unslash($_POST['providers']))) : [];
    $result = fourier_concept_multi_judge_start($id, $providers, absint($_POST['max_questions'] ?? 3));
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
    wp_send_json_success(['message' => '複数LLM審査を開始しました。', 'job' => $result]);
}
add_action('wp_ajax_fourier_concept_multi_judge_start', 'fourier_concept_multi_judge_ajax_start');

function fourier_concept_multi_judge_ajax_status() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => '権限がありません。'], 403);
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $job = is_array($data['multi_judge'] ?? null) ? $data['multi_judge'] : [];
    wp_send_json_success(['job' => $job]);
}
add_action('wp_ajax_fourier_concept_multi_judge_status', 'fourier_concept_multi_judge_ajax_status');

/** Summarize scoring behavior across the current and previous judging runs. */
function fourier_concept_multi_judge_trends($current, $history = []) {
    $runs = array_values(array_filter(array_merge((array) $history, [$current]), static function ($run) { return !empty($run['results']); }));
    $providers = [];
    $run_summaries = [];
    foreach ($runs as $run) {
        $winner_lookup = [];
        foreach (($run['consensus']['items'] ?? []) as $item) $winner_lookup[$item['question_id'] ?? ''] = $item['winner_variant_id'] ?? '';
        $run_summary = [
            'run_id' => $run['run_id'] ?? '', 'completed_at' => $run['completed_at'] ?? '',
            'provider_count' => count($run['results'] ?? []),
            'question_count' => (int) ($run['consensus']['summary']['question_count'] ?? 0),
            'unanimous_count' => (int) ($run['consensus']['summary']['unanimous_count'] ?? 0),
            'split_count' => (int) ($run['consensus']['summary']['split_count'] ?? 0),
        ];
        $run_summaries[] = $run_summary;
        foreach (($run['results'] ?? []) as $provider => $result) {
            if (!isset($providers[$provider])) $providers[$provider] = ['provider' => $provider, 'model' => $result['model'] ?? '', 'run_count' => 0, 'judgment_count' => 0, 'score_sum' => 0, 'score_count' => 0, 'confidence_sum' => 0, 'agreement_count' => 0, 'score_by_run' => []];
            $providers[$provider]['run_count']++;
            $run_score_sum = 0;
            $run_score_count = 0;
            foreach (($result['judgments'] ?? []) as $judgment) {
                $providers[$provider]['judgment_count']++;
                $providers[$provider]['confidence_sum'] += (float) ($judgment['confidence'] ?? 0);
                $question_id = $judgment['question_id'] ?? '';
                if (($judgment['winner_variant_id'] ?? '') !== '' && ($judgment['winner_variant_id'] ?? '') === ($winner_lookup[$question_id] ?? '')) $providers[$provider]['agreement_count']++;
                foreach (($judgment['scores'] ?? []) as $score) {
                    $value = max(0, min(100, (float) ($score['total'] ?? 0)));
                    $providers[$provider]['score_sum'] += $value;
                    $providers[$provider]['score_count']++;
                    $run_score_sum += $value;
                    $run_score_count++;
                }
            }
            if ($run_score_count) $providers[$provider]['score_by_run'][] = round($run_score_sum / $run_score_count, 1);
        }
    }
    foreach ($providers as &$provider) {
        $provider['average_score'] = $provider['score_count'] ? round($provider['score_sum'] / $provider['score_count'], 1) : 0;
        $provider['average_confidence'] = $provider['judgment_count'] ? round(($provider['confidence_sum'] / $provider['judgment_count']) * 100, 1) : 0;
        $provider['consensus_agreement'] = $provider['judgment_count'] ? round(($provider['agreement_count'] / $provider['judgment_count']) * 100, 1) : 0;
        $scores = $provider['score_by_run'];
        $provider['score_change'] = count($scores) > 1 ? round(end($scores) - reset($scores), 1) : 0;
        unset($provider['score_sum'], $provider['score_count'], $provider['confidence_sum'], $provider['agreement_count']);
    }
    unset($provider);
    return ['run_count' => count($runs), 'providers' => array_values($providers), 'runs' => array_slice(array_reverse($run_summaries), 0, 10)];
}

function fourier_concept_multi_judge_save_weights($id, $weights, $user_id = 0) {
    $id = (int) $id;
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $data = is_array($data) ? $data : [];
    $job = is_array($data['multi_judge'] ?? null) ? $data['multi_judge'] : [];
    if (empty($job['results'])) return new WP_Error('missing_results', '再計算できる審査結果がありません。');
    if (!empty($job['stale'])) return new WP_Error('stale_results', '回答が変更されています。重み変更の前に複数LLM審査を再実行してください。');
    $weights = fourier_concept_multi_judge_normalize_weights($weights, array_keys($job['results']));
    $candidates = fourier_concept_multi_judge_candidates($data, $job['max_questions'] ?? 3);
    $job['weights'] = $weights;
    $job['consensus'] = fourier_concept_multi_judge_aggregate($job['results'], $candidates, $weights);
    $job['weights_updated_at'] = current_time('mysql');
    $job['weights_updated_by'] = (int) ($user_id ?: get_current_user_id());
    $data['multi_judge'] = $job;
    if (!empty($data['knowledge']['registered'])) {
        $data['knowledge']['sync_required'] = true;
        $data['knowledge']['sync_reason'] = 'judge_weights_updated';
    }
    update_post_meta($id, '_fourier_concept_judge_weights', $weights);
    update_post_meta($id, '_fourier_pipeline_data', $data);
    update_post_meta($id, '_fourier_pipeline_message', 'モデル重みを反映して合議結果を再計算しました。');
    update_post_meta($id, '_fourier_pipeline_updated', current_time('mysql'));
    wp_update_post(['ID' => $id, 'post_content' => wp_slash(wp_json_encode(['format' => 'concept_distillation', 'data' => $data], JSON_UNESCAPED_UNICODE))]);
    return ['weights' => $weights, 'consensus' => $job['consensus']];
}

function fourier_concept_multi_judge_ajax_save_weights() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => '権限がありません。'], 403);
    $decoded = json_decode(wp_unslash($_POST['weights'] ?? ''), true);
    $result = fourier_concept_multi_judge_save_weights($id, is_array($decoded) ? $decoded : [], get_current_user_id());
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
    wp_send_json_success(array_merge(['message' => 'モデル重みを保存し、合議結果を再計算しました。'], $result));
}
add_action('wp_ajax_fourier_concept_multi_judge_save_weights', 'fourier_concept_multi_judge_ajax_save_weights');

/** Keep explicitly adopted consensus answers preferred across later local re-evaluations. */
function fourier_concept_apply_curation_decisions($quality, $decisions) {
    $decisions = is_array($decisions) ? $decisions : [];
    if (!is_array($quality['items'] ?? null)) $quality['items'] = [];
    foreach ($quality['items'] as &$item) {
        $question_id = sanitize_key($item['question_id'] ?? '');
        $selected_id = sanitize_key($decisions[$question_id]['variant_id'] ?? '');
        if ($selected_id === '') continue;
        $selected = null;
        foreach (['accepted_variants', 'rejected_variants'] as $bucket) {
            foreach (($item[$bucket] ?? []) as $index => $variant) {
                if (($variant['variant_id'] ?? '') !== $selected_id) continue;
                $selected = $variant;
                array_splice($item[$bucket], $index, 1);
                break 2;
            }
        }
        if (!$selected) continue;
        $selected['review_status'] = 'accepted_consensus';
        $selected['consensus_preferred'] = true;
        $selected['consensus_adopted_at'] = $decisions[$question_id]['adopted_at'] ?? '';
        $selected['quality_reasons'] = array_values(array_unique(array_merge($selected['quality_reasons'] ?? [], ['複数LLMの合議結果をレビューで優先回答として採用しました。'])));
        array_unshift($item['accepted_variants'], $selected);
    }
    unset($item);
    $accepted = 0;
    $rejected = 0;
    $duplicates = 0;
    $curated = [];
    foreach (($quality['items'] ?? []) as $item) {
        $accepted += count($item['accepted_variants'] ?? []);
        $rejected += count($item['rejected_variants'] ?? []);
        foreach (($item['rejected_variants'] ?? []) as $variant) if (($variant['review_status'] ?? '') === 'rejected_duplicate') $duplicates++;
        if (!empty($item['accepted_variants'])) $curated[] = [
            'question_id' => $item['question_id'], 'branch_id' => $item['branch_id'] ?? '',
            'question' => $item['question'] ?? '', 'preferred_variant_id' => $decisions[$item['question_id']]['variant_id'] ?? '',
            'answer_variants' => $item['accepted_variants'],
        ];
    }
    $quality['summary']['accepted_variants'] = $accepted;
    $quality['summary']['rejected_variants'] = $rejected;
    $quality['summary']['duplicate_rejected'] = $duplicates;
    $quality['summary']['low_quality_rejected'] = max(0, $rejected - $duplicates);
    $quality['curated_items'] = $curated;
    return $quality;
}

function fourier_concept_adopt_consensus($id, $selections, $user_id = 0) {
    $id = (int) $id;
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $data = is_array($data) ? $data : [];
    $consensus_items = $data['multi_judge']['consensus']['items'] ?? [];
    if (!$consensus_items) return new WP_Error('missing_consensus', '採用できる合議結果がありません。');
    $allowed = [];
    foreach ($consensus_items as $item) {
        $question_id = sanitize_key($item['question_id'] ?? '');
        foreach (($item['ranking'] ?? []) as $variant) $allowed[$question_id][sanitize_key($variant['variant_id'] ?? '')] = true;
    }
    $valid = [];
    foreach ((array) $selections as $question_id => $variant_id) {
        $question_id = sanitize_key($question_id);
        $variant_id = sanitize_key($variant_id);
        if ($question_id !== '' && $variant_id !== '' && !empty($allowed[$question_id][$variant_id])) $valid[$question_id] = $variant_id;
    }
    if (!$valid) return new WP_Error('invalid_selection', '採用する回答を1件以上選択してください。');
    $before = $data['curation_decisions']['items'] ?? [];
    $items = is_array($before) ? $before : [];
    $adopted_at = current_time('mysql');
    foreach ($valid as $question_id => $variant_id) $items[$question_id] = [
        'variant_id' => $variant_id, 'source_run_id' => $data['multi_judge']['run_id'] ?? '',
        'adopted_at' => $adopted_at, 'adopted_by' => (int) ($user_id ?: get_current_user_id()),
    ];
    $data['curation_decisions'] = ['schema_version' => '1.0', 'updated_at' => $adopted_at, 'items' => $items];
    $data['answer_quality'] = fourier_concept_apply_curation_decisions($data['answer_quality'] ?? [], $items);
    $concept = get_post_meta($id, '_fourier_pipeline_concept', true) ?: ($data['concept'] ?? '');
    $data['training_value'] = fourier_concept_build_training_value(
        $concept,
        $data['concept_map'] ?? [],
        $data['branch_questions'] ?? [],
        $data['answer_quality'],
        ['source_id' => 'pipeline-' . $id, 'source_type' => 'llm_generated', 'provider' => get_post_meta($id, '_fourier_pipeline_provider', true), 'pipeline_post_id' => $id]
    );
    update_post_meta($id, '_fourier_concept_training_value', $data['training_value']['summary']['training_value']);
    update_post_meta($id, '_fourier_concept_training_eligible', $data['training_value']['summary']['eligible_samples']);
    if (!empty($data['knowledge']['registered'])) {
        $data['knowledge']['sync_required'] = true;
        $data['knowledge']['sync_reason'] = 'consensus_answers_adopted';
    }
    $history = get_post_meta($id, '_fourier_concept_curation_history', true);
    $history = is_array($history) ? $history : [];
    $history[] = ['changed_at' => $adopted_at, 'changed_by' => (int) ($user_id ?: get_current_user_id()), 'before' => $before, 'after' => $items, 'selected_question_ids' => array_keys($valid)];
    update_post_meta($id, '_fourier_concept_curation_history', array_slice($history, -20));
    update_post_meta($id, '_fourier_pipeline_data', $data);
    update_post_meta($id, '_fourier_pipeline_message', sprintf('合議推奨回答を%d件採用しました。', count($valid)));
    update_post_meta($id, '_fourier_pipeline_updated', current_time('mysql'));
    wp_update_post(['ID' => $id, 'post_content' => wp_slash(wp_json_encode(['format' => 'concept_distillation', 'data' => $data], JSON_UNESCAPED_UNICODE))]);
    return ['adopted_count' => count($valid), 'items' => $items, 'knowledge_sync_required' => !empty($data['knowledge']['sync_required'])];
}

function fourier_concept_adopt_consensus_ajax() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => '権限がありません。'], 403);
    $decoded = json_decode(wp_unslash($_POST['selections'] ?? ''), true);
    $result = fourier_concept_adopt_consensus($id, is_array($decoded) ? $decoded : [], get_current_user_id());
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
    wp_send_json_success(array_merge(['message' => '合議推奨回答を採用しました。'], $result));
}
add_action('wp_ajax_fourier_concept_adopt_consensus', 'fourier_concept_adopt_consensus_ajax');

function fourier_concept_selective_redistill_start($id, $question_ids, $provider) {
    $id = (int) $id;
    $post = get_post($id);
    if (!$post || get_post_meta($id, '_fourier_pipeline_kind', true) !== 'concept') return new WP_Error('invalid_concept', '概念蒸留データではありません。');
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $data = is_array($data) ? $data : [];
    $existing_job = $data['selective_redistillation'] ?? [];
    if (in_array($existing_job['status'] ?? '', ['queued', 'running'], true)) return new WP_Error('already_running', '選択的な再蒸留はすでに実行中です。');
    $known = [];
    foreach (($data['branch_questions']['questions'] ?? []) as $question) $known[sanitize_key($question['question_id'] ?? '')] = true;
    $question_ids = array_slice(array_values(array_unique(array_filter(array_map('sanitize_key', (array) $question_ids), static function ($question_id) use ($known) { return !empty($known[$question_id]); }))), 0, 10);
    if (!$question_ids) return new WP_Error('missing_questions', '再蒸留する質問を1件以上選択してください。');
    $provider = sanitize_key($provider);
    $statuses = fourier_concept_multi_judge_provider_status((int) $post->post_author);
    if (empty($statuses[$provider]['configured'])) return new WP_Error('provider_unavailable', '設定済みのLLMを選択してください。');
    $run_id = wp_generate_uuid4();
    $data['selective_redistillation'] = [
        'schema_version' => '1.0', 'run_id' => $run_id, 'status' => 'queued',
        'provider' => $provider, 'question_ids' => $question_ids, 'queued_at' => current_time('mysql'),
        'message' => '再蒸留待ち', 'error' => '',
    ];
    update_post_meta($id, '_fourier_pipeline_data', $data);
    wp_schedule_single_event(time() + 1, 'fourier_concept_selective_redistill_worker', [$id, $run_id]);
    return $data['selective_redistillation'];
}

function fourier_concept_selective_redistill_worker($id, $run_id) {
    $id = (int) $id;
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    $job = is_array($data['selective_redistillation'] ?? null) ? $data['selective_redistillation'] : [];
    if (($job['run_id'] ?? '') !== $run_id || !in_array($job['status'] ?? '', ['queued', 'running'], true)) return;
    $job['status'] = 'running';
    $job['started_at'] = current_time('mysql');
    $job['message'] = '選択した質問の回答を生成中';
    $data['selective_redistillation'] = $job;
    update_post_meta($id, '_fourier_pipeline_data', $data);
    $post = get_post($id);
    $previous_user = get_current_user_id();
    if ($post) wp_set_current_user((int) $post->post_author);
    try {
        $selected_questions = array_values(array_filter($data['branch_questions']['questions'] ?? [], static function ($question) use ($job) { return in_array(sanitize_key($question['question_id'] ?? ''), $job['question_ids'], true); }));
        $concept = get_post_meta($id, '_fourier_pipeline_concept', true) ?: ($data['concept'] ?? '');
        $prompt = "概念『{$concept}』について、指定された質問だけ回答候補を再生成してください。各質問に独立した回答を3種類作り、短い直接回答、背景・理由を含む説明、具体例・比較・推論を含む応用回答にしてください。各itemはquestion_id、branch_id、answer_variants（style, answer, confidence, caveats）を持ちます。質問IDは変更せず、出力キーはitemsのみです。\n" . wp_json_encode(['questions' => $selected_questions], JSON_UNESCAPED_UNICODE);
        $mock = apply_filters('fourier_concept_selective_redistill_result', null, $job['provider'], $selected_questions, $id);
        $generated = is_array($mock) ? $mock : fourier_pipeline_json(fourier_pipeline_llm($job['provider'], $prompt, $concept));
        $replacement = [];
        foreach (($generated['items'] ?? []) as $item) {
            $question_id = sanitize_key($item['question_id'] ?? '');
            if (!in_array($question_id, $job['question_ids'], true) || empty($item['answer_variants'])) continue;
            $replacement[$question_id] = $item;
        }
        if (count($replacement) !== count($job['question_ids'])) throw new Exception('選択したすべての質問について有効な回答を生成できませんでした。');
        $old_items = [];
        foreach (($data['branch_answers']['items'] ?? []) as $index => $item) {
            $question_id = sanitize_key($item['question_id'] ?? '');
            if (!isset($replacement[$question_id])) continue;
            $old_items[] = $item;
            $data['branch_answers']['items'][$index] = $replacement[$question_id];
        }
        $history = get_post_meta($id, '_fourier_concept_redistillation_history', true);
        $history = is_array($history) ? $history : [];
        $history[] = ['run_id' => $run_id, 'completed_at' => current_time('mysql'), 'provider' => $job['provider'], 'question_ids' => $job['question_ids'], 'previous_items' => $old_items];
        update_post_meta($id, '_fourier_concept_redistillation_history', array_slice($history, -10));
        foreach ($job['question_ids'] as $question_id) unset($data['curation_decisions']['items'][$question_id]);
        if (!empty($data['multi_judge'])) {
            $data['multi_judge']['stale'] = true;
            $data['multi_judge']['stale_reason'] = 'answers_redistilled';
            $data['multi_judge']['stale_at'] = current_time('mysql');
        }
        $data['selective_redistillation'] = $job;
        update_post_meta($id, '_fourier_pipeline_data', $data);
        $evaluation = fourier_concept_apply_quality_evaluation($id, fourier_concept_get_quality_settings($id), true);
        if (is_wp_error($evaluation)) throw new Exception($evaluation->get_error_message());
        $data = get_post_meta($id, '_fourier_pipeline_data', true);
        $job['status'] = 'completed';
        $job['completed_at'] = current_time('mysql');
        $job['message'] = sprintf('%d件の質問を再蒸留しました。', count($job['question_ids']));
        $data['selective_redistillation'] = $job;
        update_post_meta($id, '_fourier_pipeline_data', $data);
        update_post_meta($id, '_fourier_pipeline_message', $job['message']);
    } catch (Throwable $error) {
        $data = get_post_meta($id, '_fourier_pipeline_data', true);
        $job['status'] = 'error';
        $job['error'] = sanitize_text_field($error->getMessage());
        $job['message'] = '選択的な再蒸留に失敗しました。';
        $data['selective_redistillation'] = $job;
        update_post_meta($id, '_fourier_pipeline_data', $data);
    }
    wp_set_current_user($previous_user);
}
add_action('fourier_concept_selective_redistill_worker', 'fourier_concept_selective_redistill_worker', 10, 2);

function fourier_concept_selective_redistill_ajax_start() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => '権限がありません。'], 403);
    $question_ids = array_filter(explode(',', sanitize_text_field(wp_unslash($_POST['question_ids'] ?? ''))));
    $result = fourier_concept_selective_redistill_start($id, $question_ids, $_POST['provider'] ?? '');
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
    wp_send_json_success(['message' => '選択的な再蒸留を開始しました。', 'job' => $result]);
}
add_action('wp_ajax_fourier_concept_selective_redistill_start', 'fourier_concept_selective_redistill_ajax_start');

function fourier_concept_selective_redistill_ajax_status() {
    check_ajax_referer('learning_data_action', 'nonce');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => '権限がありません。'], 403);
    $data = get_post_meta($id, '_fourier_pipeline_data', true);
    wp_send_json_success(['job' => $data['selective_redistillation'] ?? []]);
}
add_action('wp_ajax_fourier_concept_selective_redistill_status', 'fourier_concept_selective_redistill_ajax_status');
