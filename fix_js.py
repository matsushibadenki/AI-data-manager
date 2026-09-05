with open('page-ai-registration.php', 'r') as f:
    content = f.read()

target_js = '''        // メッセージ表示
        function showStatus(message, isError = false) {
            const statusDiv = document.getElementById('status-message');
            statusDiv.textContent = message;
            statusDiv.className = 'status-msg ' + (isError ? 'error' : 'success');
            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 5000);
        }'''

replace_js = '''        // メッセージ表示
        function showStatus(message, isError = false) {
            const statusDiv = document.getElementById('status-message');
            statusDiv.textContent = message;
            statusDiv.className = 'status-msg ' + (isError ? 'error' : 'success');
            statusDiv.style.display = 'block';
            
            if (window.statusTimeout) clearTimeout(window.statusTimeout);
            
            if (!message.includes('数分かかる場合があります')) {
                window.statusTimeout = setTimeout(() => {
                    statusDiv.style.display = 'none';
                }, 5000);
            }
        }'''

content = content.replace(target_js, replace_js)

with open('page-ai-registration.php', 'w') as f:
    f.write(content)
