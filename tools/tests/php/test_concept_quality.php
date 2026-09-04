<?php
/**
 * Concept distillation quality evaluator smoke test.
 * Run from the WordPress app container:
 * php wp-content/themes/AI-data-manager/tools/tests/php/test_concept_quality.php
 */
$wordpress_root = dirname(__DIR__, 6);
require_once $wordpress_root . '/wp-load.php';

$questions = ['questions' => [[
    'question_id' => 'dog-feature-1',
    'branch_id' => 'characteristics',
    'question' => '犬の嗅覚にはどのような特徴がありますか？',
    'expected_answer_points' => ['嗅覚が発達している', '探索や識別に利用する'],
]]];
$answers = ['items' => [[
    'question_id' => 'dog-feature-1',
    'branch_id' => 'characteristics',
    'answer_variants' => [
        [
            'style' => 'explanation',
            'answer' => '犬は発達した嗅覚を持ち、においを手がかりに対象を探索したり個体を識別したりします。能力には個体差や犬種差があります。',
            'confidence' => 0.9,
            'caveats' => ['能力には個体差がある'],
        ],
        [
            'style' => 'duplicate',
            'answer' => '犬は発達した嗅覚を持ち、においを手がかりに対象を探索したり、個体を識別したりします。能力には個体差や犬種差もあります。',
            'confidence' => 0.9,
            'caveats' => ['能力には個体差がある'],
        ],
        ['style' => 'short', 'answer' => 'すごい。', 'confidence' => '', 'caveats' => []],
    ],
]]];

$result = fourier_concept_evaluate_answers('犬', $questions, $answers);
$summary = $result['summary'];
$failures = [];
if ($summary['total_variants'] !== 3) $failures[] = 'total_variants must be 3';
if ($summary['accepted_variants'] !== 1) $failures[] = 'one high-quality answer must be accepted';
if ($summary['duplicate_rejected'] !== 1) $failures[] = 'one exact duplicate must be rejected';
if ($summary['low_quality_rejected'] !== 1) $failures[] = 'one short answer must be rejected';
if (fourier_concept_semantic_similarity('犬は人と暮らす動物です。', '犬は人と暮らす動物です。') !== 1.0) $failures[] = 'exact Japanese text similarity must be 1.0';
$accepted_score = $result['items'][0]['accepted_variants'][0]['quality_score'] ?? 0;
if ($accepted_score < 65) $failures[] = 'a representative useful answer must meet the quality threshold';
if (count($result['curated_items'][0]['answer_variants'] ?? []) !== 1) $failures[] = 'curated_items must contain accepted answers only';
$strict = fourier_concept_resolve_quality_settings('strict');
if ($strict['minimum_score'] !== 75 || $strict['duplicate_similarity'] !== 0.88) $failures[] = 'strict preset must resolve to 75 / 0.88';
$custom = fourier_concept_resolve_quality_settings('custom', 120, 55);
if ($custom['minimum_score'] !== 95 || $custom['duplicate_similarity'] !== 0.6) $failures[] = 'custom settings must be clamped to safe limits';
$angles = fourier_concept_sanitize_question_angles("比較\n比較\n 応用 ");
if ($angles !== ['比較', '応用']) $failures[] = 'question angles must be trimmed and deduplicated';

$training_value = fourier_concept_build_training_value('犬', ['branches' => [
    ['id' => 'characteristics', 'label' => '特徴', 'priority' => 1, 'question_angles' => ['嗅覚'], 'enabled' => true],
    ['id' => 'reasoning', 'label' => '推論', 'priority' => 2, 'question_angles' => ['条件推論'], 'enabled' => true],
]], $questions, $result, ['source_id' => 'pipeline-test', 'provider' => 'openai', 'pipeline_post_id' => 123]);
if (($training_value['summary']['concept_coverage'] ?? 0) !== 50.0) $failures[] = 'one of two active branches must produce 50% concept coverage';
if (empty($training_value['samples'][0]['lineage']['sample_id'])) $failures[] = 'training samples must have stable lineage IDs';
if (($training_value['provenance']['source_id'] ?? '') !== 'pipeline-test') $failures[] = 'training value must preserve source provenance';
if (($training_value['coverage_gaps'][0]['branch_id'] ?? '') !== 'reasoning') $failures[] = 'the empty reasoning branch must be the first coverage gap';
if (!isset($training_value['samples'][0]['information_gain'], $training_value['samples'][0]['training_eligibility'])) $failures[] = 'samples must expose information gain and training eligibility';
if ((fourier_concept_difficulty('reasoning', 'もし条件が変わったならどうなりますか？')['level'] ?? 0) < 5) $failures[] = 'counterfactual questions must receive curriculum level 5 or higher';

