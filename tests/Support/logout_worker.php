<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use DigitalLibrary\Core\Application;
use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Security\Session;
use DigitalLibrary\Tests\Support\TestDatabaseGuard;

final class FailingLogoutStatement extends PDOStatement
{
    public static ?Session $session = null;

    public function execute(?array $params = null): bool
    {
        if (str_starts_with($this->queryString, 'INSERT INTO audit_log')) {
            if (self::$session?->has('auth_user_id')) {
                throw new RuntimeException('Audit ran before invalidation.');
            }
            // Deterministic real server FK failure during INSERT execution.
            $params['user_id'] = 4294967295;
        }

        return parent::execute($params);
    }
}

$job = json_decode((string) getenv('DLA_LOGOUT_JOB'), true, 32, JSON_THROW_ON_ERROR);
$v = $job['config'];
if (($v['APP_ENV'] ?? '') !== 'testing' || ($v['DB_HOST'] ?? '') !== '127.0.0.1'
    || (int) ($v['DB_PORT'] ?? 3306) === 3306 || !preg_match('/^[a-z0-9_]+_test$/D', $v['DB_DATABASE'] ?? '')) {
    throw new RuntimeException('Unsafe logout test destination.');
}
$config = new Config($v);
$pdo = ConnectionFactory::fromConfig($config);
TestDatabaseGuard::verifyConnection($pdo, $v['DB_DATABASE'], 'not_used', true);
ini_set('session.save_path', $job['sessions']);
ini_set('session.gc_probability', '0');
$session = new Session(secureCookie: false);
$services = new Services($config, dirname(__DIR__, 2));
$services->set(PDO::class, $pdo);
$services->set(Session::class, $session);
$session->start();
$services->auth()->login($services->users()->findById((int) $job['actor']));
$oldId = $session->id();
$token = $services->csrf()->token();
if ($job['mode'] === 'execute') {
    FailingLogoutStatement::$session = $session;
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FailingLogoutStatement::class]);
}
$request = new Request('POST', '/logout', [], ['_csrf' => $job['mode'] === 'csrf' ? 'invalid' : $token], ['HTTP_HOST' => 'library.test', 'REMOTE_ADDR' => '192.0.2.10']);
$response = (new Application($services))->handle($request);
$result = [
    'status' => $response->status(), 'location' => $response->header('Location'),
    'authenticated' => $session->has('auth_user_id'), 'rotated' => $session->id() !== $oldId,
    'csrf_rotated' => $services->csrf()->token() !== $token,
    'flashes' => $session->takeFlashes(), 'body' => $response->body(),
];
session_write_close();
// Replay the old cookie against the real file handler and a fresh container.
session_id($oldId);
$next = new Session(secureCookie: false);
$fresh = new Services($config, dirname(__DIR__, 2));
$fresh->set(PDO::class, $pdo);
$fresh->set(Session::class, $next);
$response = (new Application($fresh))->handle(new Request('GET', '/account/password', [], [], ['HTTP_HOST' => 'library.test']));
$result['next_status'] = $response->status();
$result['next_location'] = $response->header('Location');
$result['next_authenticated'] = $next->has('auth_user_id');
session_write_close();
echo json_encode($result, JSON_THROW_ON_ERROR);
