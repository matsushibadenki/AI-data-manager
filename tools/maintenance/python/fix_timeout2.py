with open('inc/functions_llm_api.php', 'r') as f:
    content = f.read()

content = content.replace("'timeout' => 300,", "'timeout' => 900, // ローカルは非常に時間がかかる場合があるため15分に設定")

with open('inc/functions_llm_api.php', 'w') as f:
    f.write(content)
