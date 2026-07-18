import re

with open('inc/functions_llm_api.php', 'r') as f:
    content = f.read()

# Fix in frontend_learning_data_distill_handler (around line 223)
target_distill = '''    $parsed_json = _parse_json_from_llm_response($llm_response_text);
    if ($parsed_json === null) {
        wp_send_json_error(['message' => 'LLMからのJSONパースに失敗しました。']);
    }

    $distilled_results = $parsed_json;'''
replace_distill = '''    $parsed_json = $llm_response_text; // Already parsed by llm_api_call_*
    $distilled_results = $parsed_json;'''
content = content.replace(target_distill, replace_distill)

# Fix in frontend_learning_data_scrape_url_handler (around line 662)
target_scrape = '''    // 5. JSONパースとデータ保存
    $parsed_json = _parse_json_from_llm_response($llm_response_text);
    if ($parsed_json === null) {
        wp_send_json_error(['message' => esc_html__('LLMからのJSONパースに失敗しました。', 'fourier')]);
    }

    $final_data = $parsed_json;'''
replace_scrape = '''    // 5. データ保存
    $parsed_json = $llm_response_text; // Already parsed by llm_api_call_*
    $final_data = $parsed_json;'''
content = content.replace(target_scrape, replace_scrape)

# Fix in frontend_learning_data_distill_from_seed_handler (around line 812)
target_seed = '''    // 3. JSONパース
    $parsed_json = _parse_json_from_llm_response($llm_response_text);
    if ($parsed_json === null) {
        wp_send_json_error(['message' => esc_html__('LLMからのJSONパースに失敗しました。', 'fourier')]);
    }

    $final_data = $parsed_json;'''
replace_seed = '''    // 3. パース済みデータ取得
    $parsed_json = $llm_response_text; // Already parsed by llm_api_call_*
    $final_data = $parsed_json;'''
content = content.replace(target_seed, replace_seed)


with open('inc/functions_llm_api.php', 'w') as f:
    f.write(content)
