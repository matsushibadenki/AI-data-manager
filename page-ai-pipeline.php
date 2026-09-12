<?php
/*
 * Template Name: AI Learning Pipeline
 * Template Post Type: page
 */
if (!is_user_logged_in()) {
  auth_redirect();
}

$pipeline_locale = determine_locale();
$pipeline_language = strpos($pipeline_locale, 'zh') === 0 ? 'zh' : (strpos($pipeline_locale, 'en') === 0 ? 'en' : 'ja');
$pipeline_text = static function ($ja, $en, $zh) use ($pipeline_language) {
  return ['ja' => $ja, 'en' => $en, 'zh' => $zh][$pipeline_language];
};
$nonce = wp_create_nonce('learning_data_action');
$pipeline_provider_statuses = fourier_concept_multi_judge_provider_status(get_current_user_id());
$pipeline_default_provider = 'openai';
foreach ($pipeline_provider_statuses as $provider_key => $provider_status) {
  if (!empty($provider_status['configured'])) {
    $pipeline_default_provider = $provider_key;
    break;
  }
}
$pipeline_sources = get_posts([
  'post_type' => 'post',
  'post_status' => ['pending', 'publish'],
  'author' => get_current_user_id(),
  'meta_key' => '_fourier_pipeline_url',
  'posts_per_page' => 30,
  'orderby' => 'modified',
  'order' => 'DESC',
]);
$queue_search = isset($_GET['queue_search']) && is_string($_GET['queue_search']) ? mb_substr(sanitize_text_field(wp_unslash($_GET['queue_search'])), 0, 200) : '';
$queue_status = isset($_GET['queue_status']) && is_string($_GET['queue_status']) ? sanitize_key($_GET['queue_status']) : 'all';
$queue_page = isset($_GET['queue_page']) && is_scalar($_GET['queue_page']) ? max(1, min(100000, absint($_GET['queue_page']))) : 1;
$queue_labels = [
  'all' => $pipeline_text('すべて', 'All', '全部'),
  'queued' => $pipeline_text('実行待ち', 'Queued', '等待执行'),
  'processing' => $pipeline_text('処理中', 'Processing', '处理中'),
  'review' => $pipeline_text('レビュー待ち', 'Awaiting review', '等待审核'),
  'error' => $pipeline_text('エラー', 'Error', '错误'),
  'approved' => $pipeline_text('承認済み', 'Approved', '已批准'),
  'rejected' => $pipeline_text('差し戻し', 'Rejected', '已驳回'),
];
$stage_labels = [
  'scraping' => ['ページ取得', 'Fetch page', '获取页面'], 'extraction' => ['文章抽出', 'Extract text', '提取文本'],
  'summary' => ['要約生成', 'Summarize', '生成摘要'], 'instruction' => ['指示データ生成', 'Generate instructions', '生成指令'],
  'qa' => ['質問・回答生成', 'Generate Q&A', '生成问答'], 'chat' => ['対話生成', 'Generate chat', '生成对话'],
  'tags' => ['タグ生成', 'Generate tags', '生成标签'], 'concepts' => ['概念抽出', 'Extract concepts', '提取概念'],
  'concept_map' => ['概念マップ生成', 'Concept map', '生成概念图'], 'branch_questions' => ['枝ごとの質問生成', 'Branch questions', '生成分支问题'],
  'branch_answers' => ['複数回答生成', 'Answer variants', '生成多种回答'], 'answer_quality' => ['回答品質評価', 'Evaluate quality', '评估回答质量'],
  'knowledge' => ['知識登録', 'Register knowledge', '注册知识'], 'review' => ['レビュー', 'Review', '审核'],
];
if (!isset($queue_labels[$queue_status])) $queue_status = 'all';
$queue_query = fourier_pipeline_queue_query(get_current_user_id(), $queue_search, $queue_status, $queue_page);
if ($queue_page > max(1, $queue_query->max_num_pages)) {
  $queue_page = max(1, $queue_query->max_num_pages);
  $queue_query = fourier_pipeline_queue_query(get_current_user_id(), $queue_search, $queue_status, $queue_page);
}
$items = $queue_query->posts;
$queue_link = static function ($page) use ($queue_search, $queue_status) {
  return add_query_arg(['queue_search' => $queue_search, 'queue_status' => $queue_status, 'queue_page' => $page], get_permalink()) . '#pipeline-queue';
};
$js_text = [
  'urlRequired' => $pipeline_text('URLを入力してください。', 'Enter a URL.', '请输入URL。'),
  'queueing' => $pipeline_text('キューに追加しています…', 'Adding to the queue…', '正在添加到队列…'),
  'started' => $pipeline_text('開始しました。処理キューを更新します。', 'Started. Updating the work queue.', '已开始。正在更新处理队列。'),
  'startFailed' => $pipeline_text('開始できませんでした。', 'Could not start.', '无法启动。'),
  'networkError' => $pipeline_text('通信エラーが発生しました。', 'A network error occurred.', '发生网络错误。'),
  'conceptRequired' => $pipeline_text('概念を入力してください。', 'Enter a concept.', '请输入概念。'),
  'mapping' => $pipeline_text('概念マップをキューに追加しています…', 'Adding the concept map to the queue…', '正在将概念图添加到队列…'),
  'conceptStarted' => $pipeline_text('概念蒸留を開始しました。処理キューを更新します。', 'Concept distillation started. Updating the queue.', '概念蒸馏已开始。正在更新队列。'),
  'retrying' => $pipeline_text('同じ工程から再実行しています…', 'Retrying from the failed stage…', '正在从失败阶段重新执行…'),
  'retried' => $pipeline_text('再実行を開始しました。', 'Retry started.', '已开始重新执行。'),
  'saveFailed' => $pipeline_text('保存できませんでした。', 'Could not save.', '无法保存。'),
  'reevaluating' => $pipeline_text('保存済み回答を再評価しています…', 'Re-evaluating saved answers…', '正在重新评估已保存的回答…'),
  'reevaluated' => $pipeline_text('再評価が完了しました。表示を更新します。', 'Re-evaluation complete. Refreshing the results.', '重新评估完成。正在刷新结果。'),
  'graphSaving' => $pipeline_text('概念グラフを保存しています…', 'Saving the concept graph…', '正在保存概念图…'),
  'graphSaved' => $pipeline_text('グラフを保存しました。表示を更新します。', 'Graph saved. Refreshing the view.', '概念图已保存。正在刷新显示。'),
  'branchRequired' => $pipeline_text('枝の名称を入力してください。', 'Enter a branch name.', '请输入分支名称。'),
  'judgeSelectTwo' => $pipeline_text('設定済みのLLMを2つ以上選択してください。', 'Select at least two configured LLMs.', '请选择至少两个已配置的LLM。'),
  'judgeStarting' => $pipeline_text('審査ジョブを登録しています…', 'Queueing the judging job…', '正在提交评审任务…'),
  'judgeStarted' => $pipeline_text('審査を開始しました。モデルごとに順番に処理します。', 'Judging started. Models will run one at a time.', '评审已开始。模型将依次运行。'),
  'judgeRunning' => $pipeline_text('複数LLM審査を実行中です…', 'Multi-LLM judging is running…', '多LLM评审正在运行…'),
  'adopting' => $pipeline_text('選択した合議回答を採用しています…', 'Adopting selected consensus answers…', '正在采用所选共识回答…'),
  'adopted' => $pipeline_text('合議回答を採用しました。表示を更新します。', 'Consensus answers adopted. Refreshing…', '已采用共识回答。正在刷新…'),
  'selectQuestion' => $pipeline_text('対象の質問を1件以上選択してください。', 'Select at least one question.', '请至少选择一个问题。'),
  'redistillStarting' => $pipeline_text('選択的な再蒸留を登録しています…', 'Queueing selective re-distillation…', '正在提交选择性再蒸馏…'),
  'redistillRunning' => $pipeline_text('選択した質問を再蒸留しています…', 'Re-distilling selected questions…', '正在重新蒸馏所选问题…'),
  'weightsSaving' => $pipeline_text('モデル重みを反映して再計算しています…', 'Applying model weights…', '正在应用模型权重重新计算…'),
  'weightsSaved' => $pipeline_text('重みを保存しました。合議結果を更新します。', 'Weights saved. Refreshing consensus…', '权重已保存。正在更新共识结果…'),
  'gapSelectBranch' => $pipeline_text('質問を追加する枝を選択してください。', 'Select at least one branch to expand.', '请选择至少一个要扩展的分支。'),
  'gapGenerating' => $pipeline_text('追加質問候補を生成しています…', 'Generating additional question candidates…', '正在生成追加问题候选…'),
  'gapPreviewReady' => $pipeline_text('差分プレビューを更新します。', 'Refreshing the question diff preview.', '正在刷新问题差异预览。'),
  'gapSelectCandidate' => $pipeline_text('採用する新規質問を選択してください。', 'Select at least one new question.', '请选择至少一个新问题。'),
  'gapApplying' => $pipeline_text('質問を追加し、回答生成を開始しています…', 'Adding questions and starting answer generation…', '正在添加问题并开始生成回答…'),
  'gapApplied' => $pipeline_text('質問を追加しました。回答生成を開始します。', 'Questions added. Answer generation started.', '问题已添加，回答生成已开始。'),
];

