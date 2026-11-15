<?php

declare(strict_types=1);

// Only the parent's vetted, synthetic loopback test database is accepted.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Households\WriteDeniedException;
use DigitalLibrary\Loans\LoanInput;
use DigitalLibrary\Security\Role;

final class PausedMutationStatement extends PDOStatement
{
    public static string $prefix = '';

    public function execute(?array $params = null): bool
    {
        if (self::$prefix !== '' && str_starts_with(ltrim($this->queryString), self::$prefix)) {
            self::$prefix = '';
            echo "authorized\n";
            fflush(STDOUT);
            fgets(STDIN);
        }
        return parent::execute($params);
    }
}

$job = json_decode((string) getenv('DLA_WORKER'), true, 32, JSON_THROW_ON_ERROR);
$config = $job['config'];
if (($config['DB_HOST'] ?? '') !== '127.0.0.1' || (int) ($config['DB_PORT'] ?? 3306) === 3306
    || !preg_match('/^[a-z0-9_]+_test$/D', $config['DB_DATABASE'] ?? '') || ($config['APP_ENV'] ?? '') !== 'testing') {
    throw new RuntimeException('Unsafe revocation worker destination.');
}
$services = new Services(new Config($config), dirname(__DIR__, 2));
$actor = $services->users()->findById((int) $job['actor']);
try {
    if ($job['mode'] === 'stale') {
        echo "captured\n";
        fflush(STDOUT);
        fgets(STDIN);
        if ($job['operation'] === 'membership') {
            $services->householdService()->addMember($actor, (int) $job['household'], (int) $job['target']);
        } else {
            $services->userAdministration()->create($actor, 'worker-created@example.org', 'Worker', Role::Admin, 'hash', 'en');
        }
    } else {
        $services->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PausedMutationStatement::class]);
        if ($job['operation'] === 'membership') {
            PausedMutationStatement::$prefix = 'INSERT INTO household_members';
            $services->householdService()->addMember($actor, (int) $job['household'], (int) $job['target']);
        } elseif ($job['operation'] === 'create') {
            PausedMutationStatement::$prefix = 'INSERT INTO users';
            $services->userAdministration()->create($actor, 'worker-created@example.org', 'Worker', Role::Admin, 'hash', 'en');
        } elseif ($job['operation'] === 'borrower') {
            PausedMutationStatement::$prefix = 'INSERT INTO loans';
            $services->loanService()->lend($actor, (int) $job['copy'], new LoanInput('user', (string) $job['target'], '', gmdate('Y-m-d'), '', ''));
        } else {
            PausedMutationStatement::$prefix = 'INSERT INTO copies';
            $services->copyService()->create($actor->id, (int) $job['edition'], new CopyInput((string) $job['target'], '', '', '', 'available', false, '', '', '', '', '', (string) $job['household']));
        }
    }
    echo "committed\n";
} catch (WriteDeniedException) {
    echo "denied\n";
}
