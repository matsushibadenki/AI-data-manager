with open('page-api-settings.php', 'r') as f:
    content = f.read()

# Ollama help text
content = content.replace('placeholder="http://127.0.0.1:11434"', 'placeholder="http://host.docker.internal:11434"')
content = content.replace('Ollamaが稼働しているサーバーのURLを指定します。', 'Ollamaが稼働しているサーバーのURLを指定します。<br><span style="color:var(--accent);">※Docker上で動かしている場合、母艦のMac/PCに接続するには <code>http://host.docker.internal:11434</code> を指定してください。</span>')

# Custom (Llama.cpp) help text
content = content.replace('placeholder="http://127.0.0.1:8080/v1"', 'placeholder="http://host.docker.internal:8080/v1"')
content = content.replace('Llama.cppのサーバーやvLLMなど、OpenAI互換の/v1エンドポイントURLを指定します。', 'Llama.cppのサーバーやvLLMなど、OpenAI互換の/v1エンドポイントURLを指定します。<br><span style="color:var(--accent);">※Docker上で動かしている場合、母艦のMac/PCに接続するには <code>http://host.docker.internal:8080/v1</code>（Ollamaの場合は <code>http://host.docker.internal:11434/v1</code>）などを指定してください。</span>')

with open('page-api-settings.php', 'w') as f:
    f.write(content)
