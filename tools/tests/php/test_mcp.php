<?php
/** Standalone transport/security unit tests; no DB, cron, or LLM calls. */
define('ABSPATH', __DIR__);
if (in_array('--disable-writes', $argv, true)) define('FOURIER_MCP_ALLOW_WRITES', false);
function add_action(...$args) {}
function add_filter(...$args) {}
class WP_Error {
    public function __construct(public $code, public $message, public $data = []) {}
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    public function __construct(public $data, public $status = 200, public $headers = []) {}
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function is_ssl() { return $GLOBALS['ssl'] ?? true; }
function wp_parse_url($v) { return parse_url($v); }
function home_url() { return 'https://localhost/wordpress'; }
function get_current_user_id() { return $GLOBALS['uid'] ?? 7; }
function wp_get_current_user() { return new class { function exists() { return true; } }; }
function current_user_can($cap) { return $cap === 'read' || ($cap === 'edit_posts' && !empty($GLOBALS['can_edit'])); }
function get_post($id) { return (object) ['ID' => $id, 'post_type' => 'post', 'post_author' => 8, 'post_status' => 'pending']; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
class Request {
    public $headers = ['content-type' => 'application/json', 'accept' => 'application/json, text/event-stream'];
    public $method = 'POST';
    function __construct(public $body) {}
    function get_body() { return $this->body; }
    function get_method() { return $this->method; }
    function get_header($key) { return $this->headers[$key] ?? ''; }
}
require dirname(__DIR__, 3) . '/inc/functions_mcp.php';
$count = 0;
function check($condition, $message) {
    global $count;
    $count++;
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
$r = new Request('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"future","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}');
check(fourier_mcp_http($r)->data['result']['protocolVersion'] === '2025-06-18', 'version negotiation');
check(fourier_mcp_permission($r) === true, 'authenticated HTTPS');
$r->headers['origin'] = 'https://evil.example';
check(is_wp_error(fourier_mcp_permission($r)), 'reject foreign origin');
unset($r->headers['origin']);
$GLOBALS['uid'] = 0;
check(is_wp_error(fourier_mcp_permission($r)), 'reject unauthenticated');
$GLOBALS['uid'] = 7;
$GLOBALS['ssl'] = false;
check(is_wp_error(fourier_mcp_permission($r)), 'require TLS');
$GLOBALS['ssl'] = true;
check(is_wp_error(fourier_mcp_owned_post(10)), 'reject another owner');
check(count(fourier_mcp_tools()) === 3, 'read-only user cannot list writes');
check(is_wp_error(fourier_mcp_call('start_url', [])), 'direct calls cannot bypass permissions');
$GLOBALS['can_edit'] = true;
$enabled = !defined('FOURIER_MCP_ALLOW_WRITES') || FOURIER_MCP_ALLOW_WRITES;
check(count(fourier_mcp_tools()) === ($enabled ? 6 : 3), 'editor writes enabled by default, explicit false respected');
check(fourier_mcp_writes_allowed() === $enabled, 'write permission resolution');
$GLOBALS['uid'] = 0;
check(!fourier_mcp_writes_allowed(), 'anonymous write denied');
$GLOBALS['uid'] = 7;
$GLOBALS['can_edit'] = false;
foreach (['{' => -32700, '[]' => -32600, '{"jsonrpc":"2.0","method":"ping","id":null}' => -32600, '{"jsonrpc":"2.0","id":1,"method":"missing"}' => -32601, '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"stop_auto"}}' => -32602] as $body => $code) {
    check(fourier_mcp_http(new Request($body))->data['error']['code'] === $code, 'RPC validation');
}
check(fourier_mcp_http(new Request('{"jsonrpc":"2.0","method":"notifications/initialized"}'))->status === 202, 'notification accepted');
$r->method = 'GET';
check(fourier_mcp_http($r)->status === 405, 'no SSE stream');
$r->method = 'POST';
$r->headers['mcp-protocol-version'] = 'unsupported';
check(fourier_mcp_http($r)->status === 400, 'unsupported header');
unset($r->headers['mcp-protocol-version']);
$r->headers['accept'] = 'application/json';
check(fourier_mcp_http($r)->status === 406, 'transport accept header');
echo "PASS: $count MCP unit checks (mocked WordPress, no external calls).\n";
