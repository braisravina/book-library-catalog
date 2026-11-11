<?php

declare(strict_types=1);

/**
 * Behaviour tests for docker/nginx/default.conf and the client IP policies
 * (client-ip.conf and the *.conf.example templates), run against a local
 * nginx binary and PHP-FPM serving an echo script that reports what PHP
 * receives (REMOTE_ADDR, HTTP_HOST, forwarding headers).
 *
 *     php tests/nginx/run.php
 *
 * Unprivileged and disposable: a private temporary directory, high ports on
 * 127.0.0.1 and ::1 only (PHP-FPM on a 127.0.0.1 TCP port: unix socket paths
 * are limited to 108 bytes), no system configuration, no Docker, no database.
 * 127.0.0.2 (Linux loopback) plays the trusted proxy; 127.0.0.1 is an
 * untrusted client. Binaries: NGINX_BIN and PHP_FPM_BIN (auto-detected).
 * Exit status: 0 all passed, 1 failures, 2 prerequisites missing.
 */

const UPSTREAM_FASTCGI_PARAMS = <<<'PARAMS'
    fastcgi_param  QUERY_STRING       $query_string;
    fastcgi_param  REQUEST_METHOD     $request_method;
    fastcgi_param  CONTENT_TYPE       $content_type;
    fastcgi_param  CONTENT_LENGTH     $content_length;
    fastcgi_param  SCRIPT_NAME        $fastcgi_script_name;
    fastcgi_param  REQUEST_URI        $request_uri;
    fastcgi_param  DOCUMENT_URI       $document_uri;
    fastcgi_param  DOCUMENT_ROOT      $document_root;
    fastcgi_param  SERVER_PROTOCOL    $server_protocol;
    fastcgi_param  REQUEST_SCHEME     $scheme;
    fastcgi_param  HTTPS              $https if_not_empty;
    fastcgi_param  GATEWAY_INTERFACE  CGI/1.1;
    fastcgi_param  SERVER_SOFTWARE    nginx/$nginx_version;
    fastcgi_param  REMOTE_ADDR        $remote_addr;
    fastcgi_param  REMOTE_PORT        $remote_port;
    fastcgi_param  SERVER_ADDR        $server_addr;
    fastcgi_param  SERVER_PORT        $server_port;
    fastcgi_param  SERVER_NAME        $server_name;
    fastcgi_param  REDIRECT_STATUS    200;
    PARAMS;

