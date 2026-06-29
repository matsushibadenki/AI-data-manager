import re

pit_path = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-index-text.php'
with open(pit_path, 'r') as f:
    pit_content = f.read()

chatml_old = """                                        <?php
                                        if (isset($item['data']['messages']) && is_array($item['data']['messages'])) {
                                            foreach ($item['data']['messages'] as $msg) {
                                                echo '<div style="margin-bottom:0.5rem;">';
                                                echo '<strong>' . esc_html($msg['role']) . ':</strong><br>';
                                                echo nl2br(esc_html($msg['content']));
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
                                        }
                                        ?>"""

pit_content = pit_content.replace(chatml_old, chatml_new)


sharegpt_old = """                                        <?php
                                        if (isset($item['data']['conversations']) && is_array($item['data']['conversations'])) {
                                            foreach ($item['data']['conversations'] as $conv) {
                                                echo '<div style="margin-bottom:0.5rem;">';
                                                echo '<strong>' . esc_html($conv['from']) . ':</strong><br>';
                                                echo nl2br(esc_html($conv['value']));
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
                                        }
                                        ?>"""

pit_content = pit_content.replace(sharegpt_old, sharegpt_new)

with open(pit_path, 'w') as f:
    f.write(pit_content)

print("Display fix applied successfully.")