get_header();
?>
<main class="fourier-pipeline" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
  <section class="pipeline-hero">
    <p class="eyebrow">AI LEARNING DATA</p>
    <h1>
      <span><?php echo esc_html($pipeline_text('URLを、', 'Turn URLs', '将URL')); ?></span>
      <span><?php echo esc_html($pipeline_text('レビュー可能な', 'into reviewable', '转换为可审核的')); ?></span>
      <span class="headline-break"><?php echo esc_html($pipeline_text('学習データへ。', 'learning data.', '学习数据。')); ?></span>
    </h1>
    <p><?php echo esc_html($pipeline_text('取得から概念抽出、Knowledge Server登録までを自動処理します。', 'Automate extraction, concept discovery, and Knowledge Server registration.', '自动完成采集、概念提取和Knowledge Server注册。')); ?></p>
  </section>

  <section class="pipeline-card pipeline-input">
    <label for="pipeline-url"><?php echo esc_html($pipeline_text('対象URL', 'Source URL', '目标URL')); ?></label>
    <div class="pipeline-form">
      <input id="pipeline-url" type="url" placeholder="https://example.com/article" required>
      <select id="pipeline-provider" aria-label="<?php echo esc_attr($pipeline_text('LLMプロバイダ', 'LLM provider', 'LLM提供商')); ?>">
        <?php foreach ($pipeline_provider_statuses as $provider_key => $provider_status): ?>
          <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($pipeline_default_provider, $provider_key); ?> <?php disabled(empty($provider_status['configured'])); ?>><?php echo esc_html($provider_status['label'] . ' · ' . $provider_status['model'] . (!empty($provider_status['configured']) ? '' : '（未設定）')); ?></option>
        <?php endforeach; ?>
      </select>
      <button id="pipeline-start" type="button"><?php echo esc_html($pipeline_text('パイプライン開始', 'Start pipeline', '启动流程')); ?></button>
    </div>
    <details class="pipeline-settings">
      <summary><?php echo esc_html($pipeline_text('Knowledge Server設定（任意）', 'Knowledge Server settings (optional)', 'Knowledge Server设置（可选）')); ?></summary>
      <div class="pipeline-form pipeline-knowledge">
        <input id="pipeline-knowledge-url" type="url" placeholder="https://knowledge.example.com">
        <input id="pipeline-knowledge-token" type="password" placeholder="Bearer token">
        <small><?php echo esc_html($pipeline_text('入力値はWordPressのユーザーメタに保存されます。', 'Values are saved in WordPress user metadata.', '输入值将保存到WordPress用户元数据中。')); ?></small>
      </div>
    </details>
    <p id="pipeline-feedback" class="pipeline-feedback" role="status"></p>
  </section>

  <section class="pipeline-card concept-input">
    <p class="eyebrow">CONCEPT DISTILLATION</p>
    <h2><?php echo esc_html($pipeline_text('概念から知識を広げる', 'Expand knowledge from a concept', '从概念扩展知识')); ?></h2>
    <p class="concept-description"><?php echo esc_html($pipeline_text('中心概念から、観念の枝、質問、複数の回答候補を生成します。', 'Generate knowledge branches, questions, and answer variants from one concept.', '从核心概念生成知识分支、问题和多个回答候选。')); ?></p>
    <label for="pipeline-concept"><?php echo esc_html($pipeline_text('中心概念', 'Root concept', '核心概念')); ?></label>
    <div class="pipeline-form">
      <input id="pipeline-concept" type="text" placeholder="<?php echo esc_attr($pipeline_text('例：犬', 'Example: dog', '例如：狗')); ?>" maxlength="120">
      <select id="concept-provider" aria-label="<?php echo esc_attr($pipeline_text('概念蒸留のLLM', 'Concept distillation LLM', '概念蒸馏LLM')); ?>">
        <?php foreach ($pipeline_provider_statuses as $provider_key => $provider_status): ?>
          <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($pipeline_default_provider, $provider_key); ?> <?php disabled(empty($provider_status['configured'])); ?>><?php echo esc_html($provider_status['label'] . ' · ' . $provider_status['model'] . (!empty($provider_status['configured']) ? '' : '（未設定）')); ?></option>
        <?php endforeach; ?>
      </select>
      <button id="concept-start" type="button"><?php echo esc_html($pipeline_text('概念蒸留を開始', 'Start concept distillation', '启动概念蒸馏')); ?></button>
    </div>
    <div class="concept-source-control">
      <label for="concept-source-post"><?php echo esc_html($pipeline_text('根拠資料（任意）', 'Grounding source (optional)', '依据资料（可选）')); ?></label>
      <select id="concept-source-post">
        <option value=""><?php echo esc_html($pipeline_text('根拠資料を使わない', 'No grounding source', '不使用依据资料')); ?></option>
        <?php foreach ($pipeline_sources as $pipeline_source):
          $source_data = get_post_meta($pipeline_source->ID, '_fourier_pipeline_data', true);
          $source_ready = is_array($source_data) && !empty($source_data['extraction']['text']);
          $source_stage = get_post_meta($pipeline_source->ID, '_fourier_pipeline_stage', true);
        ?>
          <option value="<?php echo (int) $pipeline_source->ID; ?>" data-url="<?php echo esc_attr(get_post_meta($pipeline_source->ID, '_fourier_pipeline_url', true)); ?>">
            <?php echo esc_html($pipeline_source->post_title . ' — ' . ($source_ready ? $pipeline_text('文章抽出済み', 'Text ready', '文本已提取') : $source_stage)); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small><?php echo esc_html($pipeline_text('URLパイプラインで抽出した本文を、概念マップ・質問・回答の根拠として使用します。', 'Use text extracted by the URL pipeline to ground the concept map, questions, and answers.', '使用URL流程提取的文本作为概念图、问题和回答的依据。')); ?></small>
    </div>
    <p id="concept-feedback" class="pipeline-feedback" role="status"></p>
  </section>

  <section class="pipeline-card">
    <div class="pipeline-heading">
      <div>
        <p class="eyebrow">WORK QUEUE</p>
        <h2 id="pipeline-queue"><?php echo esc_html($pipeline_text('処理キュー', 'Work queue', '处理队列')); ?></h2>
      </div>
      <span><?php echo esc_html(sprintf($pipeline_text('該当 %d件', '%d matches', '共%d条匹配'), $queue_query->found_posts)); ?></span>
    </div>
    <div class="queue-toolbar">
      <p><?php echo esc_html($pipeline_text('本人の全データから検索します。1ページ20件、更新日時の新しい順です。', 'Search all your records. 20 per page, most recently updated first.', '搜索本人的全部记录，每页20条，按更新时间倒序。')); ?></p>
      <form class="queue-browse-form" method="get" action="<?php echo esc_url(get_permalink()); ?>#pipeline-queue">
      <?php if (!get_option('permalink_structure')): ?><input type="hidden" name="page_id" value="<?php echo (int) get_queried_object_id(); ?>"><?php endif; ?>
      <label for="queue-search"><?php echo esc_html($pipeline_text('名称・出典を検索', 'Search title or source', '搜索名称或来源')); ?></label>
      <input id="queue-search" name="queue_search" value="<?php echo esc_attr($queue_search); ?>" maxlength="200" type="search" autocomplete="off" aria-controls="pipeline-list">
      <label for="queue-status"><?php echo esc_html($pipeline_text('状態', 'Status', '状态')); ?></label>
      <select id="queue-status" name="queue_status">
      <?php foreach ($queue_labels as $key => $label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($queue_status, $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
      </select>
      <div class="queue-filters" role="group" aria-label="<?php echo esc_attr($pipeline_text('状態で絞り込み', 'Filter by status', '按状态筛选')); ?>">
        <button type="submit"><?php echo esc_html($pipeline_text('検索・絞り込み', 'Apply filters', '搜索与筛选')); ?></button>
        <a href="<?php echo esc_url(get_permalink() . '#pipeline-queue'); ?>"><?php echo esc_html($pipeline_text('条件をリセット', 'Reset filters', '重置条件')); ?></a>
      </div>
      </form>
      <nav class="queue-pagination" aria-label="<?php echo esc_attr($pipeline_text('キューのページ移動', 'Queue pagination', '队列分页')); ?>">
        <?php if ($queue_page > 1): ?><a rel="prev" href="<?php echo esc_url($queue_link($queue_page - 1)); ?>"><?php echo esc_html($pipeline_text('前へ', 'Previous', '上一页')); ?></a><?php endif; ?>
        <span><?php echo esc_html(sprintf($pipeline_text('%d / %d ページ', 'Page %d of %d', '第%d页，共%d页'), $queue_page, max(1, $queue_query->max_num_pages))); ?></span>
        <?php if ($queue_page < $queue_query->max_num_pages): ?><a rel="next" href="<?php echo esc_url($queue_link($queue_page + 1)); ?>"><?php echo esc_html($pipeline_text('次へ', 'Next', '下一页')); ?></a><?php endif; ?>
      </nav>
      <div class="queue-filters"><button id="queue-refresh" type="button"><?php echo esc_html($pipeline_text('状態だけ更新', 'Refresh status', '仅刷新状态')); ?></button>
      <label><input id="queue-auto-refresh" type="checkbox"> <?php echo esc_html($pipeline_text('30秒ごとに状態確認', 'Check every 30 seconds', '每30秒检查状态')); ?></label></div>
      <p id="queue-refresh-feedback" role="status" aria-live="polite"><?php echo esc_html($pipeline_text('状態のみ更新できます。編集内容は保持します。件数・順序・生成結果は再読み込み時に更新します。', 'Status refresh preserves edits. Counts, ordering and generated data update on reload.', '仅刷新状态并保留编辑。数量、排序和生成结果需重新加载。')); ?></p>
      <p id="queue-no-match" role="status" <?php echo $items ? 'hidden' : ''; ?>><?php echo esc_html($pipeline_text('条件に一致するデータはありません。検索語や状態の絞り込みを変更してください。', 'No matches. Change the search or status filter.', '没有匹配记录，请更改关键词或状态筛选。')); ?></p>
    </div>
    <div id="pipeline-list">
      <?php foreach ($items as $item):
        $stage = get_post_meta($item->ID, '_fourier_pipeline_stage', true);
        $status = get_post_meta($item->ID, '_fourier_pipeline_status', true);
        $kind = get_post_meta($item->ID, '_fourier_pipeline_kind', true);
        $item_provider = get_post_meta($item->ID, '_fourier_pipeline_provider', true);
        $concept_source_id = (int) get_post_meta($item->ID, '_fourier_pipeline_source_post_id', true);
        $source = $kind === 'concept' ? $pipeline_text('概念: ', 'Concept: ', '概念：') . get_post_meta($item->ID, '_fourier_pipeline_concept', true) : ($kind === 'auto_distillation' ? 'Auto Distillation Job #' . get_post_meta($item->ID, '_fourier_auto_job_id', true) : get_post_meta($item->ID, '_fourier_pipeline_url', true));
        if ($item_provider) $source .= ' · ' . strtoupper($item_provider);
        if ($concept_source_id) $source .= ' · ' . $pipeline_text('根拠: ', 'Source: ', '依据：') . get_the_title($concept_source_id);
        $pipeline_data = $kind === 'concept' ? get_post_meta($item->ID, '_fourier_pipeline_data', true) : [];
        $quality = is_array($pipeline_data) && is_array($pipeline_data['answer_quality'] ?? null) ? $pipeline_data['answer_quality'] : [];
        $quality_summary = $quality['summary'] ?? [];
        $quality_settings = $kind === 'concept' ? fourier_concept_get_quality_settings($item->ID) : [];
        $knowledge_sync_required = !empty($pipeline_data['knowledge']['sync_required']);
        $quality_history = $kind === 'concept' ? get_post_meta($item->ID, '_fourier_concept_quality_history', true) : [];
        $quality_history_count = is_array($quality_history) ? count($quality_history) : 0;
        $concept_map = is_array($pipeline_data['concept_map'] ?? null) ? $pipeline_data['concept_map'] : [];
        $training_value = is_array($pipeline_data['training_value'] ?? null) ? $pipeline_data['training_value'] : [];
        if ($kind === 'concept' && !$training_value && $quality) {
          $training_value = fourier_concept_build_training_value(
            get_post_meta($item->ID, '_fourier_pipeline_concept', true) ?: ($pipeline_data['concept'] ?? ''),
            $concept_map,
            $pipeline_data['branch_questions'] ?? [],
            $quality,
            fourier_concept_training_context($item->ID, $pipeline_data)
          );
        }
        $training_summary = $training_value['summary'] ?? [];
        $coverage_gaps = is_array($training_value['coverage_gaps'] ?? null) ? $training_value['coverage_gaps'] : [];
        $concept_branches = is_array($concept_map['branches'] ?? null) ? $concept_map['branches'] : [];
        $graph_meta = is_array($pipeline_data['concept_graph'] ?? null) ? $pipeline_data['concept_graph'] : [];
        $branch_question_counts = [];
        foreach (($pipeline_data['branch_questions']['questions'] ?? []) as $question) {
          $branch_id = sanitize_key($question['branch_id'] ?? '');
          $branch_question_counts[$branch_id] = ($branch_question_counts[$branch_id] ?? 0) + 1;
        }
        $branch_answer_counts = [];
        foreach (($quality['curated_items'] ?? []) as $curated_item) {
          $branch_id = sanitize_key($curated_item['branch_id'] ?? '');
          $branch_answer_counts[$branch_id] = ($branch_answer_counts[$branch_id] ?? 0) + count($curated_item['answer_variants'] ?? []);
        }
        $active_branch_count = count(array_filter($concept_branches, static function ($branch) {
          return !array_key_exists('enabled', $branch) || $branch['enabled'];
        }));
        $multi_judge = is_array($pipeline_data['multi_judge'] ?? null) ? $pipeline_data['multi_judge'] : [];
        $judge_statuses = $kind === 'concept' ? fourier_concept_multi_judge_provider_status((int) $item->post_author) : [];
        $judge_configured_count = count(array_filter($judge_statuses, static function ($provider) {
          return !empty($provider['configured']);
        }));
        $judge_summary = $multi_judge['consensus']['summary'] ?? [];
        $judge_running = in_array($multi_judge['status'] ?? '', ['queued', 'running'], true);
        $judge_status_key = $multi_judge['status'] ?? 'idle';
        $judge_status_label = [
          'idle' => $pipeline_text('未実行', 'Not run', '未运行'),
          'queued' => $pipeline_text('審査待ち', 'Queued', '等待评审'),
          'running' => $pipeline_text('審査中', 'Running', '评审中'),
          'completed' => $pipeline_text('完了', 'Completed', '已完成'),
          'partial' => $pipeline_text('部分完了', 'Partial', '部分完成'),
          'error' => $pipeline_text('失敗', 'Failed', '失败'),
        ][$judge_status_key] ?? $judge_status_key;
        $curation_items = is_array($pipeline_data['curation_decisions']['items'] ?? null) ? $pipeline_data['curation_decisions']['items'] : [];
        $redistill_job = is_array($pipeline_data['selective_redistillation'] ?? null) ? $pipeline_data['selective_redistillation'] : [];
        $redistill_running = in_array($redistill_job['status'] ?? '', ['queued', 'running'], true);
        $question_expansion = is_array($pipeline_data['question_expansion'] ?? null) ? $pipeline_data['question_expansion'] : [];
        $question_expansion_running = in_array($question_expansion['status'] ?? '', ['queued', 'running'], true);
        $question_expansion_provider = $question_expansion['provider'] ?? get_post_meta($item->ID, '_fourier_pipeline_provider', true);
        if (empty($judge_statuses[$question_expansion_provider]['configured'])) {
          foreach ($judge_statuses as $available_provider) {
            if (!empty($available_provider['configured'])) { $question_expansion_provider = $available_provider['provider']; break; }
          }
        }
        $judge_history = $kind === 'concept' ? get_post_meta($item->ID, '_fourier_concept_multi_judge_history', true) : [];
        $judge_history = is_array($judge_history) ? $judge_history : [];
        $judge_trends = fourier_concept_multi_judge_trends($multi_judge, $judge_history);
        $judge_weights = fourier_concept_multi_judge_normalize_weights($multi_judge['weights'] ?? [], array_keys($multi_judge['results'] ?? []));
      ?>
        <article class="pipeline-row" data-status="<?php echo esc_attr($status); ?>" data-id="<?php echo (int) $item->ID; ?>" data-kind="<?php echo esc_attr($kind ?: 'url'); ?>" data-source-post-id="<?php echo (int) $concept_source_id; ?>">
          <div class="pipeline-title"><strong><?php echo esc_html($item->post_title); ?></strong><small><?php echo esc_html($source); ?></small></div>
          <div class="pipeline-state"><span class="state-<?php echo esc_attr($status); ?>"><?php echo esc_html(isset($stage_labels[$stage]) ? $pipeline_text(...$stage_labels[$stage]) : $stage); ?></span><small><?php echo esc_html($queue_labels[$status] ?? $status); ?></small></div>
          <div class="queue-health">
            <?php
            $updated = get_post_meta($item->ID, '_fourier_pipeline_updated', true);
            $updated_date = $updated ? date_create_immutable($updated, wp_timezone()) : false;
            $age = $updated_date ? max(0, time() - $updated_date->getTimestamp()) : 0;
            ?>
            <small>#<?php echo (int) $item->ID; ?> · <?php echo esc_html($pipeline_text('最終状態更新: ', 'Last status update: ', '最近状态更新：') . ($updated ?: '—')); ?><?php if ($updated_date): ?> · <?php echo esc_html(sprintf($pipeline_text('%d分経過', '%d min elapsed', '已过%d分钟'), floor($age / 60))); ?><?php endif; ?></small>
            <?php if (in_array($status, ['queued', 'processing'], true)): ?>
              <p><?php echo esc_html($status === 'queued' ? $pipeline_text('ワーカーの実行待ちです。登録完了は生成完了ではありません。', 'Waiting for a worker. Queued does not mean generated.', '正在等待执行器，注册成功不代表生成完成。') : $pipeline_text('ワーカーが工程を開始しました。AIの応答待ちを含みます。モデルの稼働を保証する表示ではありません。', 'The worker started this stage, including possible model wait time. This is not a model heartbeat.', '执行器已开始本阶段，可能正在等待模型响应。这不代表模型心跳正常。')); ?></p>
              <?php if ($age >= 300): ?><p class="queue-warning"><?php echo esc_html($pipeline_text('5分以上、状態が更新されていません。失敗とは断定できません。モデルサーバー・Cron・通信の状態を確認し、重複した開始操作は避けてください。', 'No status update for 5+ minutes. This is not a confirmed failure. Check the model server, Cron and network before starting another job.', '超过5分钟未更新，尚不能判定失败。请检查模型服务器、Cron和网络，避免重复启动。')); ?></p><?php endif; ?>
            <?php elseif ($status === 'review'): ?><p><?php echo esc_html($pipeline_text('生成結果を確認してから承認してください。承認すると公開されます。', 'Check the generated data before approval. Approval publishes it.', '请检查生成结果后再批准，批准将公开发布。')); ?></p><?php endif; ?>
          </div>
          <?php if ($status === 'review'): ?>
            <div class="pipeline-actions">
              <button class="pipeline-review" data-decision="approve"><?php echo esc_html($pipeline_text('承認', 'Approve', '批准')); ?></button>
              <button class="pipeline-review secondary" data-decision="reject"><?php echo esc_html($pipeline_text('差し戻し', 'Reject', '驳回')); ?></button>
            </div>
          <?php endif; ?>
          <?php if ($status === 'error'): ?>
            <div class="pipeline-error-detail" role="alert">
              <p><?php echo esc_html(get_post_meta($item->ID, '_fourier_pipeline_message', true) ?: $pipeline_text('処理に失敗しました。', 'Processing failed.', '处理失败。')); ?></p>
              <button class="pipeline-retry" type="button"><?php echo esc_html($pipeline_text('この工程から再実行', 'Retry this stage', '从此阶段重试')); ?></button>
              <small class="pipeline-retry-feedback" role="status"></small>
            </div>
          <?php endif; ?>

          <?php if ($concept_branches): ?>
            <details class="concept-graph-panel">
              <summary>
                <span><?php echo esc_html($pipeline_text('概念グラフ', 'Concept graph', '概念图')); ?></span>
                <span class="graph-chip"><?php echo esc_html(sprintf($pipeline_text('有効な枝 %d', '%d active branches', '%d个有效分支'), $active_branch_count)); ?></span>
                <span class="graph-chip"><?php echo esc_html(sprintf($pipeline_text('質問 %d', '%d questions', '%d个问题'), array_sum($branch_question_counts))); ?></span>
                <span class="graph-chip"><?php echo esc_html(sprintf($pipeline_text('採用回答 %d', '%d accepted answers', '%d个采用回答'), array_sum($branch_answer_counts))); ?></span>
                <?php if (!empty($graph_meta['distillation_refresh_required'])): ?><span class="graph-chip refresh-required"><?php echo esc_html($pipeline_text('再蒸留が必要', 'Re-distillation required', '需要重新蒸馏')); ?></span><?php endif; ?>
              </summary>
              <div class="concept-graph-workspace">
                <div class="concept-graph-view">
                  <div class="graph-toolbar">
                    <p><?php echo esc_html($pipeline_text('枝を選択すると編集できます。', 'Select a branch to edit it.', '选择分支即可编辑。')); ?></p>
                    <button class="graph-add-branch" type="button"><?php echo esc_html($pipeline_text('枝を追加', 'Add branch', '添加分支')); ?></button>
                  </div>
                  <div class="graph-root-node">
                    <span><?php echo esc_html($pipeline_text('中心概念', 'Root concept', '核心概念')); ?></span>
                    <strong><?php echo esc_html(get_post_meta($item->ID, '_fourier_pipeline_concept', true)); ?></strong>
                    <?php if (!empty($concept_map['definition'])): ?><small><?php echo esc_html($concept_map['definition']); ?></small><?php endif; ?>
                  </div>
                  <div class="graph-trunk" aria-hidden="true"></div>
                  <div class="graph-branches">
                    <?php foreach ($concept_branches as $branch):
                      $branch_id = sanitize_key($branch['id'] ?? '');
                      $branch_enabled = !array_key_exists('enabled', $branch) || $branch['enabled'];
                      $angles = fourier_concept_sanitize_question_angles($branch['question_angles'] ?? []);
                    ?>
                      <button class="graph-branch-node<?php echo $branch_enabled ? '' : ' is-disabled'; ?>" type="button"
                        data-branch-id="<?php echo esc_attr($branch_id); ?>"
                        data-label="<?php echo esc_attr($branch['label'] ?? $branch_id); ?>"
                        data-scope="<?php echo esc_attr($branch['scope'] ?? ''); ?>"
                        data-priority="<?php echo esc_attr((int) ($branch['priority'] ?? 2)); ?>"
                        data-angles="<?php echo esc_attr(implode("\n", $angles)); ?>"
                        data-enabled="<?php echo $branch_enabled ? '1' : '0'; ?>">
                        <span class="graph-branch-priority">P<?php echo esc_html((int) ($branch['priority'] ?? 2)); ?></span>
                        <strong><?php echo esc_html($branch['label'] ?? $branch_id); ?></strong>
                        <small><?php echo esc_html(sprintf($pipeline_text('質問 %1$d · 回答 %2$d', '%1$d questions · %2$d answers', '%1$d个问题 · %2$d个回答'), (int) ($branch_question_counts[$branch_id] ?? 0), (int) ($branch_answer_counts[$branch_id] ?? 0))); ?></small>
                        <?php if (!$branch_enabled): ?><em><?php echo esc_html($pipeline_text('無効', 'Disabled', '已停用')); ?></em><?php endif; ?>
                      </button>
                    <?php endforeach; ?>
                  </div>
                </div>
                <form class="graph-editor" novalidate>
                  <input class="graph-mode" type="hidden" value="update">
                  <input class="graph-branch-id" type="hidden" value="">
                  <div class="graph-editor-heading">
                    <div><span><?php echo esc_html($pipeline_text('選択した枝', 'Selected branch', '已选分支')); ?></span>
                      <h3 class="graph-editor-title"><?php echo esc_html($pipeline_text('枝を選択', 'Select a branch', '选择分支')); ?></h3>
                    </div>
                    <button class="graph-cancel-edit" type="button"><?php echo esc_html($pipeline_text('リセット', 'Reset', '重置')); ?></button>
                  </div>
                  <label><?php echo esc_html($pipeline_text('枝の名称', 'Branch name', '分支名称')); ?><input class="graph-label" type="text" maxlength="80" required></label>
                  <label><?php echo esc_html($pipeline_text('知識の範囲', 'Knowledge scope', '知识范围')); ?><textarea class="graph-scope" rows="4" maxlength="600"></textarea></label>
                  <div class="graph-editor-row">
                    <label><?php echo esc_html($pipeline_text('優先度', 'Priority', '优先级')); ?><select class="graph-priority">
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                      </select></label>
                    <label class="graph-enabled-label"><input class="graph-enabled" type="checkbox" checked><?php echo esc_html($pipeline_text('この枝を有効にする', 'Enable this branch', '启用此分支')); ?></label>
                  </div>
                  <label><?php echo esc_html($pipeline_text('質問観点（1行に1件）', 'Question angles (one per line)', '问题角度（每行一个）')); ?><textarea class="graph-angles" rows="5"></textarea></label>
                  <button class="graph-save-branch" type="submit"><?php echo esc_html($pipeline_text('枝を保存', 'Save branch', '保存分支')); ?></button>
                  <p class="graph-feedback" role="status"></p>
                </form>
              </div>
            </details>
          <?php endif; ?>

          <?php if ($training_summary): ?>
            <details class="training-value-panel">
              <summary>
                <span><?php echo esc_html($pipeline_text('学習価値', 'Training value', '训练价值')); ?></span>
                <span class="training-chip value"><?php echo esc_html(sprintf($pipeline_text('総合 %.1f', 'Overall %.1f', '综合 %.1f'), (float) ($training_summary['training_value'] ?? 0))); ?></span>
                <span class="training-chip"><?php echo esc_html(sprintf($pipeline_text('被覆 %.0f%%', 'Coverage %.0f%%', '覆盖 %.0f%%'), (float) ($training_summary['concept_coverage'] ?? 0))); ?></span>
                <span class="training-chip eligible"><?php echo esc_html(sprintf($pipeline_text('学習候補 %d', 'Eligible %d', '可训练 %d'), (int) ($training_summary['eligible_samples'] ?? 0))); ?></span>
                <?php if (!empty($training_summary['conflict_samples'])): ?><span class="training-chip conflict"><?php echo esc_html(sprintf($pipeline_text('矛盾候補 %d', 'Possible conflicts %d', '潜在矛盾 %d'), (int) $training_summary['conflict_samples'])); ?></span><?php endif; ?>
              </summary>
              <div class="training-value-body">
                <div class="training-value-intro">
                  <div>
                    <h3><?php echo esc_html($pipeline_text('このデータから何を新しく学べるか', 'What this data adds', '这些数据能带来哪些新知识')); ?></h3>
                    <p><?php echo esc_html($pipeline_text('品質だけでなく、新規性・信頼性・概念被覆・重複・難易度・矛盾候補を分離して評価します。', 'Separates novelty, reliability, concept coverage, redundancy, difficulty, and possible contradictions from basic quality.', '除基础质量外，分别评估新颖性、可靠性、概念覆盖、重复度、难度和潜在矛盾。')); ?></p>
                  </div>
                  <small><?php echo esc_html($pipeline_text('決定論的な一次評価。事実確認・ライセンス確認はレビューで行ってください。', 'Deterministic first-pass estimate. Verify facts and licensing during review.', '确定性初评；事实与许可仍需人工审核。')); ?></small>
                </div>
                <div class="training-metric-grid">
                  <div><span><?php echo esc_html($pipeline_text('情報利得', 'Information gain', '信息增益')); ?></span><strong><?php echo esc_html(number_format((float) ($training_summary['information_gain'] ?? 0), 1)); ?></strong></div>
                  <div><span><?php echo esc_html($pipeline_text('信頼性', 'Reliability', '可靠性')); ?></span><strong><?php echo esc_html(number_format((float) ($training_summary['reliability'] ?? 0), 1)); ?></strong></div>
                  <div><span><?php echo esc_html($pipeline_text('概念被覆', 'Concept coverage', '概念覆盖')); ?></span><strong><?php echo esc_html(number_format((float) ($training_summary['concept_coverage'] ?? 0), 0)); ?>%</strong></div>
                  <div><span><?php echo esc_html($pipeline_text('要レビュー', 'Needs review', '需审核')); ?></span><strong><?php echo esc_html((int) ($training_summary['review_required_samples'] ?? 0)); ?></strong></div>
                </div>
                <?php if ($coverage_gaps): ?>
                  <section class="coverage-gap-section">
                    <div class="coverage-gap-heading">
                      <h4><?php echo esc_html($pipeline_text('次に埋める知識領域', 'Knowledge gaps to fill next', '下一步需要补齐的知识领域')); ?></h4>
                      <p><?php echo esc_html($pipeline_text('被覆率が低い順です。質問・回答の追加先を選ぶ参考にしてください。', 'Ordered by lowest coverage to guide the next questions and answers.', '按覆盖率从低到高排列，用于指导下一轮问题与回答生成。')); ?></p>
                    </div>
                    <div class="coverage-gap-list">
                      <?php foreach ($coverage_gaps as $gap): ?>
                        <article>
                          <label class="coverage-gap-choice"><input class="coverage-branch" type="checkbox" value="<?php echo esc_attr($gap['branch_id'] ?? ''); ?>"><span><b><?php echo esc_html($gap['label'] ?? $gap['branch_id']); ?></b><small><?php echo esc_html(sprintf($pipeline_text('質問 %1$d · 採用 %2$d', '%1$d questions · %2$d accepted', '%1$d个问题 · %2$d个采用样本'), (int) ($gap['question_count'] ?? 0), (int) ($gap['accepted_sample_count'] ?? 0))); ?></small></span></label>
                          <div class="coverage-meter" aria-label="<?php echo esc_attr(sprintf($pipeline_text('被覆率 %d%%', 'Coverage %d%%', '覆盖率 %d%%'), (int) ($gap['coverage'] ?? 0))); ?>"><i style="width:<?php echo esc_attr(max(0, min(100, (int) ($gap['coverage'] ?? 0)))); ?>%"></i></div>
                          <strong><?php echo esc_html((int) ($gap['coverage'] ?? 0)); ?>%</strong>
                        </article>
                      <?php endforeach; ?>
                    </div>
                    <div class="question-expansion" data-status="<?php echo esc_attr($question_expansion['status'] ?? 'idle'); ?>">
                      <div class="question-expansion-controls">
                        <label><?php echo esc_html($pipeline_text('質問生成LLM', 'Question generation LLM', '问题生成LLM')); ?><select class="question-expansion-provider">
                          <?php foreach ($judge_statuses as $provider): ?><option value="<?php echo esc_attr($provider['provider']); ?>" <?php disabled(!$provider['configured']); ?> <?php selected($question_expansion_provider, $provider['provider']); ?>><?php echo esc_html($provider['label'] . ' · ' . $provider['model'] . ($provider['configured'] ? '' : '（未設定）')); ?></option><?php endforeach; ?>
                        </select></label>
                        <button class="question-expansion-start" type="button" <?php disabled($question_expansion_running || $judge_configured_count === 0); ?>><?php echo esc_html($question_expansion_running ? $pipeline_text('候補を生成中', 'Generating candidates', '正在生成候选') : $pipeline_text('選択した枝の質問候補を生成', 'Generate questions for selected branches', '为所选分支生成问题')); ?></button>
                      </div>
                      <p class="question-expansion-feedback" role="status"><?php echo esc_html($question_expansion['message'] ?? ''); ?></p>
                      <?php if (($question_expansion['status'] ?? '') === 'preview' && !empty($question_expansion['candidates'])): ?>
                        <div class="question-diff-preview">
                          <div class="question-diff-heading"><b><?php echo esc_html($pipeline_text('質問の差分プレビュー', 'Question diff preview', '问题差异预览')); ?></b><span><?php echo esc_html(sprintf($pipeline_text('新規 %1$d · 重複候補 %2$d', '%1$d new · %2$d possible duplicates', '%1$d个新问题 · %2$d个重复候选'), (int) ($question_expansion['new_count'] ?? 0), (int) ($question_expansion['duplicate_count'] ?? 0))); ?></span></div>
                          <?php foreach ($question_expansion['candidates'] as $candidate): $is_new = ($candidate['diff_status'] ?? '') === 'new'; ?>
                            <label class="question-diff-item <?php echo $is_new ? 'is-new' : 'is-duplicate'; ?>">
                              <input class="question-candidate" type="checkbox" value="<?php echo esc_attr($candidate['candidate_id'] ?? ''); ?>" <?php checked($is_new); ?> <?php disabled(!$is_new); ?>>
                              <span><b><?php echo esc_html($candidate['question'] ?? ''); ?></b><small><?php echo esc_html(($candidate['branch_id'] ?? '') . ' · ' . ($is_new ? $pipeline_text('新規', 'New', '新增') : sprintf($pipeline_text('類似 %.0f%%', 'Similar %.0f%%', '相似 %.0f%%'), ((float) ($candidate['similarity'] ?? 0)) * 100))); ?></small><?php if (!$is_new && !empty($candidate['closest_question'])): ?><em><?php echo esc_html($pipeline_text('既存: ', 'Existing: ', '现有：') . $candidate['closest_question']); ?></em><?php endif; ?></span>
                            </label>
                          <?php endforeach; ?>
                          <button class="question-expansion-apply" type="button"><?php echo esc_html($pipeline_text('選択質問を採用して回答生成', 'Add selected questions and generate answers', '采用所选问题并生成回答')); ?></button>
                        </div>
                      <?php endif; ?>
                    </div>
                  </section>
                <?php endif; ?>
                <div class="training-provenance">
                  <span><?php echo esc_html(sprintf('Source ID: %s', $training_value['provenance']['source_id'] ?? '—')); ?></span>
                  <span><?php echo esc_html(sprintf('Knowledge ID: %s', $training_value['provenance']['knowledge_id'] ?? '—')); ?></span>
                  <span><?php echo esc_html($pipeline_text('ライセンス: 要確認', 'License: review required', '许可：需审核')); ?></span>
                  <span><?php echo esc_html($pipeline_text('時間的有効性: 未評価', 'Temporal validity: unassessed', '时效性：未评估')); ?></span>
                </div>
              </div>
            </details>
          <?php endif; ?>

          <?php if ($quality_summary): ?>
            <details class="quality-panel">
              <summary>
                <span><?php echo esc_html($pipeline_text('回答品質', 'Answer quality', '回答质量')); ?></span>
                <span class="quality-chip score"><?php echo esc_html(sprintf($pipeline_text('平均 %.1f点', 'Avg %.1f', '平均 %.1f分'), (float) ($quality_summary['average_score'] ?? 0))); ?></span>
                <span class="quality-chip accepted"><?php echo esc_html(sprintf($pipeline_text('採用 %d', 'Accepted %d', '采用 %d'), (int) ($quality_summary['accepted_variants'] ?? 0))); ?></span>
                <span class="quality-chip rejected"><?php echo esc_html(sprintf($pipeline_text('除外 %d', 'Rejected %d', '排除 %d'), (int) ($quality_summary['rejected_variants'] ?? 0))); ?></span>
                <span class="quality-chip duplicate"><?php echo esc_html(sprintf($pipeline_text('重複 %d', 'Duplicates %d', '重复 %d'), (int) ($quality_summary['duplicate_rejected'] ?? 0))); ?></span>
                <?php if ($knowledge_sync_required): ?><span class="quality-chip sync-required"><?php echo esc_html($pipeline_text('再同期が必要', 'Sync required', '需要重新同步')); ?></span><?php endif; ?>
              </summary>
              <div class="quality-controls" data-profile="<?php echo esc_attr($quality_settings['profile'] ?? 'balanced'); ?>">
                <div class="quality-control">
                  <label for="quality-profile-<?php echo (int) $item->ID; ?>"><?php echo esc_html($pipeline_text('用途プリセット', 'Use-case preset', '用途预设')); ?></label>
                  <select id="quality-profile-<?php echo (int) $item->ID; ?>" class="quality-profile">
                    <option value="exploratory" data-minimum="50" data-duplicate="75" <?php selected($quality_settings['profile'] ?? '', 'exploratory'); ?>><?php echo esc_html($pipeline_text('探索重視', 'Exploratory', '探索优先')); ?></option>
                    <option value="balanced" data-minimum="65" data-duplicate="82" <?php selected($quality_settings['profile'] ?? 'balanced', 'balanced'); ?>><?php echo esc_html($pipeline_text('標準', 'Balanced', '标准')); ?></option>
                    <option value="strict" data-minimum="75" data-duplicate="88" <?php selected($quality_settings['profile'] ?? '', 'strict'); ?>><?php echo esc_html($pipeline_text('厳格', 'Strict', '严格')); ?></option>
                    <option value="custom" <?php selected($quality_settings['profile'] ?? '', 'custom'); ?>><?php echo esc_html($pipeline_text('カスタム', 'Custom', '自定义')); ?></option>
                  </select>
                </div>
                <div class="quality-control">
                  <label for="quality-minimum-<?php echo (int) $item->ID; ?>"><?php echo esc_html($pipeline_text('最低品質点', 'Minimum score', '最低质量分')); ?></label>
                  <input id="quality-minimum-<?php echo (int) $item->ID; ?>" class="quality-minimum" type="number" min="40" max="95" step="1" value="<?php echo esc_attr((int) ($quality_settings['minimum_score'] ?? 65)); ?>">
                </div>
                <div class="quality-control">
                  <label for="quality-duplicate-<?php echo (int) $item->ID; ?>"><?php echo esc_html($pipeline_text('重複類似度（%）', 'Duplicate similarity (%)', '重复相似度（%）')); ?></label>
                  <input id="quality-duplicate-<?php echo (int) $item->ID; ?>" class="quality-duplicate" type="number" min="60" max="98" step="1" value="<?php echo esc_attr((int) round(((float) ($quality_settings['duplicate_similarity'] ?? 0.82)) * 100)); ?>">
                </div>
                <button class="quality-reevaluate" type="button"><?php echo esc_html($pipeline_text('この設定で再評価', 'Re-evaluate', '按此设置重新评估')); ?></button>
                <p class="quality-feedback" role="status"></p>
                <?php if (!empty($quality['evaluated_at'])): ?>
                  <p class="quality-evaluation-meta"><?php echo esc_html(sprintf($pipeline_text('評価 #%1$d · %2$s · 履歴 %3$d件', 'Evaluation #%1$d · %2$s · %3$d history entries', '评估 #%1$d · %2$s · %3$d条历史'), (int) ($quality['evaluation_revision'] ?? 1), $quality['evaluated_at'], $quality_history_count)); ?></p>
                <?php endif; ?>
              </div>
              <div class="quality-questions">
                <?php foreach (($quality['items'] ?? []) as $quality_item): ?>
                  <section class="quality-question">
                    <h3><?php echo esc_html($quality_item['question'] ?: $quality_item['question_id']); ?></h3>
                    <?php foreach (['accepted_variants' => 'accepted', 'rejected_variants' => 'rejected'] as $variant_key => $variant_state): ?>
                      <?php foreach (($quality_item[$variant_key] ?? []) as $variant): ?>
                        <div class="quality-answer <?php echo esc_attr($variant_state); ?>">
                          <div class="quality-answer-meta">
                            <b><?php echo esc_html($variant_state === 'accepted' ? $pipeline_text('採用', 'Accepted', '采用') : $pipeline_text('除外', 'Rejected', '排除')); ?></b>
                            <span><?php echo esc_html((int) ($variant['quality_score'] ?? 0)); ?>/100</span>
                            <?php if (!empty($variant['duplicate_similarity'])): ?><span><?php echo esc_html(sprintf($pipeline_text('類似度 %.0f%%', 'Similarity %.0f%%', '相似度 %.0f%%'), ((float) $variant['duplicate_similarity']) * 100)); ?></span><?php endif; ?>
                          </div>
                          <p><?php echo esc_html($variant['answer'] ?? ''); ?></p>
                          <small><?php echo esc_html(implode(' ', array_map('strval', $variant['quality_reasons'] ?? []))); ?></small>
                        </div>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </section>
                <?php endforeach; ?>
              </div>
            </details>

            <details class="multi-judge-panel" data-job-status="<?php echo esc_attr($multi_judge['status'] ?? 'idle'); ?>" data-redistill-status="<?php echo esc_attr($redistill_job['status'] ?? 'idle'); ?>">
              <summary>
                <span><?php echo esc_html($pipeline_text('複数LLM審査', 'Multi-LLM judging', '多LLM评审')); ?></span>
                <span class="judge-chip status-<?php echo esc_attr($judge_status_key); ?>"><?php echo esc_html($judge_status_label); ?></span>
                <?php if (!empty($multi_judge['results'])): ?><span class="judge-chip"><?php echo esc_html(sprintf($pipeline_text('完了モデル %d', '%d models completed', '%d个模型完成'), count($multi_judge['results']))); ?></span><?php endif; ?>
                <?php if ($judge_summary): ?><span class="judge-chip agreement"><?php echo esc_html(sprintf($pipeline_text('全会一致 %1$d · 分割 %2$d', 'Unanimous %1$d · Split %2$d', '一致 %1$d · 分歧 %2$d'), (int) ($judge_summary['unanimous_count'] ?? 0), (int) ($judge_summary['split_count'] ?? 0))); ?></span><?php endif; ?>
                <?php if (!empty($multi_judge['stale'])): ?><span class="judge-chip stale"><?php echo esc_html($pipeline_text('再審査が必要', 'Re-judging required', '需要重新评审')); ?></span><?php endif; ?>
                <?php if ($curation_items): ?><span class="judge-chip adopted"><?php echo esc_html(sprintf($pipeline_text('採用済み %d', '%d adopted', '已采用 %d'), count($curation_items))); ?></span><?php endif; ?>
              </summary>
              <div class="multi-judge-body">
                <div class="judge-intro">
                  <div>
                    <h3><?php echo esc_html($pipeline_text('同じ基準で回答候補を比較', 'Compare candidates with one rubric', '使用统一标准比较回答候选')); ?></h3>
                    <p><?php echo esc_html($pipeline_text('モデル名や生成元を隠した回答を、正確性・関連性・完全性・明瞭さ・不確実性・有用性で評価します。結果は参考情報として保存され、現在の採否は自動変更しません。', 'Blind candidates are scored for accuracy, relevance, completeness, clarity, uncertainty handling, and usefulness. Results are advisory and do not automatically change current curation.', '隐藏模型与来源后，按准确性、相关性、完整性、清晰度、不确定性处理和实用性评分。结果仅供参考，不会自动更改当前采用状态。')); ?></p>
                  </div>
                  <span class="judge-cost-note"><?php echo esc_html($pipeline_text('実行すると選択したAPIを使用します', 'Uses selected APIs when run', '运行时会调用所选API')); ?></span>
                </div>

                <div class="judge-controls">
                  <div class="judge-provider-grid">
                    <?php $judge_default_selected = 0;
                    foreach ($judge_statuses as $provider): $judge_checked = $provider['configured'] && $judge_default_selected < 2;
                      if ($judge_checked) $judge_default_selected++; ?>
                      <label class="judge-provider<?php echo $provider['configured'] ? '' : ' is-unavailable'; ?>">
                        <input type="checkbox" value="<?php echo esc_attr($provider['provider']); ?>" <?php disabled(!$provider['configured']); ?> <?php checked($judge_checked); ?>>
                        <span><b><?php echo esc_html($provider['label']); ?></b><small><?php echo esc_html($provider['model']); ?></small></span>
                        <em><?php echo esc_html($provider['configured'] ? $pipeline_text('設定済み', 'Configured', '已配置') : $pipeline_text('未設定', 'Not configured', '未配置')); ?></em>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <div class="judge-run-row">
                    <label><?php echo esc_html($pipeline_text('審査する質問数', 'Questions to judge', '评审问题数')); ?><select class="judge-max-questions">
                        <option value="1">1</option>
                        <option value="3" selected>3</option>
                        <option value="5">5</option>
                        <option value="10">10</option>
                      </select></label>
                    <button class="judge-start" type="button" <?php disabled($judge_running || $judge_configured_count < 2); ?>><?php echo esc_html($judge_running ? $pipeline_text('審査を実行中', 'Judging in progress', '评审进行中') : $pipeline_text('複数LLM審査を開始', 'Start multi-LLM judging', '开始多LLM评审')); ?></button>
                  </div>
                  <?php if ($judge_configured_count < 2): ?><p class="judge-setup-warning"><?php echo esc_html($pipeline_text('開始するには、API設定でLLMを2つ以上設定してください。', 'Configure at least two LLMs in API settings to start.', '请先在API设置中配置至少两个LLM。')); ?></p><?php endif; ?>
                  <p class="judge-feedback" role="status"><?php
                                                          if ($judge_running) echo esc_html(sprintf($pipeline_text('処理中: %1$d / %2$dモデル完了%3$s', 'Running: %1$d / %2$d models complete%3$s', '处理中：已完成 %1$d / %2$d 个模型%3$s'), count($multi_judge['completed_providers'] ?? []), count($multi_judge['providers'] ?? []), !empty($multi_judge['current_provider']) ? ' · ' . $multi_judge['current_provider'] : ''));
                                                          ?></p>
                </div>

                <?php if (!empty($multi_judge['results'])): ?>
                  <details class="judge-analysis-panel">
                    <summary><span><?php echo esc_html($pipeline_text('モデル重みと評価傾向', 'Model weights and trends', '模型权重与评估趋势')); ?></span><span class="judge-chip"><?php echo esc_html(sprintf($pipeline_text('履歴 %d回', '%d runs', '%d次记录'), (int) ($judge_trends['run_count'] ?? 0))); ?></span></summary>
                    <div class="judge-analysis-body">
                      <section class="judge-weight-section">
                        <div class="analysis-heading">
                          <div><b><?php echo esc_html($pipeline_text('合議への影響度', 'Consensus influence', '共识影响度')); ?></b>
                            <p><?php echo esc_html($pipeline_text('100%を基準に、信頼するモデルの影響を調整します。APIの再実行はありません。', 'Adjust influence relative to 100%. This does not rerun any API.', '以100%为基准调整模型影响，不会重新调用API。')); ?></p>
                          </div><button class="judge-save-weights" type="button" <?php disabled(!empty($multi_judge['stale'])); ?>><?php echo esc_html($pipeline_text('重みを保存して再計算', 'Save weights and recalculate', '保存权重并重新计算')); ?></button>
                        </div>
                        <div class="judge-weight-grid">
                          <?php foreach (($multi_judge['results'] ?? []) as $provider => $result): $weight_percent = (int) round(($judge_weights[$provider] ?? 1) * 100); ?>
                            <label><span><b><?php echo esc_html($provider); ?></b><small><?php echo esc_html($result['model'] ?? ''); ?></small></span><input class="judge-weight" type="number" min="25" max="200" step="5" value="<?php echo esc_attr($weight_percent); ?>" data-provider="<?php echo esc_attr($provider); ?>"><em>%</em></label>
                          <?php endforeach; ?>
                        </div>
                        <?php if (!empty($multi_judge['stale'])): ?><p class="analysis-warning"><?php echo esc_html($pipeline_text('回答が再蒸留されています。重み変更には複数LLM審査の再実行が必要です。', 'Answers changed. Re-run multi-LLM judging before changing weights.', '回答已重新蒸馏。更改权重前需重新运行多LLM评审。')); ?></p><?php endif; ?>
                      </section>
                      <section class="judge-trend-section">
                        <div class="analysis-heading">
                          <div><b><?php echo esc_html($pipeline_text('モデル別の評価傾向', 'Per-model evaluation trends', '各模型评估趋势')); ?></b>
                            <p><?php echo esc_html($pipeline_text('現在と保存済み履歴をまとめた参考指標です。', 'Reference metrics across the current and saved runs.', '汇总当前及历史评审的参考指标。')); ?></p>
                          </div>
                        </div>
                        <div class="judge-trend-grid">
                          <?php foreach (($judge_trends['providers'] ?? []) as $trend): ?>
                            <article>
                              <div><b><?php echo esc_html($trend['provider']); ?></b><small><?php echo esc_html($trend['model'] ?? ''); ?></small></div>
                              <dl>
                                <div>
                                  <dt><?php echo esc_html($pipeline_text('平均点', 'Avg score', '平均分')); ?></dt>
                                  <dd><?php echo esc_html(number_format_i18n((float) $trend['average_score'], 1)); ?></dd>
                                </div>
                                <div>
                                  <dt><?php echo esc_html($pipeline_text('平均確信度', 'Confidence', '平均置信度')); ?></dt>
                                  <dd><?php echo esc_html(number_format_i18n((float) $trend['average_confidence'], 1)); ?>%</dd>
                                </div>
                                <div>
                                  <dt><?php echo esc_html($pipeline_text('合議一致率', 'Consensus match', '共识一致率')); ?></dt>
                                  <dd><?php echo esc_html(number_format_i18n((float) $trend['consensus_agreement'], 1)); ?>%</dd>
                                </div>
                                <div>
                                  <dt><?php echo esc_html($pipeline_text('採点変化', 'Score change', '评分变化')); ?></dt>
                                  <dd class="<?php echo (float) $trend['score_change'] > 0 ? 'trend-up' : ((float) $trend['score_change'] < 0 ? 'trend-down' : ''); ?>"><?php echo esc_html(sprintf('%+.1f', (float) $trend['score_change'])); ?></dd>
                                </div>
                              </dl>
                              <p><?php echo esc_html(sprintf($pipeline_text('%1$d回 · %2$d判定', '%1$d runs · %2$d judgments', '%1$d次 · %2$d项判断'), (int) $trend['run_count'], (int) $trend['judgment_count'])); ?></p>
                            </article>
                          <?php endforeach; ?>
                        </div>
                      </section>
                      <p class="weight-feedback" role="status"></p>
                    </div>
                  </details>
                <?php endif; ?>

                <?php if (!empty($multi_judge['errors'])): ?>
                  <div class="judge-errors"><b><?php echo esc_html($pipeline_text('モデル別エラー', 'Provider errors', '模型错误')); ?></b><?php foreach ($multi_judge['errors'] as $provider => $error): ?><p><?php echo esc_html($provider . ': ' . ($error['message'] ?? '')); ?></p><?php endforeach; ?></div>
                <?php endif; ?>

                <?php if (!empty($multi_judge['consensus']['items'])): ?>
                  <div class="judge-results">
                    <?php foreach ($multi_judge['consensus']['items'] as $consensus_item):
                      $top = $consensus_item['ranking'][0] ?? [];
                    ?>
                      <section class="judge-question">
                        <div class="judge-question-heading">
                          <label class="redistill-question-label"><input class="redistill-question" type="checkbox" value="<?php echo esc_attr($consensus_item['question_id'] ?? ''); ?>"><span><?php echo esc_html($pipeline_text('再蒸留対象', 'Re-distill', '重新蒸馏')); ?></span></label>
                          <div class="judge-question-title"><span><?php echo esc_html($pipeline_text('合議結果', 'Consensus result', '共识结果')); ?></span>
                            <h3><?php echo esc_html($consensus_item['question'] ?? $consensus_item['question_id']); ?></h3>
                          </div>
                          <span class="agreement-badge agreement-<?php echo esc_attr($consensus_item['agreement'] ?? 'split'); ?>"><?php echo esc_html(['unanimous' => $pipeline_text('全会一致', 'Unanimous', '全体一致'), 'majority' => $pipeline_text('多数決', 'Majority', '多数意见'), 'split' => $pipeline_text('意見分割', 'Split', '意见分歧')][$consensus_item['agreement'] ?? 'split']); ?></span>
                        </div>
                        <?php if ($top): ?><div class="judge-winner"><b><?php echo esc_html($pipeline_text('推奨回答', 'Recommended answer', '推荐回答')); ?></b>
                            <p><?php echo esc_html($top['answer'] ?? ''); ?></p><small><?php echo esc_html(sprintf($pipeline_text('合議 %.1f点 · 平均 %.1f点 · %d票', 'Consensus %.1f · Average %.1f · %d votes', '共识 %.1f分 · 平均 %.1f分 · %d票'), (float) ($top['consensus_score'] ?? 0), (float) ($top['average_score'] ?? 0), (int) ($top['wins'] ?? 0))); ?></small>
                          </div><?php endif; ?>
                        <div class="judge-ranking">
                          <?php foreach (($consensus_item['ranking'] ?? []) as $rank => $variant): ?>
                            <label><input class="curation-choice" type="radio" name="curation-<?php echo (int) $item->ID; ?>-<?php echo esc_attr($consensus_item['question_id'] ?? ''); ?>" value="<?php echo esc_attr($variant['variant_id'] ?? ''); ?>" data-question-id="<?php echo esc_attr($consensus_item['question_id'] ?? ''); ?>" <?php checked(($curation_items[$consensus_item['question_id']]['variant_id'] ?? ($top['variant_id'] ?? '')), $variant['variant_id'] ?? ''); ?>><b>#<?php echo (int) $rank + 1; ?></b><span><?php echo esc_html(wp_trim_words($variant['answer'] ?? '', 28, '…')); ?></span><strong><?php echo esc_html((float) ($variant['consensus_score'] ?? 0)); ?></strong></label>
                          <?php endforeach; ?>
                        </div>
                        <div class="judge-model-votes">
                          <?php foreach (($multi_judge['results'] ?? []) as $provider => $result):
                            $provider_judgment = null;
                            foreach (($result['judgments'] ?? []) as $judgment) if (($judgment['question_id'] ?? '') === ($consensus_item['question_id'] ?? '')) {
                              $provider_judgment = $judgment;
                              break;
                            }
                            if (!$provider_judgment) continue;
                          ?>
                            <details>
                              <summary><b><?php echo esc_html($provider); ?></b><span><?php echo esc_html($result['model'] ?? ''); ?></span><em><?php echo esc_html(sprintf($pipeline_text('確信 %.0f%%', 'Confidence %.0f%%', '置信度 %.0f%%'), ((float) ($provider_judgment['confidence'] ?? 0)) * 100)); ?></em></summary><?php if (!empty($provider_judgment['dissent_or_risk'])): ?><p><?php echo esc_html($provider_judgment['dissent_or_risk']); ?></p><?php endif; ?>
                            </details>
                          <?php endforeach; ?>
                        </div>
                      </section>
                    <?php endforeach; ?>
                    <div class="judge-decision-actions">
                      <div class="judge-action-block">
                        <div><b><?php echo esc_html($pipeline_text('合議回答を確定', 'Confirm consensus answers', '确认共识回答')); ?></b>
                          <p><?php echo esc_html($pipeline_text('質問ごとに選んだ回答を優先回答として保存します。元回答と履歴は保持されます。', 'Save the selected answer for each question as preferred. Source answers and history are retained.', '将每个问题所选回答保存为优先回答，并保留原回答和历史记录。')); ?></p>
                        </div>
                        <button class="judge-adopt" type="button" <?php disabled(!empty($multi_judge['stale'])); ?>><?php echo esc_html($pipeline_text('選択した回答を一括採用', 'Adopt selected answers', '批量采用所选回答')); ?></button>
                      </div>
                      <div class="judge-action-block redistill-action">
                        <div><b><?php echo esc_html($pipeline_text('選択した質問だけ再蒸留', 'Re-distill selected questions', '仅重新蒸馏所选问题')); ?></b>
                          <p><?php echo esc_html($pipeline_text('チェックした質問の回答候補だけを再生成します。未選択データは変更しません。', 'Regenerate answer variants only for checked questions. Unselected data is unchanged.', '仅重新生成勾选问题的回答候选，未选数据不会更改。')); ?></p>
                        </div>
                        <select class="redistill-provider" aria-label="<?php echo esc_attr($pipeline_text('再蒸留LLM', 'Re-distillation LLM', '再蒸馏LLM')); ?>"><?php foreach ($judge_statuses as $provider): if (!$provider['configured']) continue; ?><option value="<?php echo esc_attr($provider['provider']); ?>"><?php echo esc_html($provider['label'] . ' · ' . $provider['model']); ?></option><?php endforeach; ?></select>
                        <button class="redistill-start" type="button" <?php disabled($redistill_running || !$judge_configured_count); ?>><?php echo esc_html($redistill_running ? $pipeline_text('再蒸留を実行中', 'Re-distillation running', '再蒸馏进行中') : $pipeline_text('選択範囲を再蒸留', 'Re-distill selection', '重新蒸馏所选范围')); ?></button>
                      </div>
                      <p class="decision-feedback" role="status"><?php if ($redistill_job): echo esc_html($redistill_job['message'] ?? '');
                                                                  endif; ?></p>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </details>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="pipeline-steps">
    <p class="eyebrow">AUTOMATION FLOW</p>
    <div class="steps-list"><?php foreach (fourier_pipeline_stages() as $i => $flow_stage): ?><div><b><?php echo sprintf('%02d', $i + 1); ?></b><span><?php echo esc_html($flow_stage); ?></span></div><?php endforeach; ?></div>
    <p class="eyebrow concept-flow-label">CONCEPT FLOW</p>
    <div class="steps-list"><?php foreach (fourier_concept_pipeline_stages() as $i => $flow_stage): ?><div><b><?php echo sprintf('%02d', $i + 1); ?></b><span><?php echo esc_html($flow_stage); ?></span></div><?php endforeach; ?></div>
  </section>
</main>

<style>
  .queue-toolbar { margin: 20px 0; padding: 18px; background: #f4f7f5; border-radius: 12px; }
  .queue-toolbar p, .queue-health p { font-size: 13px; line-height: 1.65; margin: 8px 0; }
  .queue-toolbar label { display: block; font-weight: 600; }
  #queue-search { width: 100%; box-sizing: border-box; padding: 10px; margin: 8px 0 12px; border: 1px solid #80988b; border-radius: 8px; }
  .queue-filters { display: flex; flex-wrap: wrap; gap: 8px; }
  #queue-status { width: 100%; padding: 10px; margin: 8px 0 12px; border: 1px solid #80988b; border-radius: 8px; background: white; }
  .queue-pagination { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; margin-top: 18px; }
  .queue-pagination a, .queue-filters a { padding: 8px 12px; color: #244b39; text-decoration: underline; }
  .queue-filters button { border: 1px solid #80988b; background: #fff; color: #244b39; padding: 8px 12px; border-radius: 20px; cursor: pointer; }
  .queue-filters button[aria-pressed="true"] { background: #244b39; color: #fff; border-color: #244b39; }
  .queue-filters button:focus-visible, #queue-search:focus-visible { outline: 3px solid #b17e28; outline-offset: 3px; }
  .queue-health { flex-basis: 100%; grid-column: 1 / -1; min-width: 0; overflow-wrap: anywhere; }
  .queue-warning { padding: 10px 12px; background: #fff4d9; color: #704900; border-left: 3px solid #b17e28; }
  #pipeline-list .pipeline-row[hidden], #queue-no-match[hidden] { display: none !important; }
  .fourier-pipeline {
    max-width: 1080px;
    margin: 0 auto;
    padding: 64px 16px 96px;
    color: #17251f
  }

  .pipeline-hero {
    padding: 28px 0 38px
  }

  .eyebrow {
    margin: 0 0 12px;
    color: #5d806e;
    font-size: 11px;
    letter-spacing: .18em;
    font-weight: 700
  }

  .pipeline-hero h1 {
    max-width: 760px;
    margin: 0;
    font-size: clamp(32px, 6vw, 64px);
    line-height: 1.08;
    letter-spacing: -.04em
  }

  .pipeline-hero h1 span {
    white-space: nowrap
  }

  .pipeline-hero h1 .headline-break {
    display: block
  }

  .pipeline-hero p:not(.eyebrow) {
    max-width: 650px;
    color: #63736b;
    font-size: 16px;
    line-height: 1.8
  }

  .pipeline-card {
    background: #fff;
    border: 1px solid #dfe9e2;
    border-radius: 22px;
    padding: 24px;
    box-shadow: 0 12px 40px #3159440b;
    margin-bottom: 20px
  }

  .pipeline-input label,
  .concept-input label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 10px
  }

  .concept-input {
    border-color: #c9e0d0;
    background: linear-gradient(135deg, #fff, #f4faf5)
  }

  .concept-input h2 {
    margin: 0 0 8px;
    font-size: 22px
  }

  .concept-description {
    max-width: 700px;
    color: #63736b;
    font-size: 13px;
    line-height: 1.8;
    margin: 0 0 18px
  }

  .concept-source-control {
    margin-top: 16px
  }

  .concept-source-control select {
    width: 100%;
    min-width: 0;
    border: 1px solid #cfdcd3;
    border-radius: 12px;
    padding: 12px 14px;
    background: #fff;
    font-size: 14px
  }

  .concept-source-control small {
    display: block;
    margin-top: 7px;
    color: #718278;
    line-height: 1.55
  }

  .pipeline-form {
    display: flex;
    gap: 10px
  }

  .pipeline-form input,
  .pipeline-form select {
    min-width: 0;
    flex: 1;
    border: 1px solid #cfdcd3;
    border-radius: 12px;
    padding: 14px 16px;
    font-size: 15px;
    background: #fff
  }

  .pipeline-form button,
  .pipeline-review,
  .quality-reevaluate {
    border: 0;
    border-radius: 11px;
    padding: 0 18px;
    background: #1f5941;
    color: #fff;
    font-weight: 700;
    cursor: pointer
  }

  .pipeline-form button:disabled,
  .pipeline-review:disabled,
  .quality-reevaluate:disabled {
    opacity: .5;
    cursor: wait
  }

  .pipeline-feedback {
    min-height: 20px;
    color: #668071;
    font-size: 13px
  }

  .pipeline-settings {
    margin-top: 16px;
    color: #63736b;
    font-size: 13px
  }

  .pipeline-settings summary,
  .quality-panel summary {
    cursor: pointer
  }

  .pipeline-knowledge {
    margin-top: 10px;
    flex-wrap: wrap
  }

  .pipeline-knowledge small {
    width: 100%;
    color: #829087
  }

  .pipeline-heading {
    display: flex;
    align-items: center;
    justify-content: space-between
  }

  .pipeline-heading h2 {
    margin: 0 0 18px;
    font-size: 22px
  }

  .pipeline-heading>span {
    color: #6e8378;
    font-size: 13px
  }

  .pipeline-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 16px;
    align-items: center;
    padding: 17px 0;
    border-top: 1px solid #edf2ee
  }

  .pipeline-title {
    min-width: 0
  }

  .pipeline-row strong,
  .pipeline-row small {
    display: block
  }

  .pipeline-title strong,
  .pipeline-title small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap
  }

  .pipeline-row small {
    margin-top: 5px;
    color: #829087;
    font-size: 12px
  }

  .pipeline-state {
    text-align: right
  }

  .pipeline-state span {
    display: block;
    color: #1f5941;
    font-size: 13px;
    font-weight: 700
  }

  .pipeline-state .state-error,
  .pipeline-state .state-rejected {
    color: #b64d45
  }

  .pipeline-actions {
    display: flex;
    gap: 6px
  }

  .pipeline-review {
    height: 34px
  }

  .pipeline-review.secondary {
    background: #fff;
    color: #a54d45;
    border: 1px solid #e5c8c4
  }

  .pipeline-error-detail {
    grid-column: 1/-1;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid #edcbc7;
    border-radius: 12px;
    background: #fff7f6
  }

  .pipeline-error-detail p {
    flex: 1;
    margin: 0;
    color: #8f3e38;
    font-size: 13px;
    line-height: 1.55
  }

  .pipeline-retry {
    min-height: 38px;
    border: 0;
    border-radius: 10px;
    padding: 0 14px;
    background: #8f3e38;
    color: #fff;
    font-weight: 700;
    cursor: pointer
  }

  .pipeline-retry:disabled {
    opacity: .55;
    cursor: wait
  }

  .pipeline-retry-feedback {
    color: #8f3e38
  }

  .pipeline-empty {
    color: #829087;
    font-size: 14px
  }

  .quality-panel {
    grid-column: 1/-1;
    border: 1px solid #e0ebe4;
    border-radius: 14px;
    background: #f8fbf9
  }

  .quality-panel>summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    padding: 11px 13px;
    font-size: 12px;
    font-weight: 700
  }

  .quality-panel>summary::marker {
    color: #5d806e
  }

  .quality-chip {
    padding: 4px 8px;
    border-radius: 999px;
    background: #e9f0eb;
    color: #52675c;
    font-size: 11px
  }

  .quality-chip.accepted {
    background: #e1f3e7;
    color: #247044
  }

  .quality-chip.rejected {
    background: #f8e8e5;
    color: #a04b43
  }

  .quality-chip.duplicate {
    background: #f1eafb;
    color: #6e4a9c
  }

  .quality-chip.sync-required {
    background: #fff0cb;
    color: #855e0c
  }

  .quality-controls {
    display: grid;
    grid-template-columns: minmax(150px, 1.2fr) minmax(110px, .7fr) minmax(145px, .8fr) auto;
    gap: 10px;
    align-items: end;
    padding: 14px 13px;
    border-top: 1px solid #e7eee9;
    background: #fff
  }

  .quality-control label {
    display: block;
    margin-bottom: 6px;
    color: #607168;
    font-size: 11px;
    font-weight: 700
  }

  .quality-control select,
  .quality-control input {
    box-sizing: border-box;
    width: 100%;
    height: 40px;
    border: 1px solid #cfdcd3;
    border-radius: 9px;
    background: #fff;
    padding: 0 10px;
    font-size: 13px
  }

  .quality-control input:disabled {
    background: #f2f5f3;
    color: #718078
  }

  .quality-reevaluate {
    height: 40px;
    white-space: nowrap
  }

  .quality-feedback,
  .quality-evaluation-meta {
    grid-column: 1/-1;
    min-height: 0;
    margin: 0;
    color: #527565;
    font-size: 12px
  }

  .quality-evaluation-meta {
    color: #7a8981
  }

  .quality-questions {
    padding: 0 13px 13px
  }

  .quality-question {
    padding: 14px 0;
    border-top: 1px solid #e7eee9
  }

  .quality-question h3 {
    margin: 0 0 10px;
    font-size: 14px;
    line-height: 1.55
  }

  .quality-answer {
    margin-top: 8px;
    padding: 11px 12px;
    border-left: 3px solid #55a474;
    border-radius: 7px;
    background: #fff
  }

  .quality-answer.rejected {
    border-left-color: #c36a61;
    background: #fffafa
  }

  .quality-answer-meta {
    display: flex;
    gap: 10px;
    align-items: center;
    color: #607168;
    font-size: 11px
  }

  .quality-answer-meta b {
    color: #277048
  }

  .quality-answer.rejected .quality-answer-meta b {
    color: #a04b43
  }

  .quality-answer p {
    margin: 7px 0 0;
    font-size: 13px;
    line-height: 1.65
  }

  .quality-answer small {
    white-space: normal;
    color: #7a8981;
    line-height: 1.5
  }

  .pipeline-steps {
    padding-top: 24px
  }

  .concept-flow-label {
    margin-top: 22px
  }

  .steps-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px
  }

  .steps-list div {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #eef5f0;
    border-radius: 999px;
    padding: 8px 12px 8px 8px;
    font-size: 12px
  }

  .steps-list b {
    display: grid;
    place-items: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #fff;
    color: #5d806e;
    font-size: 10px
  }

  @media(max-width:760px) {
    .quality-controls {
      grid-template-columns: 1fr 1fr
    }

    .quality-control:first-child,
    .quality-reevaluate {
      grid-column: 1/-1
    }

    .quality-reevaluate {
      height: 44px
    }
  }

  @media(max-width:620px) {
    .fourier-pipeline {
      padding-top: 36px
    }

    .pipeline-hero h1 span {
      display: block
    }

    .pipeline-card {
      padding: 18px
    }

    .pipeline-form {
      display: block
    }

    .pipeline-form button {
      height: 48px;
      width: 100%;
      margin-top: 10px
    }

    .pipeline-form select {
      width: 100%;
      margin-top: 10px
    }

    .pipeline-error-detail {
      align-items: stretch;
      flex-direction: column
    }

    .pipeline-retry {
      width: 100%
    }

    .pipeline-row {
      grid-template-columns: 1fr
    }

    .pipeline-state {
      text-align: left
    }

    .pipeline-actions {
      margin-top: 2px;
      flex-wrap: wrap
    }

    .pipeline-title strong,
    .pipeline-title small {
      white-space: normal;
      overflow-wrap: anywhere
    }

    .quality-panel {
      grid-column: 1
    }

    .quality-panel>summary {
      align-items: flex-start
    }

    .quality-questions {
      padding-left: 10px;
      padding-right: 10px
    }

    .quality-controls {
      grid-template-columns: 1fr
    }

    .quality-control:first-child,
    .quality-reevaluate {
      grid-column: 1
    }
  }

  .training-value-panel {
    grid-column: 1/-1;
    border: 1px solid #d9e5dc;
    border-radius: 14px;
    background: linear-gradient(135deg, #fbfdfb, #f5faf6)
  }

  .training-value-panel>summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    padding: 11px 13px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700
  }

  .training-chip {
    padding: 4px 8px;
    border-radius: 999px;
    background: #e7efe9;
    color: #50675a;
    font-size: 11px
  }

  .training-chip.value {
    background: #dceee3;
    color: #1e6845
  }

  .training-chip.eligible {
    background: #e3eef8;
    color: #315f86
  }

  .training-chip.conflict {
    background: #f9e3df;
    color: #9a4b43
  }

  .training-value-body {
    border-top: 1px solid #e2ebe4;
    padding: 16px;
    background: #fff
  }

  .training-value-intro {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px
  }

  .training-value-intro h3 {
    margin: 0 0 6px;
    font-size: 16px
  }

  .training-value-intro p {
    max-width: 670px;
    margin: 0;
    color: #65776c;
    font-size: 11px;
    line-height: 1.65
  }

  .training-value-intro>small {
    max-width: 280px;
    margin: 0;
    padding: 8px 10px;
    border-radius: 8px;
    background: #fff5db;
    color: #7a5d1d;
    font-size: 10px;
    line-height: 1.5;
    white-space: normal
  }

  .training-metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 9px;
    margin-top: 14px
  }

  .training-metric-grid>div {
    padding: 12px;
    border: 1px solid #e1e9e3;
    border-radius: 10px;
    background: #f8faf8
  }

  .training-metric-grid span,
  .training-metric-grid strong {
    display: block
  }

  .training-metric-grid span {
    color: #75837b;
    font-size: 10px
  }

  .training-metric-grid strong {
    margin-top: 5px;
    color: #244836;
    font-size: 20px
  }

  .coverage-gap-section {
    margin-top: 16px
  }

  .coverage-gap-heading h4 {
    margin: 0;
    font-size: 13px
  }

  .coverage-gap-heading p {
    margin: 4px 0 0;
    color: #75837b;
    font-size: 10px;
    line-height: 1.55
  }

  .coverage-gap-list {
    margin-top: 9px;
    border: 1px solid #e3ebe5;
    border-radius: 10px;
    overflow: hidden
  }

  .coverage-gap-list article {
    display: grid;
    grid-template-columns: minmax(150px, 1fr) minmax(120px, 1.5fr) 42px;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    background: #fff
  }

  .coverage-gap-list article+article {
    border-top: 1px solid #edf1ee
  }

  .coverage-gap-list b,
  .coverage-gap-list span {
    display: block
  }

  .coverage-gap-list b {
    font-size: 11px
  }

  .coverage-gap-list span {
    margin-top: 3px;
    color: #7a8880;
    font-size: 9px
  }

  .coverage-gap-list>article>strong {
    text-align: right;
    font-size: 11px
  }

  .coverage-gap-choice {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    cursor: pointer
  }

  .coverage-gap-choice input {
    width: 16px;
    height: 16px;
    margin: 1px 0 0;
    accent-color: #357054
  }

  .coverage-gap-choice span {
    min-width: 0;
    margin: 0
  }

  .coverage-gap-choice small {
    display: block;
    margin-top: 3px;
    color: #7a8880;
    font-size: 9px
  }

  .coverage-meter {
    height: 7px;
    border-radius: 999px;
    background: #e9efeb;
    overflow: hidden
  }

  .coverage-meter i {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #75ad89, #357054)
  }

  .question-expansion {
    margin-top: 11px;
    padding: 12px;
    border: 1px solid #dbe8df;
    border-radius: 11px;
    background: #f8fbf9
  }

  .question-expansion-controls {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
    gap: 10px
  }

  .question-expansion-controls label {
    color: #607168;
    font-size: 10px;
    font-weight: 700
  }

  .question-expansion-controls select {
    display: block;
    width: 100%;
    min-width: 0;
    margin-top: 5px;
    padding: 9px 10px;
    border: 1px solid #cfdcd3;
    border-radius: 9px;
    background: #fff
  }

  .question-expansion-start,
  .question-expansion-apply {
    min-height: 38px;
    padding: 0 14px;
    border: 0;
    border-radius: 9px;
    background: #286247;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer
  }

  .question-expansion-start:disabled,
  .question-expansion-apply:disabled {
    opacity: .55;
    cursor: wait
  }

  .question-expansion-feedback {
    min-height: 16px;
    margin: 8px 0 0;
    color: #607168;
    font-size: 10px
  }

  .question-diff-preview {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #dfe8e2
  }

  .question-diff-heading {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
    color: #52695d;
    font-size: 10px
  }

  .question-diff-item {
    display: flex;
    gap: 9px;
    padding: 9px;
    border: 1px solid #e0e9e3;
    border-radius: 9px;
    background: #fff;
    cursor: pointer
  }

  .question-diff-item+.question-diff-item {
    margin-top: 6px
  }

  .question-diff-item.is-duplicate {
    background: #f3f4f3;
    opacity: .72;
    cursor: default
  }

  .question-diff-item input {
    width: 16px;
    height: 16px;
    margin: 1px 0 0;
    accent-color: #357054
  }

  .question-diff-item>span {
    min-width: 0
  }

  .question-diff-item b,
  .question-diff-item small,
  .question-diff-item em {
    display: block
  }

  .question-diff-item b {
    color: #304a3c;
    font-size: 11px;
    line-height: 1.55
  }

  .question-diff-item small {
    margin-top: 3px;
    color: #65796d;
    font-size: 9px
  }

  .question-diff-item em {
    margin-top: 4px;
    color: #8a746b;
    font-size: 9px;
    font-style: normal;
    line-height: 1.45
  }

  .question-expansion-apply {
    width: 100%;
    margin-top: 10px
  }

  .training-provenance {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 13px
  }

  .training-provenance span {
    padding: 5px 7px;
    border-radius: 7px;
    background: #f1f4f2;
    color: #6d7b73;
    font-size: 9px;
    overflow-wrap: anywhere
  }

  @media(max-width:700px) {
    .training-value-intro {
      display: block
    }

    .training-value-intro>small {
      display: block;
      max-width: none;
      margin-top: 10px
    }

    .training-metric-grid {
      grid-template-columns: 1fr 1fr
    }

    .coverage-gap-list article {
      grid-template-columns: minmax(0, 1fr) 42px
    }

    .coverage-meter {
      grid-column: 1/-1;
      grid-row: 2
    }

    .coverage-gap-list>article>strong {
      grid-column: 2;
      grid-row: 1
    }

    .training-value-body {
      padding: 13px
    }

    .question-expansion-controls {
      grid-template-columns: 1fr
    }

    .question-expansion-start {
      width: 100%
    }

    .question-diff-heading {
      display: block
    }

    .question-diff-heading span {
      display: block;
      margin-top: 4px
    }
  }

  @media(max-width:420px) {
    .training-metric-grid {
      grid-template-columns: 1fr
    }

    .training-value-panel {
      grid-column: 1
    }

    .training-value-panel>summary {
      align-items: flex-start
    }
  }

  .concept-graph-panel {
    grid-column: 1/-1;
    border: 1px solid #dce8e1;
    border-radius: 14px;
    background: #f8fbf9
  }

  .concept-graph-panel>summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    padding: 11px 13px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700
  }

  .graph-chip {
    padding: 4px 8px;
    border-radius: 999px;
    background: #e8f0eb;
    color: #52675c;
    font-size: 11px
  }

  .graph-chip.refresh-required {
    background: #fff0cb;
    color: #855e0c
  }

  .concept-graph-workspace {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(280px, .75fr);
    border-top: 1px solid #e3ebe6;
    background: #fff
  }

  .concept-graph-view {
    min-width: 0;
    padding: 16px;
    border-right: 1px solid #e3ebe6
  }

  .graph-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px
  }

  .graph-toolbar p {
    margin: 0;
    color: #75847c;
    font-size: 12px
  }

  .graph-add-branch,
  .graph-cancel-edit {
    border: 1px solid #cddbd2;
    border-radius: 9px;
    background: #fff;
    color: #2d614b;
    padding: 8px 11px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700
  }

  .graph-root-node {
    position: relative;
    max-width: 360px;
    margin: 22px auto 0;
    padding: 16px;
    border: 1px solid #bcd8c7;
    border-radius: 15px;
    background: linear-gradient(135deg, #f5fbf7, #eaf5ee);
    text-align: center
  }

  .graph-root-node span {
    display: block;
    color: #64806f;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .12em
  }

  .graph-root-node strong {
    display: block;
    margin-top: 4px;
    font-size: 18px
  }

  .graph-root-node small {
    display: block;
    margin-top: 7px;
    color: #6f7e76;
    font-size: 11px;
    line-height: 1.5
  }

  .graph-trunk {
    width: 1px;
    height: 24px;
    margin: 0 auto;
    background: #b8cfc1
  }

  .graph-branches {
    position: relative;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    padding-top: 14px
  }

  .graph-branches::before {
    content: "";
    position: absolute;
    top: 0;
    left: 25%;
    right: 25%;
    height: 1px;
    background: #b8cfc1
  }

  .graph-branch-node {
    position: relative;
    min-width: 0;
    border: 1px solid #d7e4dc;
    border-radius: 12px;
    background: #fff;
    padding: 13px;
    text-align: left;
    cursor: pointer
  }

  .graph-branch-node::before {
    content: "";
    position: absolute;
    top: -15px;
    left: 50%;
    width: 1px;
    height: 14px;
    background: #b8cfc1
  }

  .graph-branch-node:hover,
  .graph-branch-node.is-selected {
    border-color: #4c9270;
    box-shadow: 0 0 0 2px #4c92701c
  }

  .graph-branch-node.is-disabled {
    opacity: .55;
    background: #f5f6f5
  }

  .graph-branch-node strong,
  .graph-branch-node small {
    display: block;
    white-space: normal
  }

  .graph-branch-node strong {
    padding-right: 38px;
    font-size: 13px;
    line-height: 1.4
  }

  .graph-branch-node small {
    margin-top: 7px;
    color: #78877f;
    font-size: 10px
  }

  .graph-branch-node em {
    display: inline-block;
    margin-top: 7px;
    color: #9b554e;
    font-size: 10px;
    font-style: normal;
    font-weight: 700
  }

  .graph-branch-priority {
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 3px 6px;
    border-radius: 999px;
    background: #edf4ef;
    color: #47715d;
    font-size: 9px;
    font-weight: 700
  }

  .graph-editor {
    padding: 16px
  }

  .graph-editor-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px
  }

  .graph-editor-heading span {
    color: #75847c;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .1em
  }

  .graph-editor-heading h3 {
    margin: 3px 0 0;
    font-size: 17px
  }

  .graph-editor label {
    display: block;
    margin-top: 11px;
    color: #52645b;
    font-size: 11px;
    font-weight: 700
  }

  .graph-editor input[type=text],
  .graph-editor textarea,
  .graph-editor select {
    box-sizing: border-box;
    width: 100%;
    margin-top: 6px;
    border: 1px solid #cfdcd3;
    border-radius: 9px;
    background: #fff;
    padding: 10px;
    font: inherit;
    color: #263b31
  }

  .graph-editor textarea {
    resize: vertical;
    line-height: 1.5
  }

  .graph-editor-row {
    display: grid;
    grid-template-columns: 90px 1fr;
    gap: 14px;
    align-items: end
  }

  .graph-enabled-label {
    display: flex !important;
    align-items: center;
    gap: 7px;
    padding-bottom: 11px
  }

  .graph-enabled-label input {
    margin: 0
  }

  .graph-save-branch {
    width: 100%;
    height: 42px;
    margin-top: 15px;
    border: 0;
    border-radius: 10px;
    background: #1f5941;
    color: #fff;
    cursor: pointer;
    font-weight: 700
  }

  .graph-save-branch:disabled {
    opacity: .5;
    cursor: wait
  }

  .graph-feedback {
    min-height: 18px;
    margin: 8px 0 0;
    color: #527565;
    font-size: 11px
  }

  .graph-editor.is-new .graph-editor-heading span {
    color: #2b7956
  }

  @media(max-width:820px) {
    .concept-graph-workspace {
      grid-template-columns: 1fr
    }

    .concept-graph-view {
      border-right: 0;
      border-bottom: 1px solid #e3ebe6
    }
  }

  @media(max-width:620px) {
    .graph-branches {
      grid-template-columns: 1fr
    }

    .graph-branches::before {
      left: 50%;
      right: auto;
      height: 100%;
      width: 1px
    }

    .graph-branch-node::before {
      top: 50%;
      left: -15px;
      width: 14px;
      height: 1px
    }

    .graph-branch-node {
      margin-left: 14px
    }

    .graph-toolbar {
      align-items: flex-start
    }

    .graph-root-node {
      margin-top: 18px
    }

    .concept-graph-view,
    .graph-editor {
      padding: 12px
    }
  }

  .multi-judge-panel {
    grid-column: 1/-1;
    border: 1px solid #d8e3ec;
    border-radius: 14px;
    background: #f8fafc
  }

  .multi-judge-panel>summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    padding: 11px 13px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700
  }

  .judge-chip {
    padding: 4px 8px;
    border-radius: 999px;
    background: #e9eef3;
    color: #536575;
    font-size: 11px
  }

  .judge-chip.status-running,
  .judge-chip.status-queued {
    background: #fff0cb;
    color: #855e0c
  }

  .judge-chip.status-completed {
    background: #e1f3e7;
    color: #247044
  }

  .judge-chip.status-partial,
  .judge-chip.status-error {
    background: #f8e8e5;
    color: #a04b43
  }

  .judge-chip.agreement {
    background: #eee9f8;
    color: #65498f
  }

  .multi-judge-body {
    border-top: 1px solid #e2e9ef;
    background: #fff
  }

  .judge-intro {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    padding: 16px
  }

  .judge-intro h3 {
    margin: 0 0 7px;
    font-size: 16px
  }

  .judge-intro p {
    max-width: 720px;
    margin: 0;
    color: #677883;
    font-size: 12px;
    line-height: 1.65
  }

  .judge-cost-note {
    flex: 0 0 auto;
    padding: 6px 9px;
    border-radius: 8px;
    background: #fff4dc;
    color: #805c18;
    font-size: 10px
  }

  .judge-controls {
    padding: 0 16px 16px
  }

  .judge-provider-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px
  }

  .judge-provider {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    padding: 10px;
    border: 1px solid #dce5eb;
    border-radius: 10px;
    cursor: pointer
  }

  .judge-provider input {
    margin: 0
  }

  .judge-provider span {
    min-width: 0;
    flex: 1
  }

  .judge-provider b,
  .judge-provider small {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap
  }

  .judge-provider b {
    font-size: 12px
  }

  .judge-provider small {
    margin-top: 3px;
    color: #778792;
    font-size: 9px
  }

  .judge-provider em {
    color: #317353;
    font-size: 9px;
    font-style: normal
  }

  .judge-provider.is-unavailable {
    opacity: .55;
    cursor: not-allowed
  }

  .judge-provider.is-unavailable em {
    color: #9a5d57
  }

  .judge-run-row {
    display: flex;
    align-items: end;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 12px
  }

  .judge-run-row label {
    color: #607168;
    font-size: 10px;
    font-weight: 700
  }

  .judge-run-row select {
    display: block;
    height: 38px;
    margin-top: 5px;
    border: 1px solid #cfdce4;
    border-radius: 9px;
    background: #fff;
    padding: 0 28px 0 10px
  }

  .judge-start {
    height: 38px;
    border: 0;
    border-radius: 9px;
    background: #263f62;
    color: #fff;
    padding: 0 15px;
    cursor: pointer;
    font-weight: 700
  }

  .judge-start:disabled {
    opacity: .5;
    cursor: wait
  }

  .judge-feedback,
  .judge-setup-warning {
    min-height: 18px;
    margin: 9px 0 0;
    color: #4d6f85;
    font-size: 11px
  }

  .judge-setup-warning {
    color: #9a5d3b
  }

  .judge-errors {
    margin: 0 16px 14px;
    padding: 11px;
    border-radius: 9px;
    background: #fff2f0;
    color: #934c45;
    font-size: 11px
  }

  .judge-errors p {
    margin: 5px 0 0
  }

  .judge-results {
    border-top: 1px solid #e7edf1
  }

  .judge-question {
    padding: 18px 16px;
    border-bottom: 1px solid #e7edf1
  }

  .judge-question:last-child {
    border-bottom: 0
  }

  .judge-question-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px
  }

  .judge-question-heading span {
    color: #72838f;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .12em
  }

  .judge-question-heading h3 {
    margin: 4px 0 0;
    font-size: 15px;
    line-height: 1.5
  }

  .agreement-badge {
    flex: 0 0 auto;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 10px !important;
    letter-spacing: 0 !important
  }

  .agreement-unanimous {
    background: #ddf2e4;
    color: #257047 !important
  }

  .agreement-majority {
    background: #e7eef7;
    color: #3d6389 !important
  }

  .agreement-split {
    background: #f8e5e2;
    color: #9a5048 !important
  }

  .judge-winner {
    margin-top: 12px;
    padding: 13px;
    border-left: 3px solid #4c799f;
    border-radius: 8px;
    background: #f5f8fb
  }

  .judge-winner b {
    font-size: 10px;
    color: #426a8c
  }

  .judge-winner p {
    margin: 7px 0;
    font-size: 13px;
    line-height: 1.65
  }

  .judge-winner small {
    color: #687d8d
  }

  .judge-ranking {
    margin-top: 10px
  }

  .judge-ranking>div {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr) 48px;
    gap: 8px;
    align-items: center;
    padding: 8px 0;
    border-top: 1px solid #edf1f4;
    font-size: 11px
  }

  .judge-ranking b {
    color: #4f6f88
  }

  .judge-ranking span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #61717c
  }

  .judge-ranking strong {
    text-align: right;
    color: #253f57
  }

  .judge-model-votes {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 10px
  }

  .judge-model-votes details {
    min-width: 180px;
    border: 1px solid #e1e8ed;
    border-radius: 8px;
    background: #fff;
    padding: 7px 9px
  }

  .judge-model-votes summary {
    display: flex;
    align-items: center;
    gap: 7px;
    cursor: pointer;
    font-size: 10px
  }

  .judge-model-votes summary b {
    color: #344f65
  }

  .judge-model-votes summary span {
    max-width: 130px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #74838e
  }

  .judge-model-votes summary em {
    margin-left: auto;
    color: #4e7561;
    font-style: normal
  }

  .judge-model-votes p {
    margin: 8px 0 0;
    color: #697983;
    font-size: 10px;
    line-height: 1.5
  }

  @media(max-width:820px) {
    .judge-provider-grid {
      grid-template-columns: 1fr 1fr
    }
  }

  @media(max-width:620px) {
    .judge-intro {
      display: block;
      padding: 13px
    }

    .judge-cost-note {
      display: inline-block;
      margin-top: 10px
    }

    .judge-controls {
      padding: 0 13px 13px
    }

    .judge-provider-grid {
      grid-template-columns: 1fr
    }

    .judge-run-row {
      align-items: stretch;
      flex-direction: column
    }

    .judge-start {
      height: 44px;
      width: 100%
    }

    .judge-question {
      padding: 15px 13px
    }

    .judge-question-heading {
      display: block
    }

    .agreement-badge {
      display: inline-block;
      margin-top: 8px
    }

    .judge-ranking>div {
      grid-template-columns: 28px minmax(0, 1fr) 42px
    }

    .judge-model-votes {
      display: block
    }

    .judge-model-votes details {
      box-sizing: border-box;
      width: 100%;
      margin-top: 7px
    }
  }

  .judge-chip.stale {
    background: #ffe6d8;
    color: #96512e
  }

  .judge-chip.adopted {
    background: #dff1ea;
    color: #246a51
  }

  .judge-question-title {
    min-width: 0;
    flex: 1
  }

  .redistill-question-label {
    display: flex;
    align-items: center;
    gap: 5px;
    flex: 0 0 auto;
    padding: 5px 7px;
    border: 1px solid #dce5eb;
    border-radius: 7px;
    cursor: pointer
  }

  .redistill-question-label input {
    margin: 0
  }

  .redistill-question-label span {
    letter-spacing: 0 !important
  }

  .judge-ranking>label {
    display: grid;
    grid-template-columns: 18px 32px minmax(0, 1fr) 48px;
    gap: 8px;
    align-items: center;
    padding: 8px 0;
    border-top: 1px solid #edf1f4;
    font-size: 11px;
    cursor: pointer
  }

  .judge-ranking>label input {
    margin: 0
  }

  .judge-decision-actions {
    padding: 16px;
    background: #f7f9fb
  }

  .judge-action-block {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 14px;
    border: 1px solid #dce5eb;
    border-radius: 11px;
    background: #fff
  }

  .judge-action-block+.judge-action-block {
    margin-top: 9px
  }

  .judge-action-block b {
    font-size: 12px
  }

  .judge-action-block p {
    margin: 5px 0 0;
    color: #6d7d88;
    font-size: 10px;
    line-height: 1.55
  }

  .judge-action-block button {
    height: 39px;
    border: 0;
    border-radius: 9px;
    background: #315f50;
    color: #fff;
    padding: 0 14px;
    cursor: pointer;
    font-weight: 700
  }

  .judge-action-block button:disabled {
    opacity: .5;
    cursor: wait
  }

  .redistill-action {
    grid-template-columns: minmax(0, 1fr) minmax(190px, auto) auto
  }

  .redistill-action select {
    height: 39px;
    max-width: 260px;
    border: 1px solid #cfdae2;
    border-radius: 9px;
    background: #fff;
    padding: 0 9px
  }

  .redistill-action button {
    background: #775632
  }

  .decision-feedback {
    min-height: 16px;
    margin: 9px 2px 0;
    color: #4d6f85;
    font-size: 11px
  }

  @media(max-width:700px) {
    .judge-question-heading {
      display: grid;
      grid-template-columns: auto 1fr
    }

    .agreement-badge {
      grid-column: 2;
      margin-top: 0
    }

    .judge-ranking>label {
      grid-template-columns: 16px 25px minmax(0, 1fr) 38px
    }

    .judge-action-block,
    .redistill-action {
      grid-template-columns: 1fr
    }

    .judge-action-block button,
    .redistill-action select {
      width: 100%;
      max-width: none;
      height: 44px
    }

    .judge-decision-actions {
      padding: 12px
    }
  }

  .judge-analysis-panel {
    margin: 0 16px 16px;
    border: 1px solid #dbe5ec;
    border-radius: 11px;
    background: #fbfcfd
  }

  .judge-analysis-panel>summary {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 11px 12px;
    cursor: pointer;
    font-size: 11px;
    font-weight: 700
  }

  .judge-analysis-body {
    border-top: 1px solid #e3eaf0;
    padding: 14px
  }

  .analysis-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px
  }

  .analysis-heading b {
    font-size: 12px
  }

  .analysis-heading p {
    margin: 4px 0 0;
    color: #70808b;
    font-size: 10px;
    line-height: 1.5
  }

  .judge-save-weights {
    height: 37px;
    border: 0;
    border-radius: 8px;
    background: #425f82;
    color: #fff;
    padding: 0 13px;
    cursor: pointer;
    font-weight: 700;
    white-space: nowrap
  }

  .judge-save-weights:disabled {
    opacity: .5;
    cursor: wait
  }

  .judge-weight-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    margin-top: 11px
  }

  .judge-weight-grid label {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 64px 12px;
    align-items: center;
    gap: 6px;
    padding: 9px;
    border: 1px solid #dde6ec;
    border-radius: 9px;
    background: #fff
  }

  .judge-weight-grid span {
    min-width: 0
  }

  .judge-weight-grid b,
  .judge-weight-grid small {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap
  }

  .judge-weight-grid b {
    font-size: 11px
  }

  .judge-weight-grid small {
    margin-top: 2px;
    color: #84919a;
    font-size: 8px
  }

  .judge-weight-grid input {
    box-sizing: border-box;
    width: 64px;
    height: 34px;
    border: 1px solid #ccd9e1;
    border-radius: 7px;
    padding: 0 6px
  }

  .judge-weight-grid em {
    color: #6b7c87;
    font-size: 10px;
    font-style: normal
  }

  .judge-trend-section {
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid #e5ebef
  }

  .judge-trend-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin-top: 10px
  }

  .judge-trend-grid article {
    padding: 11px;
    border: 1px solid #dde6ec;
    border-radius: 9px;
    background: #fff
  }

  .judge-trend-grid article>div>b,
  .judge-trend-grid article>div>small {
    display: block
  }

  .judge-trend-grid article>div>b {
    font-size: 11px
  }

  .judge-trend-grid article>div>small {
    margin-top: 2px;
    color: #84919a;
    font-size: 8px
  }

  .judge-trend-grid dl {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 5px;
    margin: 10px 0 0
  }

  .judge-trend-grid dl>div {
    padding: 7px;
    border-radius: 7px;
    background: #f3f6f8
  }

  .judge-trend-grid dt {
    color: #71818c;
    font-size: 8px
  }

  .judge-trend-grid dd {
    margin: 4px 0 0;
    color: #2d475c;
    font-size: 13px;
    font-weight: 700
  }

  .judge-trend-grid article>p {
    margin: 8px 0 0;
    color: #7b8992;
    font-size: 9px
  }

  .trend-up {
    color: #29704b !important
  }

  .trend-down {
    color: #a24f47 !important
  }

  .analysis-warning {
    margin: 9px 0 0;
    color: #9a5d3b;
    font-size: 10px
  }

  .weight-feedback {
    min-height: 15px;
    margin: 9px 0 0;
    color: #4d6f85;
    font-size: 10px
  }

  @media(max-width:820px) {
    .judge-weight-grid {
      grid-template-columns: 1fr 1fr
    }
  }

  @media(max-width:620px) {
    .judge-analysis-panel {
      margin: 0 12px 12px
    }

    .judge-analysis-body {
      padding: 11px
    }

    .analysis-heading {
      display: block
    }

    .judge-save-weights {
      width: 100%;
      height: 44px;
      margin-top: 10px
    }

    .judge-weight-grid,
    .judge-trend-grid {
      grid-template-columns: 1fr
    }

    .judge-trend-grid dl {
      grid-template-columns: 1fr 1fr
    }

    .judge-weight-grid label {
      grid-template-columns: minmax(0, 1fr) 72px 12px
    }

    .judge-weight-grid input {
      width: 72px
    }
  }