const ECHO_SCRIPT = <<<'PHP'
    <?php
    header('Content-Type: application/json');
    $http = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_') && is_string($value)) {
            $http[$key] = $value;
        }
    }
    echo json_encode(['remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null, 'host' => $_SERVER['HTTP_HOST'] ?? null, 'http' => $http]);
    PHP;

/** Headers that must never reach PHP with a value. */
const STRIPPED = [
    'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_PORT',
    'HTTP_X_FORWARDED_SERVER', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_X_CLIENT_IP', 'HTTP_X_ORIGINAL_HOST',
    'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL', 'HTTP_CF_CONNECTING_IP', 'HTTP_CF_CONNECTING_IPV6', 'HTTP_TRUE_CLIENT_IP',
];

const SPOOF = [
    'CF-Connecting-IP: 203.0.113.66', 'CF-Connecting-IPv6: 2001:db8::66', 'X-Forwarded-For: 203.0.113.66',
    'X-Real-IP: 203.0.113.66', 'Forwarded: for=203.0.113.66;host=evil.example;proto=https', 'True-Client-IP: 203.0.113.66',
    'X-Client-IP: 203.0.113.66', 'X-Forwarded-Host: evil.example', 'X-Forwarded-Proto: https', 'X-Forwarded-Port: 8443',
    'X-Forwarded-Server: evil.example', 'X-Original-Host: evil.example', 'X-Original-URL: /admin', 'X-Rewrite-URL: /admin',
];

final class NginxHarness
{
    public int $passed = 0;
    /** @var list<string> */
    public array $failures = [];
    public int $port = 0;
    private int $fpmPort = 0;
    /** @var resource|null */
    private $nginx = null;
    /** @var resource|null */
    private $fpm = null;
    private string $mainConf = '';

    public function __construct(public readonly string $repo, public readonly string $tmp, private readonly string $nginxBin, private readonly string $fpmBin)
    {
    }

    public function check(string $name, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->passed++;
            echo "  ok    {$name}\n";
        } else {
            $this->failures[] = $name;
            echo "  FAIL  {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
        }
    }

    public function prepare(): void
    {
        foreach (['public/assets', 'temp', 'conf'] as $dir) {
            mkdir($this->tmp . '/' . $dir, 0700, true);
        }
        file_put_contents($this->tmp . '/public/index.php', ECHO_SCRIPT);
        file_put_contents($this->tmp . '/public/assets/app.css', "body{}\n");
        file_put_contents($this->tmp . '/conf/fastcgi_params', UPSTREAM_FASTCGI_PARAMS . "\n");
        $this->fpmPort = self::freePort();
        file_put_contents($this->tmp . '/fpm.conf', implode("\n", [
            '[global]', "pid = {$this->tmp}/fpm.pid", "error_log = {$this->tmp}/fpm.log", 'daemonize = no',
            '[www]', "listen = 127.0.0.1:{$this->fpmPort}", 'listen.allowed_clients = 127.0.0.1', 'pm = static', 'pm.max_children = 2', 'clear_env = yes', '',
        ]));
        $this->port = self::freePort();

        $site = (string) file_get_contents($this->repo . '/docker/nginx/default.conf');
        $replacements = [
            'include /etc/nginx/dla/client-ip.conf;' => "include {$this->tmp}/conf/client-ip.conf;",
            'listen 8080 default_server;' => "listen 127.0.0.1:{$this->port} default_server;\n    listen [::1]:{$this->port} default_server;",
            'root /var/www/html/public;' => "root {$this->tmp}/public;",
            'fastcgi_pass php:9000;' => "fastcgi_pass 127.0.0.1:{$this->fpmPort};",
            'include fastcgi_params;' => "include {$this->tmp}/conf/fastcgi_params;",
            'access_log /var/log/nginx/access.log' => "access_log {$this->tmp}/access.log",
        ];
        foreach ($replacements as $from => $to) {
            if (substr_count($site, $from) !== 1) {
                throw new RuntimeException("default.conf changed: expected exactly one '{$from}'");
            }
            $site = str_replace($from, $to, $site);
        }
        file_put_contents($this->tmp . '/conf/default.conf', $site);
        $this->prepareProductionMain();
        $temp = $this->tmp . '/temp';
        file_put_contents($this->tmp . '/conf/nginx.conf', <<<CONF
            worker_processes 1;
            pid {$this->tmp}/nginx.pid;
            error_log {$this->tmp}/error.log info;
            events { worker_connections 64; }
            http {
                client_body_temp_path {$temp}/body;
                proxy_temp_path {$temp}/proxy;
                fastcgi_temp_path {$temp}/fastcgi;
                uwsgi_temp_path {$temp}/uwsgi;
                scgi_temp_path {$temp}/scgi;
                default_type application/octet-stream;
                include {$this->tmp}/conf/default.conf;
            }
            CONF);
        $this->mainConf = $this->tmp . '/conf/nginx.conf';
    }

    /**
     * docker/nginx/nginx.prod.conf (the production image's main
     * configuration) with its absolute paths moved under prod/, serving the
     * same default.conf: proves it runs unprivileged with /tmp-only paths.
     */
    private function prepareProductionMain(): void
    {
        $prod = $this->tmp . '/prod';
        foreach (['tmp', 'conf.d'] as $dir) {
            mkdir($prod . '/' . $dir, 0700, true);
        }
        copy($this->tmp . '/conf/default.conf', $prod . '/conf.d/default.conf');
        file_put_contents($prod . '/mime.types', "types { text/css css; application/javascript js; image/svg+xml svg; }\n");
        $main = (string) file_get_contents($this->repo . '/docker/nginx/nginx.prod.conf');
        // Before the includes are rewritten: $prod itself may lie under /tmp.
        if (substr_count($main, ' /tmp/') !== 6) {
            throw new RuntimeException('nginx.prod.conf changed: expected the PID file and five temp paths under /tmp');
        }
        $main = str_replace(' /tmp/', " {$prod}/tmp/", $main);
        foreach (['include /etc/nginx/mime.types;' => "include {$prod}/mime.types;", 'include /etc/nginx/conf.d/*.conf;' => "include {$prod}/conf.d/*.conf;"] as $from => $to) {
            if (substr_count($main, $from) !== 1) {
                throw new RuntimeException("nginx.prod.conf changed: expected exactly one '{$from}'");
            }
            $main = str_replace($from, $to, $main);
        }
        file_put_contents($prod . '/nginx.conf', $main);
    }

    public function useProductionMain(bool $production): void
    {
        $this->mainConf = $this->tmp . ($production ? '/prod/nginx.conf' : '/conf/nginx.conf');
    }

    /**
     * Installs a client IP policy (the shipped file or a template with its
     * placeholder replaced) and returns `nginx -t`'s exit status.
     */
    public function policy(string $file, ?string $address = null, ?bool $requireProxy = null): int
    {
        $policy = (string) file_get_contents($this->repo . '/docker/nginx/' . $file);
        if ($address !== null) {
            $policy = (string) preg_replace('/<[A-Z_]+_ADDRESS>/', $address, $policy);
        }
        if ($requireProxy !== null) {
            $policy = (string) preg_replace('/(map \$uri \$dla_require_trusted_proxy \{\s*default )[01];/', '${1}' . ($requireProxy ? '1' : '0') . ';', $policy, 1, $count);
            if ($count !== 1) {
                throw new RuntimeException('Cannot set $dla_require_trusted_proxy in ' . $file);
            }
        }
        file_put_contents($this->tmp . '/conf/client-ip.conf', $policy);

        return $this->run([$this->nginxBin, '-t', '-q', '-p', $this->tmp, '-c', $this->mainConf, '-e', $this->tmp . '/error.log']);
    }

    public function start(): void
    {
        $this->stop();
        if ($this->fpm === null) {
            $this->fpm = proc_open([$this->fpmBin, '-n', '-y', $this->tmp . '/fpm.conf', '-F'], [['file', '/dev/null', 'r'], ['file', $this->tmp . '/fpm.out', 'a'], ['file', $this->tmp . '/fpm.out', 'a']], $pipes) ?: null;
            $this->waitFor(fn (): bool => self::listening($this->fpmPort), 'php-fpm');
        }
        @unlink($this->tmp . '/access.log');
        $this->nginx = proc_open([$this->nginxBin, '-p', $this->tmp, '-c', $this->mainConf, '-e', $this->tmp . '/error.log', '-g', 'daemon off;'], [['file', '/dev/null', 'r'], ['file', $this->tmp . '/nginx.out', 'a'], ['file', $this->tmp . '/nginx.out', 'a']], $pipes) ?: null;
        $this->waitFor(fn (): bool => self::listening($this->port), 'nginx');
    }

    public function stop(): void
    {
        if ($this->nginx !== null) {
            proc_terminate($this->nginx, 15);
            proc_close($this->nginx);
            $this->nginx = null;
        }
    }

    public function shutdown(): void
    {
        $this->stop();
        if ($this->fpm !== null) {
            proc_terminate($this->fpm, 15);
            proc_close($this->fpm);
            $this->fpm = null;
        }
    }

    /**
     * One raw HTTP/1.0 request (no keep-alive, no chunking).
     *
     * @param list<string> $headers
     *
     * @return array{status: int, json: array<string, mixed>|null, raw: string}
     */
    public function request(string $target, array $headers = [], string $from = '127.0.0.1', ?string $host = 'library.test'): array
    {
        $to = str_contains($from, ':') ? '[::1]' : '127.0.0.1';
        $bind = str_contains($from, ':') ? "[{$from}]:0" : "{$from}:0";
        $context = stream_context_create(['socket' => ['bindto' => $bind]]);
        $socket = stream_socket_client("tcp://{$to}:{$this->port}", $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("connect from {$from}: {$error}");
        }
        $lines = ["GET {$target} HTTP/1.0"];
        if ($host !== null) {
            $lines[] = 'Host: ' . $host;
        }
        fwrite($socket, implode("\r\n", array_merge($lines, $headers)) . "\r\n\r\n");
        stream_set_timeout($socket, 5);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);
        preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m);
        $body = substr($raw, (int) strpos($raw, "\r\n\r\n") + 4);
        $json = json_decode($body, true);

        return ['status' => (int) ($m[1] ?? 0), 'json' => is_array($json) ? $json : null, 'raw' => $raw];
    }

    public function accessLog(): string
    {
        usleep(100_000);

        return (string) @file_get_contents($this->tmp . '/access.log');
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): int
    {
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', $this->tmp . '/nginx-t.out', 'w'], ['file', $this->tmp . '/nginx-t.out', 'w']], $pipes);

        return $process === false ? 127 : proc_close($process);
    }

    private function waitFor(callable $ready, string $what): void
    {
        for ($i = 0; $i < 100; $i++) {
            if ($ready()) {
                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException("{$what} did not start; see {$this->tmp}");
    }

    private static function listening(int $port): bool
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.2);

        return $socket !== false && fclose($socket);
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }
}

