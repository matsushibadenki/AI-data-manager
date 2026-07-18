import re

filepath = '/Users/Shared/Docker/AI-data-manager-docker/www/html/wordpress/wp-content/themes/AI-data-manager/page-import-export.php'
with open(filepath, 'r') as f:
    content = f.read()

old_logic = """        function detectFormatLocally(raw) {
            let format = 'structured';
            if (raw.instruction && raw.output) format = 'instruction';
            else if (raw.messages && Array.isArray(raw.messages)) format = 'chatml';
            else if (raw.conversations && Array.isArray(raw.conversations)) format = 'sharegpt';
            else if (raw.question && raw.thought && raw.answer) format = 'cot';
            else if (raw.prompt && raw.chosen && raw.rejected) format = 'dpo';
            else if (raw.html || raw.css || raw.js) format = 'frontend_code';
            else if (raw.text && Object.keys(raw).length === 1) format = 'plain';

            return {"""

new_logic = """        function detectFormatLocally(raw) {
            const forceFormat = document.getElementById('import-force-format') ? document.getElementById('import-force-format').value : 'auto';
            let format = 'structured';
            
            let checkTarget = (raw.data && Array.isArray(raw.data)) ? raw.data : raw;
            
            if (forceFormat !== 'auto') {
                format = forceFormat;
            } else if (checkTarget.instruction && checkTarget.output) {
                format = 'instruction';
            } else if (checkTarget.messages && Array.isArray(checkTarget.messages)) {
                format = 'chatml';
            } else if (checkTarget.conversations && Array.isArray(checkTarget.conversations)) {
                format = 'sharegpt';
            } else if (checkTarget.question && checkTarget.thought && checkTarget.answer) {
                format = 'cot';
            } else if (checkTarget.prompt && checkTarget.chosen && checkTarget.rejected) {
                format = 'dpo';
            } else if (checkTarget.html || checkTarget.css || checkTarget.js) {
                format = 'frontend_code';
            } else if (checkTarget.text && Object.keys(checkTarget).length === 1) {
                format = 'plain';
            } else if (Array.isArray(checkTarget) && checkTarget.length > 0 && checkTarget[0].role) {
                format = 'chatml';
            } else if (Array.isArray(checkTarget) && checkTarget.length > 0 && checkTarget[0].from) {
                format = 'sharegpt';
            }

            return {"""

content = content.replace(old_logic, new_logic)

with open(filepath, 'w') as f:
    f.write(content)

print("JS format detection logic updated.")
