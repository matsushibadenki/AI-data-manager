import re

pit_path = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-index-text.php'
with open(pit_path, 'r') as f:
    pit_content = f.read()

chatml_old = """                                        <?php
                                        $messages = isset($item['data']['messages']) ? $item['data']['messages'] : (is_array($item['data']) && isset($item['data'][0]['role']) ? $item['data'] : []);
                                        if (!empty($messages) && is_array($messages)) {
                                            foreach ($messages as $msg) {
                                                echo '<div style="margin-bottom:0.5rem;">';
                                                echo '<strong>' . esc_html(isset($msg['role']) ? $msg['role'] : '') . ':</strong><br>';
                                                echo nl2br(esc_html(isset($msg['content']) ? $msg['content'] : ''));
                                                echo '</div>';
                                            }
                                        }
                                        ?>"""

chatml_new = """                                        <?php
                                        $messages = isset($item['data']['messages']) ? $item['data']['messages'] : (is_array($item['data']) && isset($item['data'][0]['role']) ? $item['data'] : []);
                                        if (!empty($messages) && is_array($messages)) {
                                            foreach ($messages as $msg) {
                                                echo '<div style="margin-bottom:0.5rem;">';
                                                echo '<strong>' . esc_html(isset($msg['role']) ? $msg['role'] : '') . ':</strong><br>';
                                                echo nl2br(esc_html(isset($msg['content']) ? $msg['content'] : ''));
                                                echo '</div>';
                                            }
                                        } else {
                                            // 期待した構造でない場合のフォールバック（生のJSONを表示）
                                            echo '<pre style="white-space: pre-wrap; font-size: 0.8rem; background: #f8f8f8; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">' . esc_html(json_encode($item['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
                                        }
                                        ?>"""

pit_content = pit_content.replace(chatml_old, chatml_new)

sharegpt_old = """                                        <?php
                                        $conversations = isset($item['data']['conversations']) ? $item['data']['conversations'] : (is_array($item['data']) && isset($item['data'][0]['from']) ? $item['data'] : []);
                                        if (!empty($conversations) && is_array($conversations)) {
                                            foreach ($conversations as $conv) {
                                                echo '<div style="margin-bottom:0.5rem;">';
                                                echo '<strong>' . esc_html(isset($conv['from']) ? $conv['from'] : '') . ':</strong><br>';
                                                echo nl2br(esc_html(isset($conv['value']) ? $conv['value'] : ''));
                                                echo '</div>';
                                            }
                                        }
                                        ?>"""

sharegpt_new = """                                        <?php
                                        $conversations = isset($item['data']['conversations']) ? $item['data']['conversations'] : (is_array($item['data']) && isset($item['data'][0]['from']) ? $item['data'] : []);
                                        if (!empty($conversations) && is_array($conversations)) {
                                            foreach ($conversations as $conv) {
                                                echo '<div style="margin-bottom:0.5rem;">';
                                                echo '<strong>' . esc_html(isset($conv['from']) ? $conv['from'] : '') . ':</strong><br>';
                                                echo nl2br(esc_html(isset($conv['value']) ? $conv['value'] : ''));
                                                echo '</div>';
                                            }
                                        } else {
                                            // 期待した構造でない場合のフォールバック（生のJSONを表示）
                                            echo '<pre style="white-space: pre-wrap; font-size: 0.8rem; background: #f8f8f8; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">' . esc_html(json_encode($item['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
                                        }
                                        ?>"""

pit_content = pit_content.replace(sharegpt_old, sharegpt_new)

with open(pit_path, 'w') as f:
    f.write(pit_content)

print("Fallback display applied successfully.")