</style>
<script>
  (() => {
    const root = document.querySelector('.fourier-pipeline');
    const rows = Array.from(root.querySelectorAll('.pipeline-row'));
    const button = document.querySelector('#queue-refresh');
    const auto = document.querySelector('#queue-auto-refresh');
    const feedback = document.querySelector('#queue-refresh-feedback');
    const statuses = <?php echo wp_json_encode($queue_labels); ?>;
    const stages = <?php echo wp_json_encode(array_map(static function ($labels) use ($pipeline_text) { return $pipeline_text(...$labels); }, $stage_labels)); ?>;
    const labels = <?php echo wp_json_encode([
      'loading' => $pipeline_text('状態を確認しています…', 'Checking status…', '正在检查状态…'),
      'checked' => $pipeline_text('状態確認完了: ', 'Status checked: ', '状态检查完成：'),
      'failed' => $pipeline_text('状態確認に失敗しました。表示は前回のままです。再試行してください。', 'Status check failed. Previous values remain; please retry.', '状态检查失败，保留上次显示，请重试。'),
      'changed' => $pipeline_text('状態が変わったため操作を無効化しました。未保存の編集内容を手元に控えてから再読み込みしてください。自動保存はされません。', 'State changed; actions are disabled. Copy unsaved edits before reloading. Edits are not automatically saved.', '状态已变化，操作已禁用。请复制未保存的编辑后再重新加载，系统不会自动保存。'),
      'missing' => $pipeline_text('取得できない項目があります。削除や権限変更の可能性があります。', 'Some records are unavailable; they may have been removed or access changed.', '部分记录无法获取，可能已删除或权限已变化。'),
      'updated' => $pipeline_text('最終状態更新: ', 'Last status update: ', '最近状态更新：'),
      'minutes' => $pipeline_text('分経過', 'min elapsed', '分钟已过'),
      'warning' => $pipeline_text('5分以上未更新です。モデル・Cron・通信を確認してください。失敗とは断定できません。', 'No update for 5+ minutes. Check the model, Cron and network; failure is not confirmed.', '超过5分钟未更新，请检查模型、Cron和网络，尚不能判定失败。'),
    ], JSON_UNESCAPED_UNICODE); ?>;
    let busy = false;
    async function refresh() {
      if (busy) return;
      busy = true; button.disabled = true; feedback.textContent = labels.loading;
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 15000);
      try {
        const form = new FormData();
        form.append('action', 'fourier_pipeline_queue_snapshot'); form.append('nonce', root.dataset.nonce);
        rows.forEach(row => form.append('ids[]', row.dataset.id));
        const response = await fetch(root.dataset.ajax, {method:'POST', body:form, signal:controller.signal});
        const payload = await response.json();
        if (!response.ok || !payload.success || !Array.isArray(payload.data.items)) throw new Error('Invalid response');
        const items = new Map(payload.data.items.map(item => [String(item.id), item]));
        rows.forEach(row => {
          const item = items.get(row.dataset.id);
          if (!item) { row.querySelectorAll('button').forEach(b => b.disabled = true); return; }
          const state = row.querySelector('.pipeline-state');
          const changed = row.dataset.status !== item.status || state.querySelector('span').textContent !== (stages[item.stage] || item.stage);
          state.querySelector('span').textContent = stages[item.stage] || item.stage;
          state.querySelector('span').className = 'state-' + item.status;
          state.querySelector('small').textContent = statuses[item.status] || item.status;
          row.dataset.status = item.status;
          const health = row.querySelector('.queue-health');
          health.querySelector('small').textContent = '#' + item.id + ' · ' + labels.updated + (item.updated || '—') + (item.age_minutes === null ? '' : ' · ' + item.age_minutes + ' ' + labels.minutes);
          if (changed) {
            health.querySelectorAll('p').forEach(p => p.remove());
            const note = document.createElement('p'); note.textContent = labels.changed; health.append(note);
            row.querySelectorAll('button').forEach(b => b.disabled = true);
          }
          let warning = health.querySelector('.queue-warning');
          const stale = ['queued','processing'].includes(item.status) && item.age_minutes >= 5;
          if (stale && !warning) { warning = document.createElement('p'); warning.className = 'queue-warning'; health.append(warning); }
          if (warning) { warning.hidden = !stale; warning.textContent = labels.warning; }
        });
        feedback.textContent = labels.checked + payload.data.checked_at + (items.size < rows.length ? ' · ' + labels.missing : '');
      } catch (error) { feedback.textContent = labels.failed; }
      finally { clearTimeout(timeout); busy = false; button.disabled = false; }
    }
    button.addEventListener('click', refresh);
    setInterval(() => { if (auto.checked && !document.hidden) refresh(); }, 30000);
  })();
  (() => {
    let dirty = false;
    document.querySelector('#pipeline-list').addEventListener('input', event => {
      if (event.target.matches('input, textarea, select')) dirty = true;
    });
    window.addEventListener('beforeunload', event => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });
  })();
  (() => {
    const root = document.querySelector('.fourier-pipeline');
    if (!root) return;
    const ajax = root.dataset.ajax,
      nonce = root.dataset.nonce,
      text = <?php echo wp_json_encode($js_text, JSON_UNESCAPED_UNICODE); ?>,
      feedback = document.querySelector('#pipeline-feedback');
    const post = (action, extra = {}) => {
      const form = new FormData();
      form.append('action', action);
      form.append('nonce', nonce);
      Object.entries(extra).forEach(([key, value]) => form.append(key, value));
      return fetch(ajax, {
        method: 'POST',
        body: form
      }).then(response => response.json())
    };
    const knowledge = provider => ({
      provider,
      knowledge_url: document.querySelector('#pipeline-knowledge-url').value,
      knowledge_token: document.querySelector('#pipeline-knowledge-token').value
    });
    const sourceSelect = document.querySelector('#concept-source-post');
    document.querySelector('#pipeline-url').addEventListener('change', event => {
      const enteredUrl = event.target.value.trim();
      const match = Array.from(sourceSelect.options).find(option => option.dataset.url === enteredUrl);
      if (match) sourceSelect.value = match.value
    });
    document.querySelector('#pipeline-start').onclick = () => {
      const url = document.querySelector('#pipeline-url').value.trim(),
        button = document.querySelector('#pipeline-start');
      if (!url) {
        feedback.textContent = text.urlRequired;
        return
      }
      button.disabled = true;
      feedback.textContent = text.queueing;
      post('fourier_pipeline_start', Object.assign({
        url
      }, knowledge(document.querySelector('#pipeline-provider').value))).then(result => {
        feedback.textContent = result.success ? text.started : (result.data?.message || text.startFailed);
        if (result.success) setTimeout(() => location.reload(), 1800);
        button.disabled = false
      }).catch(() => {
        feedback.textContent = text.networkError;
        button.disabled = false
      })
    };
    document.querySelector('#concept-start').onclick = () => {
      const concept = document.querySelector('#pipeline-concept').value.trim(),
        button = document.querySelector('#concept-start'),
        conceptFeedback = document.querySelector('#concept-feedback');
      if (!concept) {
        conceptFeedback.textContent = text.conceptRequired;
        return
      }
      button.disabled = true;
      conceptFeedback.textContent = text.mapping;
      post('fourier_concept_pipeline_start', Object.assign({
        concept,
        source_post_id: sourceSelect.value
      }, knowledge(document.querySelector('#concept-provider').value))).then(result => {
        conceptFeedback.textContent = result.success ? text.conceptStarted : (result.data?.message || text.startFailed);
        if (result.success) setTimeout(() => location.reload(), 1800);
        button.disabled = false
      }).catch(() => {
        conceptFeedback.textContent = text.networkError;
        button.disabled = false
      })
    };
    document.querySelectorAll('.pipeline-retry').forEach(button => button.onclick = () => {
      const row = button.closest('.pipeline-row'),
        status = row.querySelector('.pipeline-retry-feedback'),
        isConcept = row.dataset.kind === 'concept',
        provider = document.querySelector(isConcept ? '#concept-provider' : '#pipeline-provider').value,
        selectedSource = sourceSelect.value || row.dataset.sourcePostId || '';
      button.disabled = true;
      status.textContent = text.retrying;
      post('fourier_pipeline_retry', {
        post_id: row.dataset.id,
        provider,
        source_post_id: isConcept ? selectedSource : ''
      }).then(result => {
        status.textContent = result.success ? text.retried : (result.data?.message || text.startFailed);
        if (result.success) setTimeout(() => location.reload(), 1200);
        else button.disabled = false
      }).catch(() => {
        status.textContent = text.networkError;
        button.disabled = false
      })
    });
    document.querySelectorAll('.pipeline-review').forEach(button => button.onclick = () => {
      const row = button.closest('.pipeline-row');
      button.disabled = true;
      post('fourier_pipeline_review', {
        post_id: row.dataset.id,
        decision: button.dataset.decision
      }).then(result => {
        if (result.success) location.reload();
        else {
          alert(result.data?.message || text.saveFailed);
          button.disabled = false
        }
      }).catch(() => {
        alert(text.networkError);
        button.disabled = false
      })
    });
    document.querySelectorAll('.quality-controls').forEach(controls => {
      const profile = controls.querySelector('.quality-profile'),
        minimum = controls.querySelector('.quality-minimum'),
        duplicate = controls.querySelector('.quality-duplicate'),
        button = controls.querySelector('.quality-reevaluate'),
        status = controls.querySelector('.quality-feedback'),
        row = controls.closest('.pipeline-row');
      const syncInputs = () => {
        const option = profile.selectedOptions[0],
          custom = profile.value === 'custom';
        minimum.disabled = !custom;
        duplicate.disabled = !custom;
        if (!custom) {
          minimum.value = option.dataset.minimum;
          duplicate.value = option.dataset.duplicate
        }
      };
      profile.addEventListener('change', syncInputs);
      syncInputs();
      button.addEventListener('click', () => {
        button.disabled = true;
        profile.disabled = true;
        minimum.disabled = true;
        duplicate.disabled = true;
        status.textContent = text.reevaluating;
        post('fourier_concept_pipeline_reevaluate', {
          post_id: row.dataset.id,
          profile: profile.value,
          minimum_score: minimum.value,
          duplicate_similarity: duplicate.value
        }).then(result => {
          if (result.success) {
            status.textContent = text.reevaluated;
            setTimeout(() => location.reload(), 700)
          } else {
            status.textContent = result.data?.message || text.saveFailed;
            button.disabled = false;
            profile.disabled = false;
            syncInputs()
          }
        }).catch(() => {
          status.textContent = text.networkError;
          button.disabled = false;
          profile.disabled = false;
          syncInputs()
        })
      })
    })
  })();
