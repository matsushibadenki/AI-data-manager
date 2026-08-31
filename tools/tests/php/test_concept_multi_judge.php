<?php
/**
 * Multi-LLM concept judgment smoke test. No external API is called.
 * Run from the WordPress app container:
 * php wp-content/themes/AI-data-manager/tools/tests/php/test_concept_multi_judge.php
 */
$wordpress_root = dirname(__DIR__, 6);
require_once $wordpress_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = [];
$questions = ['questions' => [[
    'question_id' => 'dog-sense-1',
    'branch_id' => 'characteristics',
    'question' => '犬の嗅覚にはどのような特徴がありますか？',
    'expected_answer_points' => ['嗅覚が発達している', '探索や識別に使う'],
]]];
$answers = ['items' => [[
    'question_id' => 'dog-sense-1',
    'branch_id' => 'characteristics',
    'answer_variants' => [
        ['answer' => '犬は発達した嗅覚を使い、対象の探索や個体の識別を行います。'],
        ['answer' => '犬の嗅覚は鋭く、においから環境や対象について多くの情報を得ます。'],
        ['answer' => '犬は鼻がよい動物です。'],
    ],
]]];
$data = ['concept' => '犬', 'branch_questions' => $questions, 'branch_answers' => $answers];
$candidates = fourier_concept_multi_judge_candidates($data, 3);
if (count($candidates) !== 1 || count($candidates[0]['variants'] ?? []) !== 3) $failures[] = 'candidate builder must retain three stable variants';

$responses = [
    'openai' => [
        'judgments' => [[
            'question_id' => 'dog-sense-1',
            'ranking' => ['dog-sense-1-v1', 'dog-sense-1-v2', 'dog-sense-1-v3', 'unknown-id'],
            'scores' => [
                ['variant_id' => 'dog-sense-1-v1', 'accuracy' => 30, 'relevance' => 19, 'completeness' => 18, 'clarity' => 14, 'uncertainty_handling' => 9, 'usefulness' => 10, 'total' => 90, 'reason' => '具体的で質問に直接答える。'],
                ['variant_id' => 'dog-sense-1-v2', 'total' => 82, 'reason' => '正確だが用途がやや抽象的。'],
                ['variant_id' => 'dog-sense-1-v3', 'total' => 40, 'reason' => '情報が不足する。'],
                ['variant_id' => 'unknown-id', 'total' => 100, 'reason' => 'invalid'],
            ],
            'winner_variant_id' => 'dog-sense-1-v1', 'confidence' => 1.4, 'dissent_or_risk' => '犬種差への言及はない。',
        ]],
    ],
    'gemini' => [
        'judgments' => [[
            'question_id' => 'dog-sense-1',
            'ranking' => ['dog-sense-1-v2', 'dog-sense-1-v1', 'dog-sense-1-v3'],
            'scores' => [
                ['variant_id' => 'dog-sense-1-v1', 'total' => 80, 'reason' => '用途が明確。'],
                ['variant_id' => 'dog-sense-1-v2', 'total' => 88, 'reason' => '情報取得という広がりがある。'],
                ['variant_id' => 'dog-sense-1-v3', 'total' => 42, 'reason' => '説明が短い。'],
            ],
            'winner_variant_id' => 'dog-sense-1-v2', 'confidence' => 0.8, 'dissent_or_risk' => '具体例が少ない。',
        ]],
    ],
];
$normalized = [];
foreach ($responses as $provider => $response) $normalized[$provider] = fourier_concept_multi_judge_normalize($response, $candidates);
if (($normalized['openai']['judgments'][0]['confidence'] ?? 0) !== 1.0) $failures[] = 'confidence must be clamped to 1.0';
if (count($normalized['openai']['judgments'][0]['scores'] ?? []) !== 3) $failures[] = 'unknown variant scores must be removed';
if ((float) ($normalized['openai']['judgments'][0]['scores'][0]['accuracy'] ?? 0) !== 25.0) $failures[] = 'rubric component scores must be clamped';
$consensus = fourier_concept_multi_judge_aggregate($normalized, $candidates);
if (($consensus['summary']['split_count'] ?? 0) !== 1) $failures[] = 'one-to-one model disagreement must be marked split';
if (($consensus['items'][0]['judge_count'] ?? 0) !== 2) $failures[] = 'consensus must include two judges';
if (count($consensus['items'][0]['ranking'] ?? []) !== 3) $failures[] = 'consensus ranking must retain all known variants';
$weighted_consensus = fourier_concept_multi_judge_aggregate($normalized, $candidates, ['openai' => .5, 'gemini' => 2]);
if (($weighted_consensus['items'][0]['winner_variant_id'] ?? '') !== 'dog-sense-1-v2') $failures[] = 'higher Gemini weight must move its preferred variant to first place';
if (($weighted_consensus['items'][0]['agreement'] ?? '') !== 'majority') $failures[] = 'weighted majority must be reflected in agreement status';
$clamped_weights = fourier_concept_multi_judge_normalize_weights(['openai' => 0, 'gemini' => 9], ['openai', 'gemini']);
if (($clamped_weights['openai'] ?? 0) !== .25 || ($clamped_weights['gemini'] ?? 0) !== 2.0) $failures[] = 'model weights must be clamped to 25-200 percent';