$conflict_quality = ['items' => [[
    'question_id' => 'dog-claim-1', 'branch_id' => 'characteristics', 'question' => '犬は哺乳類ですか？',
    'accepted_variants' => [
        ['variant_id' => 'dog-claim-1-v1', 'answer' => '犬は哺乳類です。', 'quality_score' => 90, 'confidence' => 0.9, 'review_status' => 'accepted'],
        ['variant_id' => 'dog-claim-1-v2', 'answer' => '犬は哺乳類ではないです。', 'quality_score' => 85, 'confidence' => 0.8, 'review_status' => 'accepted'],
    ],
    'rejected_variants' => [],
]]];
$conflict_value = fourier_concept_build_training_value('犬', ['branches' => [['id' => 'characteristics', 'label' => '特徴']]], ['questions' => [[
    'question_id' => 'dog-claim-1', 'branch_id' => 'characteristics', 'question' => '犬は哺乳類ですか？',
]]], $conflict_quality);
if (($conflict_value['summary']['conflict_samples'] ?? 0) !== 2) $failures[] = 'opposite-polarity similar claims must be preserved as possible conflicts';
if (($conflict_value['samples'][0]['training_eligibility'] ?? '') !== 'review_required') $failures[] = 'possible conflicts must require human review';

$graph_test_id = wp_insert_post(['post_title' => 'Concept graph smoke test', 'post_status' => 'pending', 'post_type' => 'post']);
if (is_wp_error($graph_test_id) || !$graph_test_id) {
    $failures[] = 'temporary concept graph post could not be created';
} else {
    update_post_meta($graph_test_id, '_fourier_pipeline_kind', 'concept');
    update_post_meta($graph_test_id, '_fourier_pipeline_concept', '犬');
    update_post_meta($graph_test_id, '_fourier_pipeline_data', [
        'concept' => '犬',
        'concept_map' => ['branches' => [[
            'id' => 'characteristics',
            'label' => '特徴',
            'scope' => '身体的特徴',
            'priority' => 1,
            'question_angles' => ['感覚'],
        ]]],
        'branch_questions' => $questions,
        'branch_answers' => $answers,
        'knowledge' => ['registered' => true],
    ]);
    fourier_concept_apply_quality_evaluation($graph_test_id, ['profile' => 'balanced']);
    $initial_training_value = get_post_meta($graph_test_id, '_fourier_pipeline_data', true)['training_value'] ?? [];
    if (empty($initial_training_value['provenance']['knowledge_id'])) $failures[] = 'quality evaluation must persist training value and provenance';
    if (get_post_meta($graph_test_id, '_fourier_concept_training_value', true) === '') $failures[] = 'training value summary must be queryable through post meta';
    $graph_result = fourier_concept_save_graph_branch($graph_test_id, [
        'mode' => 'update',
        'branch_id' => 'characteristics',
        'label' => '身体と感覚',
        'scope' => '身体構造と感覚能力',
        'priority' => 2,
        'question_angles' => "嗅覚\n身体構造",
        'enabled' => false,
    ]);
    $graph_data = get_post_meta($graph_test_id, '_fourier_pipeline_data', true);
    $graph_history = get_post_meta($graph_test_id, '_fourier_concept_graph_history', true);
    if (is_wp_error($graph_result)) $failures[] = 'concept graph branch update must succeed';
    if (($graph_data['concept_map']['branches'][0]['label'] ?? '') !== '身体と感覚') $failures[] = 'concept graph branch label must be updated';
    if (!empty($graph_data['answer_quality']['summary']['accepted_variants'])) $failures[] = 'disabled branch answers must leave the curated set';
    if (empty($graph_data['concept_graph']['distillation_refresh_required'])) $failures[] = 'graph edit must require downstream re-distillation';
    if (empty($graph_data['knowledge']['sync_required'])) $failures[] = 'graph edit must mark registered knowledge as stale';
    if (!is_array($graph_history) || count($graph_history) !== 1) $failures[] = 'graph edit history must be recorded';
    $created_branch = fourier_concept_save_graph_branch($graph_test_id, [
        'mode' => 'create',
        'label' => '推論問題',
        'scope' => '条件から結論を導く',
        'priority' => 3,
        'question_angles' => ['条件推論'],
        'enabled' => true,
    ]);
    if (is_wp_error($created_branch) || !preg_match('/^custom-[a-f0-9]{10}$/', $created_branch['branch_id'] ?? '')) $failures[] = 'Japanese manual branches must receive stable ASCII IDs';
    $graph_history = get_post_meta($graph_test_id, '_fourier_concept_graph_history', true);
    if (!is_array($graph_history) || count($graph_history) !== 2) $failures[] = 'create and update operations must both be recorded';
    wp_delete_post($graph_test_id, true);
}

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n" . wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

echo "PASS\n" . wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