</script>
<script>
  (() => {
    const root = document.querySelector('.fourier-pipeline');
    if (!root) return;
    const ajax = root.dataset.ajax,
      nonce = root.dataset.nonce,
      text = <?php echo wp_json_encode($js_text, JSON_UNESCAPED_UNICODE); ?>;
    const post = (action, extra = {}) => {
      const form = new FormData();
      form.append('action', action);
      form.append('nonce', nonce);
      Object.entries(extra).forEach(([key, value]) => form.append(key, value));
      return fetch(ajax, {
        method: 'POST',
        body: form
      }).then(response => response.json())
    };
    document.querySelectorAll('.concept-graph-panel').forEach(panel => {
      const row = panel.closest('.pipeline-row'),
        editor = panel.querySelector('.graph-editor'),
        nodes = [...panel.querySelectorAll('.graph-branch-node')],
        mode = editor.querySelector('.graph-mode'),
        branchId = editor.querySelector('.graph-branch-id'),
        label = editor.querySelector('.graph-label'),
        scope = editor.querySelector('.graph-scope'),
        priority = editor.querySelector('.graph-priority'),
        angles = editor.querySelector('.graph-angles'),
        enabled = editor.querySelector('.graph-enabled'),
        title = editor.querySelector('.graph-editor-title'),
        feedback = editor.querySelector('.graph-feedback'),
        save = editor.querySelector('.graph-save-branch');
      let selectedNode = null;
      const selectNode = node => {
        if (!node) return;
        nodes.forEach(item => item.classList.toggle('is-selected', item === node));
        selectedNode = node;
        editor.classList.remove('is-new');
        mode.value = 'update';
        branchId.value = node.dataset.branchId;
        label.value = node.dataset.label;
        scope.value = node.dataset.scope;
        priority.value = node.dataset.priority;
        angles.value = node.dataset.angles;
        enabled.checked = node.dataset.enabled === '1';
        title.textContent = node.dataset.label;
        feedback.textContent = ''
      };
      const startNew = () => {
        nodes.forEach(item => item.classList.remove('is-selected'));
        selectedNode = null;
        editor.classList.add('is-new');
        mode.value = 'create';
        branchId.value = '';
        label.value = '';
        scope.value = '';
        priority.value = '2';
        angles.value = '';
        enabled.checked = true;
        title.textContent = panel.querySelector('.graph-add-branch').textContent;
        feedback.textContent = '';
        label.focus()
      };
      nodes.forEach(node => node.addEventListener('click', () => selectNode(node)));
      panel.addEventListener('toggle', () => {
        if (panel.open && !selectedNode && mode.value !== 'create') selectNode(nodes[0])
      });
      panel.querySelector('.graph-add-branch').addEventListener('click', startNew);
      editor.querySelector('.graph-cancel-edit').addEventListener('click', () => selectNode(nodes[0]));
      editor.addEventListener('submit', event => {
        event.preventDefault();
        if (!label.value.trim()) {
          feedback.textContent = text.branchRequired;
          label.focus();
          return
        }
        save.disabled = true;
        feedback.textContent = text.graphSaving;
        post('fourier_concept_pipeline_save_graph_branch', {
          post_id: row.dataset.id,
          mode: mode.value,
          branch_id: branchId.value,
          label: label.value.trim(),
          scope: scope.value.trim(),
          priority: priority.value,
          question_angles: angles.value,
          enabled: enabled.checked ? 'true' : 'false'
        }).then(result => {
          if (result.success) {
            feedback.textContent = text.graphSaved;
            setTimeout(() => location.reload(), 700)
          } else {
            feedback.textContent = result.data?.message || text.saveFailed;
            save.disabled = false
          }
        }).catch(() => {
          feedback.textContent = text.networkError;
          save.disabled = false
        })
      });
    });
  })();
