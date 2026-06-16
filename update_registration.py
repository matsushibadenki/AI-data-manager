import re

with open('page-ai-registration.php', 'r') as f:
    content = f.read()

# Add Tab Button
tab_button_target = '''                    <button type="button" class="learning-tab active" data-target="tab-scrape" style="background: var(--accent-subtle, rgba(201,169,110,0.1)); color: var(--accent, #C9A96E); border: 1px solid var(--accent, #C9A96E);">
                        <span class="material-symbols-outlined" style="font-size: 1rem; vertical-align: -2px;">language</span> URL自動取得
                    </button>'''
tab_button_replacement = tab_button_target + '''
                    <button type="button" class="learning-tab" data-target="tab-distillation" style="background: var(--accent-subtle, rgba(201,169,110,0.1)); color: var(--accent, #C9A96E); border: 1px solid var(--accent, #C9A96E);">
                        <span class="material-symbols-outlined" style="font-size: 1rem; vertical-align: -2px;">science</span> データ蒸留生成
                    </button>'''
content = content.replace(tab_button_target, tab_button_replacement)

# Add Tab Content
tab_content_target = '''                    <div style="margin-top: 1.5rem; text-align: center;">
                        <button type="button" id="btn-scrape-submit" class="btn-black" style="background: var(--accent); border-color: var(--accent); color: var(--text-inverse);">
                            <span class="material-symbols-outlined">language</span> 自動取得・生成して登録
                        </button>
                    </div>
                </div>'''
tab_content_replacement = tab_content_target + '''

                <!-- 蒸留生成登録 -->
                <div id="tab-distillation" class="learning-tab-content" data-format="distillation">
                    <div class="upload-form-group">
                        <label for="distill-seed"><?php echo esc_html__('シードデータ / トピック:', 'fourier'); ?></label>
                        <textarea id="distill-seed" class="upload-form-input" rows="4" placeholder="例: 「日本の歴史に関するQAを作成して」「以下のテキストを元に、より詳細な解説を作成して: [テキスト]」"></textarea>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-method"><?php echo esc_html__('蒸留方式:', 'fourier'); ?></label>
                        <select id="distill-method" class="upload-form-input">
                            <option value="self-instruct">Self-Instruct (トピックから多様な指示・回答ペア生成)</option>
                            <option value="refinement">Refinement (入力データの高品質化・詳細化)</option>
                            <option value="cot">CoT Generation (論理的思考プロセスの付加)</option>
                            <option value="backtranslation">Backtranslation (回答から最適なプロンプトを逆生成)</option>
                            <option value="format-conversion">Format Conversion (特定フォーマットへの構造化)</option>
                        </select>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-target-format"><?php echo esc_html__('生成するデータ形式:', 'fourier'); ?></label>
                        <select id="distill-target-format" class="upload-form-input">
                            <option value="instruction">Instruction (QAペア)</option>
                            <option value="chatml">ChatML (会話形式)</option>
                            <option value="cot">CoT (思考過程付き)</option>
                            <option value="dpo">DPO / RLHF (比較データ)</option>
                            <option value="plain">プレーンテキスト</option>
                        </select>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-provider"><?php echo esc_html__('教師モデル (LLMプロバイダ):', 'fourier'); ?></label>
                        <select id="distill-provider" class="upload-form-input">
                            <option value="openai">OpenAI (推奨: gpt-5.5等)</option>
                            <option value="gemini">Google Gemini</option>
                            <option value="ollama">Ollama (ローカルサーバー)</option>
                            <option value="custom">Custom (Llama.cpp等)</option>
                        </select>
                    </div>
                    <div class="upload-form-group" style="margin-top: 1rem;">
                        <label for="distill-prompt"><?php echo esc_html__('追加の指示（任意）:', 'fourier'); ?></label>
                        <textarea id="distill-prompt" class="upload-form-input" rows="2" placeholder="例: 出力は小学生でもわかる言葉遣いにしてください。"></textarea>
                    </div>
                    <div style="margin-top: 1.5rem; text-align: center;">
                        <button type="button" id="btn-distill-submit" class="btn-black" style="background: var(--accent); border-color: var(--accent); color: var(--text-inverse);">
                            <span class="material-symbols-outlined">science</span> 蒸留処理を実行して登録
                        </button>
                    </div>
                </div>'''
content = content.replace(tab_content_target, tab_content_replacement)

# Add JS Logic for Distillation right after scrape JS
js_target = '''        // 検索処理
        document.getElementById('btn-search')'''
js_replacement = '''        // データ蒸留処理
        document.getElementById('btn-distill-submit').addEventListener('click', function() {
            const seed = document.getElementById('distill-seed').value.trim();
            const method = document.getElementById('distill-method').value;
            const targetFormat = document.getElementById('distill-target-format').value;
            const provider = document.getElementById('distill-provider').value;
            const extraPrompt = document.getElementById('distill-prompt').value.trim();

            if (!seed) {
                showStatus('シードデータまたはトピックを入力してください。', true);
                return;
            }

            const titleInput = document.getElementById('data-title').value.trim();
            if (!titleInput) {
                showStatus('タイトルを入力してください。', true);
                return;
            }

            // メタデータ収集
            var metaLang = document.getElementById('meta-language');
            var metaCat = document.getElementById('meta-category');
            var metaDiff = document.getElementById('meta-difficulty');
            var metaQuality = document.getElementById('meta-quality');
            var metaSource = document.getElementById('meta-source');
            var metaTags = document.getElementById('meta-tags');

            const formData = new FormData();
            formData.append('action', 'frontend_learning_data_distill_from_seed');
            formData.append('nonce', uploadNonce);
            formData.append('seed_data', seed);
            formData.append('distill_method', method);
            formData.append('target_format', targetFormat);
            formData.append('provider', provider);
            formData.append('extra_prompt', extraPrompt);
            formData.append('title', titleInput);

            if (metaLang && metaLang.value) formData.append('language', metaLang.value);
            if (metaCat && metaCat.value) formData.append('category', metaCat.value);
            if (metaDiff && metaDiff.value) formData.append('difficulty', metaDiff.value);
            if (metaQuality && metaQuality.value) formData.append('quality', metaQuality.value);
            if (metaSource && metaSource.value) formData.append('source', metaSource.value);
            if (metaTags && metaTags.value) formData.append('tags', metaTags.value);

            showStatus('教師モデルから蒸留データを生成しています... (数分かかる場合があります)', false);
            this.disabled = true;
            this.style.opacity = '0.5';

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(response => {
                this.disabled = false;
                this.style.opacity = '1';
                
                if (response.success) {
                    showStatus('蒸留データの自動登録が完了しました！(ID: ' + response.data.post_id + ')', false);
                    document.getElementById('distill-seed').value = '';
                    document.getElementById('data-title').value = '';
                } else {
                    showStatus(response.data.message || '処理に失敗しました。', true);
                }
            })
            .catch(error => {
                this.disabled = false;
                this.style.opacity = '1';
                showStatus('通信エラーが発生しました。', true);
            });
        });

        // 検索処理
        document.getElementById('btn-search')'''
content = content.replace(js_target, js_replacement)

with open('page-ai-registration.php', 'w') as f:
    f.write(content)
