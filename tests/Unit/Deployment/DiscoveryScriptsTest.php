<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

/**
 * docker/discovery/*.sh: production topology discovery must stay read-only
 * and must not copy secrets into its report. The scripts run against stub
 * docker/systemctl/ss/ip/pgrep/sudo binaries that serve planted secrets.
 */
final class DiscoveryScriptsTest extends TestCase
{
    private const SCRIPTS = ['docker/discovery/docker-host.sh', 'docker/discovery/ingress-host.sh'];
    private const TOKEN = 'eyJhIjoiMTIzNDU2Nzg5MGFiY2RlZiIsInQiOiJhYmNkZWYxMjM0NTYifQ';
    /** Planted values that must never appear in a report. */
    private const SECRETS = [self::TOKEN, 'owner@example.org', 'apr1', 'SUPERSECRET', 'hunter2', '203.0.113.7', 'Bearer'];

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/dla-discovery-' . bin2hex(random_bytes(6));
        foreach (['bin', 'traefik', 'nginx'] as $dir) {
            mkdir($this->tmp . '/' . $dir, 0700, true);
        }
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tmp);
    }

    public function testScriptsParseAndContainOnlyReadOnlyCommands(): void
    {
        foreach (self::SCRIPTS as $script) {
            $path = dirname(__DIR__, 3) . '/' . $script;
            [$status, , $err] = $this->exec(['bash', '-n', $path]);
            self::assertSame(0, $status, $script . ': ' . $err);
            $source = implode("\n", array_filter(explode("\n", (string) file_get_contents($path)), static fn (string $l): bool => !str_starts_with(ltrim($l), '#')));
            // Commands only: messages in quotes may mention "sudo -v" etc.
            $code = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'/', '""', $source);
            self::assertDoesNotMatchRegularExpression('/\b(sh|bash)\s+-c\b|\beval\b/', $code, $script . ': no hidden command strings');
            self::assertDoesNotMatchRegularExpression('/\bsystem\s*\(|\|\s*getline|print[^\n]*\|\s*"/', $source, $script . ': awk programs run no commands');

            self::assertDoesNotMatchRegularExpression('/\bd(ocker)?\s+(exec|run|pull|push|rm|rmi|stop|start|restart|kill|create|cp|update|network\s+(create|connect|disconnect|rm)|compose\s+(up|down|run|exec|restart))\b/', $code, $script);
            self::assertDoesNotMatchRegularExpression('/\b(iptables|ip6tables)\s+-[AIDRFXNPZ]\b|\bnft\s+(add|delete|flush|insert|replace)\b|\bufw\s+(allow|deny|enable|disable|delete|reset)\b/', $code, $script);
            self::assertDoesNotMatchRegularExpression('/\bsystemctl\s+(start|stop|restart|reload|enable|disable|mask|edit|kill|daemon-reload)\b/', $code, $script);
            self::assertDoesNotMatchRegularExpression('/\bcloudflared\s+(tunnel\s+)?(login|create|delete|route|run|token|update|service)\b/', $code, $script);
            self::assertDoesNotMatchRegularExpression('/\bsudo\s+(?!-n\b)(?=[\w-])/', $code, $script . ': sudo only as "sudo -n" (never prompts)');
            self::assertDoesNotMatchRegularExpression('/\b(curl|wget|nc|ncat|nmap|ping)\b/', $code, $script . ': no network probes');
            self::assertDoesNotMatchRegularExpression('/(^|[;|&(\s])(env|printenv|set)(\s*$|\s*[|;)])|\/proc\/[^ ]*environ/m', $code, $script . ': no environment dumps');
            self::assertStringContainsString('umask 077', $code);
            self::assertStringContainsString('Refusing to overwrite', $source);
            self::assertStringContainsString('</dev/null', $code, $script . ': commands never wait for input');
        }
    }

    public function testIngressReportWithholdsSecrets(): void
    {
        $this->stubCommonTools();
        file_put_contents($this->tmp . '/traefik/dynamic.yml', implode("\n", [
            'http:', '  routers:', '    library:', '      rule: "Host(`library.example.org`)"', '      service: library',
            '  services:', '    library:', '      loadBalancer:', '        servers:', '          - url: "http://192.168.1.50:8080"',
            '    legacy:', '      loadBalancer:', '        servers:', '          - url: "http://admin:hunter2@192.168.1.60:80"',
            '  middlewares:', '    auth:', '      basicAuth:', '        users:', '          - "admin:$apr1$H6uskkkW$IgXLP6ewTrSuBkTrqE8wj/"',
            '    api:', '      headers:', '        customRequestHeaders:', '          X-Api-Key: "SUPERSECRETAPIKEYVALUE"', '',
        ]));
        $report = $this->runScript('ingress-host.sh', ['192.168.1.50']);

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $report, $secret);
        }
        self::assertStringContainsString('--token=<withheld>', $report);
        self::assertStringContainsString('TUNNEL_TOKEN PATH ORIGIN_PASS', $report, 'Variable names (only) are reported');
        self::assertStringContainsString('--entrypoints.web.address=:80', $report);
        self::assertStringContainsString('--entrypoints.websecure.forwardedheaders.trustedips=173.245.48.0/20', $report);
        self::assertStringContainsString('--certificatesresolvers.le.acme.email=<withheld>', $report);
        self::assertStringContainsString('url: "http://192.168.1.50:8080"', $report);
        self::assertStringContainsString('rule: "Host(`library.example.org`)"', $report);
        self::assertStringContainsString('withheld', $report);
        self::assertStringContainsString('non-public address 192.168.1.52 seen 1 time(s)', $report);
        self::assertStringContainsString('public=1', $report, 'Public clients are counted, not listed');
        self::assertStringContainsString('traefik.http.routers.app.rule=Host(`library.example.org`)', $report);
        self::assertStringContainsString('manual review checklist', $report);
    }

    public function testDockerHostReportWithholdsSecrets(): void
    {
        $this->stubCommonTools();
        file_put_contents($this->tmp . '/nginx/site.conf', implode("\n", [
            'server {', '    listen 80;', '    server_name app.example.org;', '    set_real_ip_from 192.168.1.52;', '    real_ip_header X-Forwarded-For;',
            '    # set_real_ip_from 10.0.0.1;', '    proxy_set_header Authorization "Bearer SUPERSECRETBEARER";', '    auth_basic_user_file /etc/nginx/htpasswd;',
            '    proxy_pass http://app:9000;', '    proxy_pass http://user:hunter2@legacy:80;', '}', '',
        ]));
        file_put_contents($this->tmp . '/nginx/secret.key', "set_real_ip_from SUPERSECRET;\n");
        $report = $this->runScript('docker-host.sh', []);

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $report, $secret);
        }
        self::assertStringContainsString('set_real_ip_from 192.168.1.52;', $report);
        self::assertStringContainsString('real_ip_header X-Forwarded-For;', $report);
        self::assertStringContainsString('proxy_pass http://app:9000;', $report);
        self::assertStringNotContainsString('10.0.0.1', $report, 'Comment lines are skipped');
        self::assertStringContainsString('matching line(s) withheld', $report);
        self::assertStringContainsString('published: 443/tcp -> 0.0.0.0:443', $report);
        self::assertStringContainsString('-- proxy driver=bridge', $report);
    }

    private function runScript(string $script, array $args): string
    {
        $report = $this->tmp . '/report.txt';
        [$status, $out, $err] = $this->exec(array_merge(['bash', dirname(__DIR__, 3) . '/docker/discovery/' . $script], $args), ['DLA_REPORT' => $report, 'DLA_SUDO' => 'no', 'DLA_TIMEOUT' => '10']);
        self::assertSame(0, $status, $out . $err);
        self::assertSame('600', substr(sprintf('%o', fileperms($report)), -3), 'Owner-only report');

        [$again] = $this->exec(['bash', dirname(__DIR__, 3) . '/docker/discovery/' . $script], ['DLA_REPORT' => $report, 'DLA_SUDO' => 'no']);
        self::assertSame(1, $again, 'An existing report is never overwritten');

        return (string) file_get_contents($report);
    }

    private function stubCommonTools(): void
    {
        $dir = $this->tmp;
        $token = self::TOKEN;
        $this->stub('docker', <<<SH
            case "\$1" in
                info) echo 27.0.0 ;;
                version) echo "server 27.0.0 (API 1.47)" ;;
                compose) echo 2.29.0 ;;
                port) [ "\$2" = proxy-nginx ] || [ "\$2" = traefik ] && echo "443/tcp -> 0.0.0.0:443" ;;
                ps)
                    case "\$*" in
                        *status=exited*) ;;
                        *" -a "*) echo "  cf cloudflare/cloudflared:2026.1.0 Up"; echo "  traefik traefik:v3.2 Up" ;;
                        *) printf 'cf\\ntraefik\\nproxy-nginx\\n' ;;
                    esac ;;
                network)
                    if [ "\$2" = ls ]; then echo proxy; else echo "-- proxy driver=bridge internal=false attachable=false"; fi ;;
                logs) printf '203.0.113.7 - - [x] "GET / HTTP/2.0" 200\\n192.168.1.52 - - [x] "GET /x HTTP/1.1" 200\\n' ;;
                inspect)
                    name="\$2"; fmt="\$4"
                    case "\$fmt" in
                        '{{.Config.Image}}')
                            case "\$name" in cf) echo cloudflare/cloudflared:2026.1.0 ;; traefik) echo traefik:v3.2 ;; *) echo nginx:1.30 ;; esac ;;
                        *Entrypoint*) echo "cloudflared --no-autoupdate tunnel run --token {$token}" ;;
                        *Config.Env*) printf 'TUNNEL_TOKEN={$token}\\nPATH=/usr/bin\\nORIGIN_PASS=hunter2\\n' ;;
                        *'.Config.Labels}}'*) printf '%s\\n' 'traefik.http.routers.app.rule=Host(`library.example.org`)' 'traefik.http.middlewares.auth.basicauth.users=admin:\$apr1\$SECRETHASH' ;;
                        *'eq .Type'*)
                            case "\$name" in traefik) echo "{$dir}/traefik" ;; proxy-nginx) echo "{$dir}/nginx" ;; esac ;;
                        *Mounts*) echo "    bind /srv/config -> /etc/config (ro)" ;;
                        *Config.Cmd*) echo "traefik --entrypoints.web.address=:80 --certificatesresolvers.le.acme.email=owner@example.org --providers.file.directory=/etc/traefik/dynamic --entrypoints.websecure.forwardedheaders.trustedips=173.245.48.0/20" ;;
                        *) echo "  network-mode=bridge restart=unless-stopped" ;;
                    esac ;;
            esac
            exit 0
            SH);
        $this->stub('systemctl', '[ "$1" = is-active ] && echo inactive; exit 0');
        $this->stub('pgrep', 'exit 1');
        $this->stub('sudo', 'exit 1');
        $this->stub('ss', 'echo "LISTEN 0 4096 0.0.0.0:443 0.0.0.0:*"');
        $this->stub('ip', 'echo "eth0 UP 192.168.1.52/24"');
    }

    private function stub(string $name, string $body): void
    {
        file_put_contents($this->tmp . '/bin/' . $name, "#!/bin/sh\n{$body}\n");
        chmod($this->tmp . '/bin/' . $name, 0700);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function exec(array $command, array $env = []): array
    {
        $env += ['PATH' => $this->tmp . '/bin:/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'HOME' => $this->tmp];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->tmp, $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