</script>
<script>
  (() => {
    const root = document.querySelector('.fourier-pipeline');
    if (!root) return;
    const ajax = root.dataset.ajax,
      nonce = root.dataset.nonce,
      text = <?php echo wp_json_encode($js_text, JSON_UNESCAPED_UNICODE); ?>;
    const post = (action, extra = {}) => {
      const form = new FormData();
      form.append('action', action);
      form.append('nonce', nonce);
      Object.entries(extra).forEach(([key, value]) => form.append(key, value));
      return fetch(ajax, {
        method: 'POST',
        body: form
      }).then(response => response.json())
    };
    document.querySelectorAll('.multi-judge-panel').forEach(panel => {
      const row = panel.closest('.pipeline-row'),
        button = panel.querySelector('.judge-start'),
        feedback = panel.querySelector('.judge-feedback'),
        select = panel.querySelector('.judge-max-questions'),
        adopt = panel.querySelector('.judge-adopt'),
        redistill = panel.querySelector('.redistill-start'),
        decisionFeedback = panel.querySelector('.decision-feedback'),
        saveWeights = panel.querySelector('.judge-save-weights'),
        weightFeedback = panel.querySelector('.weight-feedback');
      let pollTimer = null,
        redistillTimer = null;
      const setLocked = locked => {
        if (button) button.disabled = locked;
        panel.querySelectorAll('.judge-provider input,.judge-max-questions').forEach(input => input.disabled = locked || input.closest('.judge-provider')?.classList.contains('is-unavailable'))
      };
      const poll = () => post('fourier_concept_multi_judge_status', {
        post_id: row.dataset.id
      }).then(result => {
        if (!result.success) throw new Error(result.data?.message || text.networkError);
        const job = result.data?.job || {},
          done = (job.completed_providers || []).length,
          total = (job.providers || []).length,
          current = job.current_provider ? ` · ${job.current_provider}` : '';
        feedback.textContent = `${text.judgeRunning} ${done} / ${total}${current}`;
        if (['completed', 'partial', 'error'].includes(job.status)) {
          clearTimeout(pollTimer);
          location.reload();
          return
        }
        pollTimer = setTimeout(poll, 2000);
      }).catch(() => {
        feedback.textContent = text.networkError;
        setLocked(false)
      });
      if (button) button.addEventListener('click', () => {
        const providers = [...panel.querySelectorAll('.judge-provider input:checked')].map(input => input.value);
        if (providers.length < 2) {
          feedback.textContent = text.judgeSelectTwo;
          return
        }
        setLocked(true);
        feedback.textContent = text.judgeStarting;
        post('fourier_concept_multi_judge_start', {
          post_id: row.dataset.id,
          providers: providers.join(','),
          max_questions: select.value
        }).then(result => {
          if (!result.success) {
            feedback.textContent = result.data?.message || text.startFailed;
            setLocked(false);
            return
          }
          feedback.textContent = text.judgeStarted;
          panel.dataset.jobStatus = 'queued';
          pollTimer = setTimeout(poll, 1200);
        }).catch(() => {
          feedback.textContent = text.networkError;
          setLocked(false)
        })
      });
      if (adopt) adopt.addEventListener('click', () => {
        const selections = {};
        panel.querySelectorAll('.curation-choice:checked').forEach(input => selections[input.dataset.questionId] = input.value);
        if (!Object.keys(selections).length) {
          decisionFeedback.textContent = text.selectQuestion;
          return
        }
        adopt.disabled = true;
        decisionFeedback.textContent = text.adopting;
        post('fourier_concept_adopt_consensus', {
          post_id: row.dataset.id,
          selections: JSON.stringify(selections)
        }).then(result => {
          if (!result.success) {
            decisionFeedback.textContent = result.data?.message || text.saveFailed;
            adopt.disabled = false;
            return
          }
          decisionFeedback.textContent = text.adopted;
          setTimeout(() => location.reload(), 700)
        }).catch(() => {
          decisionFeedback.textContent = text.networkError;
          adopt.disabled = false
        })
      });
      const pollRedistill = () => post('fourier_concept_selective_redistill_status', {
        post_id: row.dataset.id
      }).then(result => {
        if (!result.success) throw new Error();
        const job = result.data?.job || {};
        decisionFeedback.textContent = job.message || text.redistillRunning;
        if (['completed', 'error'].includes(job.status)) {
          clearTimeout(redistillTimer);
          location.reload();
          return
        }
        redistillTimer = setTimeout(pollRedistill, 2000)
      }).catch(() => {
        decisionFeedback.textContent = text.networkError;
        if (redistill) redistill.disabled = false
      });
      if (redistill) redistill.addEventListener('click', () => {
        const questionIds = [...panel.querySelectorAll('.redistill-question:checked')].map(input => input.value);
        if (!questionIds.length) {
          decisionFeedback.textContent = text.selectQuestion;
          return
        }
        redistill.disabled = true;
        if (adopt) adopt.disabled = true;
        decisionFeedback.textContent = text.redistillStarting;
        post('fourier_concept_selective_redistill_start', {
          post_id: row.dataset.id,
          question_ids: questionIds.join(','),
          provider: panel.querySelector('.redistill-provider').value
        }).then(result => {
          if (!result.success) {
            decisionFeedback.textContent = result.data?.message || text.startFailed;
            redistill.disabled = false;
            if (adopt) adopt.disabled = false;
            return
          }
          decisionFeedback.textContent = text.redistillRunning;
          redistillTimer = setTimeout(pollRedistill, 1200)
        }).catch(() => {
          decisionFeedback.textContent = text.networkError;
          redistill.disabled = false;
          if (adopt) adopt.disabled = false
        })
      });
      if (saveWeights) saveWeights.addEventListener('click', () => {
        const weights = {};
        panel.querySelectorAll('.judge-weight').forEach(input => weights[input.dataset.provider] = Math.max(25, Math.min(200, Number(input.value) || 100)) / 100);
        saveWeights.disabled = true;
        weightFeedback.textContent = text.weightsSaving;
        post('fourier_concept_multi_judge_save_weights', {
          post_id: row.dataset.id,
          weights: JSON.stringify(weights)
        }).then(result => {
          if (!result.success) {
            weightFeedback.textContent = result.data?.message || text.saveFailed;
            saveWeights.disabled = false;
            return
          }
          weightFeedback.textContent = text.weightsSaved;
          setTimeout(() => location.reload(), 700)
        }).catch(() => {
          weightFeedback.textContent = text.networkError;
          saveWeights.disabled = false
        })
      });
      if (['queued', 'running'].includes(panel.dataset.jobStatus)) {
        setLocked(true);
        pollTimer = setTimeout(poll, 800)
      }
      if (['queued', 'running'].includes(panel.dataset.redistillStatus)) {
        if (redistill) redistill.disabled = true;
        if (adopt) adopt.disabled = true;
        redistillTimer = setTimeout(pollRedistill, 800)
      }
    });
  })();