function find_binary(string $env, array $candidates): ?string
{
    $configured = getenv($env);
    foreach ($configured !== false && $configured !== '' ? [$configured] : $candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * @param array{status: int, json: array<string, mixed>|null, raw: string} $response
 */
function leaked(array $response): array
{
    $http = $response['json']['http'] ?? [];

    return array_keys(array_filter(array_intersect_key($http, array_flip(STRIPPED)), static fn ($v): bool => $v !== ''));
}

$nginxBin = find_binary('NGINX_BIN', ['/usr/sbin/nginx', '/usr/local/sbin/nginx', '/usr/bin/nginx']);
$fpmBin = find_binary('PHP_FPM_BIN', ['/usr/sbin/php-fpm8.4', '/usr/sbin/php-fpm8.3', '/usr/sbin/php-fpm', '/usr/local/sbin/php-fpm']);
if ($nginxBin === null || $fpmBin === null) {
    fwrite(STDERR, "nginx tests: prerequisites missing (nginx: " . ($nginxBin ?? 'not found') . ', php-fpm: ' . ($fpmBin ?? 'not found') . ")\n");
    exit(2);
}

$base = getenv('TMPDIR') ?: sys_get_temp_dir();
$tmp = rtrim($base, '/') . '/dla-nginx-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
$t = new NginxHarness(dirname(__DIR__, 2), $tmp, $nginxBin, $fpmBin);
register_shutdown_function(static function () use ($t, $tmp): void {
    $t->shutdown();
    if (getenv('KEEP_NGINX_TEST_DIR') !== '1') {
        remove_tree($tmp);
    }
});

try {
    $t->prepare();
    echo "nginx: {$nginxBin}, php-fpm: {$fpmBin}, port {$t->port}\n";

    echo "\n[syntax]\n";
    $t->check('templates are refused while their placeholder remains', $t->policy('client-ip.cloudflared.conf.example') !== 0 && $t->policy('client-ip.reverse-proxy.conf.example') !== 0);
    $t->check('cloudflared template valid with an address', $t->policy('client-ip.cloudflared.conf.example', '127.0.0.2') === 0);
    $t->check('reverse-proxy template valid with an address', $t->policy('client-ip.reverse-proxy.conf.example', '127.0.0.2') === 0);
    $t->check('shipped default.conf + client-ip.conf valid', $t->policy('client-ip.conf') === 0);

    // ---------------------------------------------------------------------
    echo "\n[production main configuration: unprivileged, /tmp-only paths]\n";
    $t->useProductionMain(true);
    $t->check('nginx.prod.conf + default.conf valid', $t->policy('client-ip.conf') === 0);
    $t->start();
    $t->check('/health is served', $t->request('/health')['status'] === 200);
    $t->check('static assets are served', $t->request('/assets/app.css')['status'] === 200);
    $r = $t->request('/login', SPOOF);
    $t->check('forwarding headers never reach PHP', $r['status'] === 200 && $r['json'] !== null && leaked($r) === [], $r['raw']);
    $t->check('PID file under its /tmp', is_file($tmp . '/prod/tmp/nginx.pid'));
    $t->stop();
    $t->useProductionMain(false);

    // ---------------------------------------------------------------------
    echo "\n[shipped policy: no trusted proxy]\n";
    $t->start();
    $r = $t->request('/login', SPOOF);
    $t->check('spoofed forwarding headers do not change REMOTE_ADDR', $r['status'] === 200 && ($r['json']['remote_addr'] ?? null) === '127.0.0.1', $r['raw']);
    $t->check('forwarding headers never reach PHP', $r['json'] !== null && leaked($r) === [], implode(',', leaked($r)));
    $r = $t->request('/login', ['X_Forwarded_For: 203.0.113.66', 'CF_Connecting_IP: 203.0.113.66']);
    $t->check('underscore aliases of forwarding headers are dropped', ($r['json']['http']['HTTP_X_FORWARDED_FOR'] ?? '') === '' && ($r['json']['http']['HTTP_CF_CONNECTING_IP'] ?? '') === '');
    $r = $t->request('/login', [], '::1');
    $t->check('IPv6 peer reaches PHP as ::1', ($r['json']['remote_addr'] ?? null) === '::1', $r['raw']);
    $r = $t->request('/login', [], '127.0.0.1', 'library.test:8443');
    $t->check('raw Host with port reaches PHP (application checks the port)', ($r['json']['host'] ?? null) === 'library.test:8443', $r['raw']);
    $r = $t->request('/login', [], '127.0.0.1', 'LIBRARY.Test');
    $t->check('Host case is preserved for the application', ($r['json']['host'] ?? null) === 'LIBRARY.Test');
    $t->check('absolute-form target with a different Host is refused', $t->request('http://evil.example/login')['status'] === 400);
    $t->check('absolute-form target with a foreign Host is refused', $t->request('http://library.test/login', [], '127.0.0.1', 'evil.example')['status'] === 400);
    $t->check('absolute-form target matching the Host is served', $t->request('http://library.test/login')['status'] === 200);
    $t->check('a crafted path cannot shift the refusal fields', $t->request('http://evil.example/a%7C1%7C00%7C/health')['status'] === 400);
    $t->check('/health is exempt from the Host consistency check', $t->request('http://evil.example/health')['status'] === 200);
    $t->check('static assets are served', $t->request('/assets/app.css')['status'] === 200);
    $t->request('/invite?token=SECRETTOKENVALUE');
    $log = $t->accessLog();
    $t->check('access log records the TCP peer', str_contains($log, 'peer=127.0.0.1') && str_contains($log, 'peer=::1'));
    $t->check('access log omits query-format invitation tokens', $log !== '' && !str_contains($log, 'SECRETTOKENVALUE'));

    // ---------------------------------------------------------------------
    echo "\n[cloudflared template, trusted proxy 127.0.0.2, direct requests refused]\n";
    $t->policy('client-ip.cloudflared.conf.example', '127.0.0.2');
    $t->start();
    $r = $t->request('/login', ['CF-Connecting-IP: 203.0.113.9'], '127.0.0.2');
    $t->check('trusted proxy: CF-Connecting-IP becomes REMOTE_ADDR', $r['status'] === 200 && ($r['json']['remote_addr'] ?? null) === '203.0.113.9', $r['raw']);
    $t->check('trusted proxy: the header itself still does not reach PHP', $r['json'] !== null && leaked($r) === []);
    $r = $t->request('/login', ['CF-Connecting-IP: 2001:DB8:0:0::1'], '127.0.0.2');
    $t->check('trusted proxy: IPv6 client address (canonical form)', ($r['json']['remote_addr'] ?? null) === '2001:db8::1', $r['raw']);
    $r = $t->request('/login', ['CF-Connecting-IP: 203.0.113.9', 'X-Forwarded-For: 198.51.100.1'], '127.0.0.2');
    $t->check('trusted proxy: X-Forwarded-For is not used in this mode', ($r['json']['remote_addr'] ?? null) === '203.0.113.9');
    $t->check('untrusted peer with CF-Connecting-IP is refused', $t->request('/login', ['CF-Connecting-IP: 203.0.113.9'])['status'] === 403);
    $t->check('untrusted IPv6 peer is refused', $t->request('/login', ['CF-Connecting-IP: 203.0.113.9'], '::1')['status'] === 403);
    $t->check('trusted proxy without CF-Connecting-IP is refused', $t->request('/login', ['X-Forwarded-For: 203.0.113.9'], '127.0.0.2')['status'] === 403);
    // nginx parses any real_ip header as a list and, non-recursive, takes the
    // last entry. Only the trusted proxy can send it (Cloudflare replaces
    // visitor-supplied CF-Connecting-IP values with a single address).
    $r = $t->request('/login', ['CF-Connecting-IP: 203.0.113.9, 198.51.100.1'], '127.0.0.2');
    $t->check('trusted proxy: a list resolves to its last entry, never the first', ($r['json']['remote_addr'] ?? null) === '198.51.100.1', $r['raw']);
    $r = $t->request('/login', ['CF-Connecting-IP: 203.0.113.9:443'], '127.0.0.2');
    $t->check('trusted proxy: address:port resolves to the address', ($r['json']['remote_addr'] ?? null) === '203.0.113.9', $r['raw']);
    foreach (['not-an-ip', '999.1.1.1', '[2001:db8::1]', '203.0.113.9 extra', '127.0.0.2'] as $bad) {
        $t->check("trusted proxy with malformed CF-Connecting-IP '{$bad}' is refused", $t->request('/login', ['CF-Connecting-IP: ' . $bad], '127.0.0.2')['status'] === 403);
    }
    $t->check('static assets are refused to direct clients', $t->request('/assets/app.css')['status'] === 403);
    $t->check('/health stays reachable for local probes', $t->request('/health')['status'] === 200);
    $t->check('/health stays reachable over IPv6', $t->request('/health', [], '::1')['status'] === 200);
    $t->check('only /health is exempt (/health/x refused)', $t->request('/health/x')['status'] === 403);
    $t->request('/login', ['CF-Connecting-IP: 203.0.113.9'], '127.0.0.2');
    $t->check('access log shows client and reporting proxy', str_contains($t->accessLog(), '203.0.113.9 - ') && str_contains($t->accessLog(), 'peer=127.0.0.2'));

    echo "\n[cloudflared template, trusted proxy ::1]\n";
    $t->policy('client-ip.cloudflared.conf.example', '::1');
    $t->start();
    $r = $t->request('/login', ['CF-Connecting-IP: 198.51.100.7'], '::1');
    $t->check('IPv6 trusted proxy: client address resolved', ($r['json']['remote_addr'] ?? null) === '198.51.100.7', $r['raw']);
    $t->check('IPv4 peer is not trusted by an IPv6 entry', $t->request('/login', ['CF-Connecting-IP: 198.51.100.7'], '127.0.0.1')['status'] === 403);

    echo "\n[cloudflared template, direct requests allowed]\n";
    $t->policy('client-ip.cloudflared.conf.example', '127.0.0.2', false);
    $t->start();
    $r = $t->request('/login', ['CF-Connecting-IP: 203.0.113.9']);
    $t->check('untrusted peer keeps its own address', ($r['json']['remote_addr'] ?? null) === '127.0.0.1', $r['raw']);
    $r = $t->request('/login', ['CF-Connecting-IP: not-an-ip'], '127.0.0.2');
    $t->check('malformed value from the proxy falls back to the proxy address', ($r['json']['remote_addr'] ?? null) === '127.0.0.2', $r['raw']);
    $r = $t->request('/login', [], '127.0.0.2');
    $t->check('missing value from the proxy falls back to the proxy address', ($r['json']['remote_addr'] ?? null) === '127.0.0.2', $r['raw']);

    // ---------------------------------------------------------------------
    echo "\n[reverse-proxy template, trusted proxy 127.0.0.2]\n";
    $t->policy('client-ip.reverse-proxy.conf.example', '127.0.0.2');
    $t->start();
    $r = $t->request('/login', ['X-Forwarded-For: 198.51.100.1, 203.0.113.9'], '127.0.0.2');
    $t->check('rightmost untrusted X-Forwarded-For entry wins (prepended value ignored)', ($r['json']['remote_addr'] ?? null) === '203.0.113.9', $r['raw']);
    $r = $t->request('/login', ['X-Forwarded-For: 203.0.113.9, 127.0.0.2'], '127.0.0.2');
    $t->check('trusted hops in X-Forwarded-For are skipped', ($r['json']['remote_addr'] ?? null) === '203.0.113.9', $r['raw']);
    $r = $t->request('/login', ['X-Forwarded-For: 2001:db8::5'], '127.0.0.2');
    $t->check('IPv6 client in X-Forwarded-For', ($r['json']['remote_addr'] ?? null) === '2001:db8::5', $r['raw']);
    $t->check('CF-Connecting-IP is not used in this mode', $t->request('/login', ['CF-Connecting-IP: 203.0.113.9'], '127.0.0.2')['status'] === 403);
    $t->check('garbage X-Forwarded-For is refused', $t->request('/login', ['X-Forwarded-For: garbage'], '127.0.0.2')['status'] === 403);
    $t->check('untrusted peer with X-Forwarded-For is refused', $t->request('/login', ['X-Forwarded-For: 203.0.113.9'])['status'] === 403);
    $t->check('/health stays reachable', $t->request('/health')['status'] === 200);
} catch (Throwable $e) {
    $t->failures[] = 'harness: ' . $e->getMessage();
    echo '  FAIL  harness: ' . $e->getMessage() . "\n";
}

$t->shutdown();
echo "\n" . $t->passed . ' passed, ' . count($t->failures) . " failed\n";
exit($t->failures === [] ? 0 : 1);