$username = 'concept_judge_test_' . wp_generate_password(8, false, false);
$user_id = wp_create_user($username, wp_generate_password(20), $username . '@example.invalid');
if (is_wp_error($user_id)) {
    $failures[] = 'temporary judgment user could not be created';
} else {
    update_user_meta($user_id, 'llm_openai_api_key', 'mock-only');
    update_user_meta($user_id, 'llm_gemini_api_key', 'mock-only');
    $post_id = wp_insert_post(['post_title' => 'Multi judge smoke test', 'post_status' => 'pending', 'post_type' => 'post', 'post_author' => $user_id]);
    if (is_wp_error($post_id) || !$post_id) {
        $failures[] = 'temporary judgment post could not be created';
    } else {
        update_post_meta($post_id, '_fourier_pipeline_kind', 'concept');
        update_post_meta($post_id, '_fourier_pipeline_concept', '犬');
        update_post_meta($post_id, '_fourier_pipeline_data', $data);
        fourier_concept_apply_quality_evaluation($post_id, ['profile' => 'balanced']);
        $mock_filter = static function ($value, $provider) use ($responses) { return $responses[$provider] ?? $value; };
        add_filter('fourier_concept_multi_judge_provider_result', $mock_filter, 10, 2);
        $job = fourier_concept_multi_judge_start($post_id, ['openai', 'gemini'], 3);
        if (is_wp_error($job)) {
            $failures[] = 'mock judgment job must start';
        } else {
            fourier_concept_multi_judge_worker($post_id, $job['run_id']);
            $after_first = get_post_meta($post_id, '_fourier_pipeline_data', true);
            if (($after_first['multi_judge']['status'] ?? '') !== 'running' || count($after_first['multi_judge']['completed_providers'] ?? []) !== 1) $failures[] = 'worker must persist progress after one provider';
            fourier_concept_multi_judge_worker($post_id, $job['run_id']);
            $finished = get_post_meta($post_id, '_fourier_pipeline_data', true);
            if (($finished['multi_judge']['status'] ?? '') !== 'completed') $failures[] = 'two successful mock providers must complete the job';
            if (count($finished['multi_judge']['results'] ?? []) !== 2) $failures[] = 'both provider results must be saved';
            if (($finished['multi_judge']['consensus']['summary']['split_count'] ?? 0) !== 1) $failures[] = 'worker must persist aggregate consensus';
            $weighted = fourier_concept_multi_judge_save_weights($post_id, ['openai' => .5, 'gemini' => 2], $user_id);
            $after_weights = get_post_meta($post_id, '_fourier_pipeline_data', true);
            if (is_wp_error($weighted) || ($after_weights['multi_judge']['consensus']['items'][0]['winner_variant_id'] ?? '') !== 'dog-sense-1-v2') $failures[] = 'saved model weights must recalculate persisted consensus';
            $saved_weights = get_post_meta($post_id, '_fourier_concept_judge_weights', true);
            if (($saved_weights['gemini'] ?? 0) !== 2.0) $failures[] = 'model weights must persist for future runs';
            $trends = fourier_concept_multi_judge_trends($after_weights['multi_judge'], []);
            if (($trends['run_count'] ?? 0) !== 1 || count($trends['providers'] ?? []) !== 2) $failures[] = 'trend analysis must summarize both providers in the current run';
            if (($trends['providers'][0]['judgment_count'] ?? 0) !== 1) $failures[] = 'trend analysis must count provider judgments';
            $adopted = fourier_concept_adopt_consensus($post_id, ['dog-sense-1' => 'dog-sense-1-v2'], $user_id);
            $after_adoption = get_post_meta($post_id, '_fourier_pipeline_data', true);
            if (is_wp_error($adopted) || ($adopted['adopted_count'] ?? 0) !== 1) $failures[] = 'one selected consensus answer must be adopted';
            if (($after_adoption['answer_quality']['items'][0]['accepted_variants'][0]['variant_id'] ?? '') !== 'dog-sense-1-v2') $failures[] = 'adopted variant must become the preferred accepted answer';
            if (empty($after_adoption['answer_quality']['items'][0]['accepted_variants'][0]['consensus_preferred'])) $failures[] = 'adopted answer must carry the consensus preference marker';
            $curation_history = get_post_meta($post_id, '_fourier_concept_curation_history', true);
            if (count($curation_history ?? []) !== 1) $failures[] = 'consensus adoption must record audit history';
            fourier_concept_apply_quality_evaluation($post_id, ['profile' => 'strict'], true);
            $after_reevaluation = get_post_meta($post_id, '_fourier_pipeline_data', true);
            if (($after_reevaluation['answer_quality']['items'][0]['accepted_variants'][0]['variant_id'] ?? '') !== 'dog-sense-1-v2') $failures[] = 'adopted preference must survive later local quality re-evaluation';

            $redistill_filter = static function ($value) {
                return ['items' => [[
                    'question_id' => 'dog-sense-1', 'branch_id' => 'characteristics',
                    'answer_variants' => [
                        ['style' => 'direct', 'answer' => '再生成された直接回答です。犬は嗅覚を探索と識別に利用します。', 'confidence' => .9, 'caveats' => []],
                        ['style' => 'explanation', 'answer' => '再生成された説明回答です。犬は発達した嗅覚から環境中の情報を得て探索や識別に役立てます。', 'confidence' => .88, 'caveats' => ['個体差があります。']],
                        ['style' => 'application', 'answer' => '再生成された応用回答です。捜索活動などでは、においの識別能力を具体的な探索課題に利用します。', 'confidence' => .82, 'caveats' => []],
                    ],
                ]]];
            };
            add_filter('fourier_concept_selective_redistill_result', $redistill_filter, 10, 1);
            $redistill_job = fourier_concept_selective_redistill_start($post_id, ['dog-sense-1'], 'openai');
            if (is_wp_error($redistill_job)) {
                $failures[] = 'selective re-distillation job must start';
            } else {
                fourier_concept_selective_redistill_worker($post_id, $redistill_job['run_id']);
                $redistilled = get_post_meta($post_id, '_fourier_pipeline_data', true);
                if (($redistilled['selective_redistillation']['status'] ?? '') !== 'completed') $failures[] = 'mock selective re-distillation must complete';
                if (strpos($redistilled['branch_answers']['items'][0]['answer_variants'][0]['answer'] ?? '', '再生成') === false) $failures[] = 'selected question answers must be replaced';
                if (empty($redistilled['multi_judge']['stale'])) $failures[] = 'answer replacement must mark previous multi-LLM judgment stale';
                if (!empty($redistilled['curation_decisions']['items']['dog-sense-1'])) $failures[] = 're-distilled question must clear its previous curation decision';
                $redistill_history = get_post_meta($post_id, '_fourier_concept_redistillation_history', true);
                if (count($redistill_history ?? []) !== 1) $failures[] = 'selective re-distillation must preserve previous answers in history';
                wp_clear_scheduled_hook('fourier_concept_selective_redistill_worker', [$post_id, $redistill_job['run_id']]);
            }
            remove_filter('fourier_concept_selective_redistill_result', $redistill_filter, 10);
            wp_clear_scheduled_hook('fourier_concept_multi_judge_worker', [$post_id, $job['run_id']]);
        }
        remove_filter('fourier_concept_multi_judge_provider_result', $mock_filter, 10);
        wp_delete_post($post_id, true);
    }
    wp_delete_user($user_id);
}

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS\n" . wp_json_encode($consensus['summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