</script>
<script>
  (() => {
    const root = document.querySelector('.fourier-pipeline');
    if (!root) return;
    const ajax = root.dataset.ajax,
      nonce = root.dataset.nonce,
      text = <?php echo wp_json_encode($js_text, JSON_UNESCAPED_UNICODE); ?>;
    const post = (action, extra = {}) => {
      const form = new FormData();
      form.append('action', action);
      form.append('nonce', nonce);
      Object.entries(extra).forEach(([key, value]) => form.append(key, value));
      return fetch(ajax, { method: 'POST', body: form }).then(response => response.json())
    };
    document.querySelectorAll('.question-expansion').forEach(panel => {
      const row = panel.closest('.pipeline-row'),
        start = panel.querySelector('.question-expansion-start'),
        apply = panel.querySelector('.question-expansion-apply'),
        provider = panel.querySelector('.question-expansion-provider'),
        feedback = panel.querySelector('.question-expansion-feedback');
      let pollTimer = null;
      const setLocked = locked => {
        if (start) start.disabled = locked;
        if (provider) provider.disabled = locked;
        row.querySelectorAll('.coverage-branch').forEach(input => input.disabled = locked)
      };
      const poll = () => post('fourier_concept_question_expansion_status', { post_id: row.dataset.id }).then(result => {
        if (!result.success) throw new Error(result.data?.message || text.networkError);
        const job = result.data?.job || {};
        feedback.textContent = job.message || text.gapGenerating;
        if (['preview', 'error'].includes(job.status)) {
          clearTimeout(pollTimer);
          if (job.status === 'preview') feedback.textContent = text.gapPreviewReady;
          setTimeout(() => location.reload(), 500);
          return
        }
        pollTimer = setTimeout(poll, 2000)
      }).catch(() => {
        feedback.textContent = text.networkError;
        setLocked(false)
      });
      if (start) start.addEventListener('click', () => {
        const branchIds = [...row.querySelectorAll('.coverage-branch:checked')].map(input => input.value);
        if (!branchIds.length) {
          feedback.textContent = text.gapSelectBranch;
          return
        }
        setLocked(true);
        feedback.textContent = text.gapGenerating;
        post('fourier_concept_question_expansion_start', {
          post_id: row.dataset.id,
          branch_ids: branchIds.join(','),
          provider: provider.value
        }).then(result => {
          if (!result.success) {
            feedback.textContent = result.data?.message || text.startFailed;
            setLocked(false);
            return
          }
          pollTimer = setTimeout(poll, 1000)
        }).catch(() => {
          feedback.textContent = text.networkError;
          setLocked(false)
        })
      });
      if (apply) apply.addEventListener('click', () => {
        const candidateIds = [...panel.querySelectorAll('.question-candidate:checked')].map(input => input.value);
        if (!candidateIds.length) {
          feedback.textContent = text.gapSelectCandidate;
          return
        }
        apply.disabled = true;
        feedback.textContent = text.gapApplying;
        post('fourier_concept_question_expansion_apply', {
          post_id: row.dataset.id,
          candidate_ids: candidateIds.join(',')
        }).then(result => {
          if (!result.success) {
            feedback.textContent = result.data?.message || text.saveFailed;
            apply.disabled = false;
            return
          }
          feedback.textContent = text.gapApplied;
          setTimeout(() => location.reload(), 900)
        }).catch(() => {
          feedback.textContent = text.networkError;
          apply.disabled = false
        })
      });
      if (['queued', 'running'].includes(panel.dataset.status)) {
        setLocked(true);
        pollTimer = setTimeout(poll, 800)
      }
    })
  })();
</script>
<?php get_footer(); ?>
