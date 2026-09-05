<?php
$text = '[{"instruction": "test"}]';
function _parse_json_from_llm_response($text)
{
    $text = preg_replace('/^```json\s*/m', '', $text);
    $text = preg_replace('/```$/m', '', $text);
    $text = trim($text);

    $decoded = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (!is_array($decoded) || array_keys($decoded) !== range(0, count($decoded) - 1)) {
            if ((isset($decoded['draft_thought']) && isset($decoded['data'])) || isset($decoded['variations'])) {
                return $decoded;
            }
            return [$decoded]; // それ以外は配列に強制
        }
        return $decoded;
    }
    return null;
}
var_dump(_parse_json_from_llm_response($text));
