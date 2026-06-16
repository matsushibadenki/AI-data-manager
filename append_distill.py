with open('inc/functions_llm_api.php', 'a') as f:
    f.write('''
// --- 新規：シードデータからの蒸留処理 ---
add_action('wp_ajax_frontend_learning_data_distill_from_seed', 'frontend_learning_data_distill_from_seed_handler');
add_action('wp_ajax_nopriv_frontend_learning_data_distill_from_seed', 'frontend_learning_data_distill_from_seed_handler');

function frontend_learning_data_distill_from_seed_handler() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'learning_data_action')) {
        wp_send_json_error(['message' => esc_html__('セッションが無効です。', 'fourier')]);
    }

    $seed_data = isset($_POST['seed_data']) ? sanitize_textarea_field($_POST['seed_data']) : '';
    $distill_method = isset($_POST['distill_method']) ? sanitize_text_field($_POST['distill_method']) : 'self-instruct';
    $target_format = isset($_POST['target_format']) ? sanitize_text_field($_POST['target_format']) : 'instruction';
    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : 'openai';
    $extra_prompt = isset($_POST['extra_prompt']) ? sanitize_textarea_field($_POST['extra_prompt']) : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : 'Distilled Data';

    if (!$seed_data || !$provider) {
        wp_send_json_error(['message' => esc_html__('パラメータが不足しています。', 'fourier')]);
    }

    // 1. プロンプトの構築
    $system_prompt = "あなたはAI学習データを作成・精製する専門のデータエンジニアです。与えられたシードデータやトピックから、要求された蒸留処理を行い、高品質な学習データを生成してください。出力結果は必ず指定されたフォーマットのJSONのみとしてください（Markdownのコードブロックは許容しますが余計なテキストは含めないこと）。";
    $user_prompt = "【シードデータ / トピック】\\n{$seed_data}\\n\\n";

    // 蒸留方式（Distillation Method）に応じた指示
    switch ($distill_method) {
        case 'self-instruct':
            $user_prompt .= "【指示: Self-Instruct】上記のトピックまたはデータに基づいて、多様で高品質なタスク（指示と回答のペア等）を複数生成してください。\\n";
            break;
        case 'refinement':
            $user_prompt .= "【指示: Refinement】上記の入力データの品質を向上させ、より詳細で正確、かつプロフェッショナルな表現に書き直してください。元の意図や情報は維持してください。\\n";
            break;
        case 'cot':
            $user_prompt .= "【指示: CoT Generation】上記のデータに対する回答が導かれるまでの「ステップバイステップの論理的な思考過程（Chain-of-Thought）」を詳細に生成し、付加してください。\\n";
            break;
        case 'backtranslation':
            $user_prompt .= "【指示: Backtranslation】上記のテキストを「AIの回答」と仮定し、ユーザーがAIに入力したであろう最適なプロンプト（指示や質問）を逆生成してペアにしてください。\\n";
            break;
        case 'format-conversion':
            $user_prompt .= "【指示: Format Conversion】上記のデータを、指定された構造化フォーマットに適切に変換・整形してください。\\n";
            break;
    }

    if ($extra_prompt) {
        $user_prompt .= "\\n【追加の指示】\\n{$extra_prompt}\\n\\n";
    }

    // ターゲットフォーマットに応じたJSON出力形式の指定
    $user_prompt .= "【出力フォーマット】\\n生成するデータは以下のJSONフォーマットに従ってください:\\n";
    if ($target_format === 'instruction') {
        $user_prompt .= '{ "instruction": "...", "input": "...", "output": "..." } (複数生成する場合は配列可)';
    } elseif ($target_format === 'chatml') {
        $user_prompt .= '{ "messages": [ { "role": "system", "content": "..." }, { "role": "user", "content": "..." }, { "role": "assistant", "content": "..." } ] }';
    } elseif ($target_format === 'cot') {
        $user_prompt .= '{ "question": "...", "thought": "...", "answer": "..." }';
    } elseif ($target_format === 'dpo') {
        $user_prompt .= '{ "prompt": "...", "chosen": "...", "rejected": "..." }';
    } else {
        $user_prompt .= '{ "text": "..." }';
    }

    $current_user_id = get_current_user_id();
    $llm_response_text = '';

    // 2. LLMプロバイダごとの通信
    try {
        switch ($provider) {
            case 'openai':
                $api_key = get_user_meta($current_user_id, 'llm_openai_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_openai_model', true) ?: 'gpt-4o';
                if (!$api_key) throw new Exception("OpenAI API Keyが設定されていません。");
                $llm_response_text = llm_api_call_openai($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'gemini':
                $api_key = get_user_meta($current_user_id, 'llm_gemini_api_key', true);
                $model = get_user_meta($current_user_id, 'llm_gemini_model', true) ?: 'gemini-1.5-pro-latest';
                if (!$api_key) throw new Exception("Gemini API Keyが設定されていません。");
                $llm_response_text = llm_api_call_gemini($api_key, $model, $system_prompt, $user_prompt);
                break;
            case 'ollama':
                $url = get_user_meta($current_user_id, 'llm_ollama_url', true) ?: 'http://127.0.0.1:11434';
                $model = get_user_meta($current_user_id, 'llm_ollama_model', true) ?: 'llama3';
                $llm_response_text = llm_api_call_ollama($url, $model, $system_prompt, $user_prompt);
                break;
            case 'custom':
                $url = get_user_meta($current_user_id, 'llm_custom_url', true) ?: 'http://127.0.0.1:8080/v1';
                $model = get_user_meta($current_user_id, 'llm_custom_model', true);
                $llm_response_text = llm_api_call_custom($url, $model, $system_prompt, $user_prompt);
                break;
            default:
                throw new Exception("不明なプロバイダです。");
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }

    if (empty($llm_response_text)) {
        wp_send_json_error(['message' => 'LLMから有効な応答が返されませんでした。']);
    }

    // 3. JSONパース
    $parsed_json = _parse_json_from_llm_response($llm_response_text);
    if ($parsed_json === null) {
        wp_send_json_error(['message' => esc_html__('LLMからのJSONパースに失敗しました。', 'fourier')]);
    }

    // 4. データ保存
    $payload = [
        'format' => $target_format,
        'data' => $parsed_json
    ];

    $post_data = array(
        'post_title'   => $title,
        'post_content' => wp_slash(json_encode($payload, JSON_UNESCAPED_UNICODE)),
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => $current_user_id
    );

    $post_id = wp_insert_post($post_data);
    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => esc_html__('データの保存に失敗しました。', 'fourier')]);
    }

    update_post_meta($post_id, 'is_learning_data', '1');

    $meta_fields = ['language', 'category', 'difficulty', 'quality', 'source', 'tags'];
    foreach ($meta_fields as $field) {
        if (isset($_POST[$field]) && $_POST[$field] !== '') {
            $val = sanitize_text_field($_POST[$field]);
            update_post_meta($post_id, 'learning_data_' . $field, $val);
        }
    }

    wp_send_json_success(['post_id' => $post_id]);
}
''')
