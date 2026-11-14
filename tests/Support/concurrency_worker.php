<?php

declare(strict_types=1);

/*
 * A second process for the concurrency tests
 * (tests/Integration/HouseholdWriteConcurrencyTest.php): real parallelism
 * needs a second connection that can wait on a lock while the test keeps
 * running. The job comes as JSON in the DLA_WORKER environment variable
 * (never on the command line: it holds the disposable test database's
 * credentials). It only ever connects to the database the parent test
 * was allowed to use (TestDatabaseGuard has already vetted it).
 *
 * Modes
 *   script  runs "first" statements in a transaction, prints "ready", waits
 *           for a line on stdin, runs "second", commits and prints "done".
 *   copy    waits until "start" (a microtime) and adds a copy through
 *           Catalogue\CopyService; prints ok, taken or busy.
 *   lend    waits until "start" and lends a copy through Loans\LoanService;
 *           prints ok or the refusal.
 */

use DigitalLibrary\Catalogue\CopyValidationException;
use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Households\WriteBusyException;
use DigitalLibrary\Loans\LoanException;
use DigitalLibrary\Loans\LoanInput;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
$job = json_decode((string) getenv('DLA_WORKER'), true, 16, JSON_THROW_ON_ERROR);
if (!str_ends_with((string) $job['config']['DB_DATABASE'], '_test')) {
    fwrite(STDERR, "refusing: not a test database\n");
    exit(2);
}
$services = new Services(new Config(array_map('strval', $job['config'])), dirname(__DIR__, 2));
$pdo = $services->pdo();
$waitUntil = static function (float $start): void {
    $delay = $start - microtime(true);
    if ($delay > 0) {
        usleep((int) ($delay * 1_000_000));
    }
};

try {
    switch ($job['mode']) {
        case 'script':
            $pdo->beginTransaction();
            foreach ($job['first'] as $sql) {
                $pdo->query($sql)->fetchAll();
            }
            echo "ready\n";
            fgets(STDIN);
            foreach ($job['second'] as $sql) {
                $pdo->query($sql)->fetchAll();
            }
            $pdo->commit();
            echo "done\n";
            break;

        case 'copy':
            $waitUntil((float) $job['start']);
            try {
                $services->copyService()->create(
                    (int) $job['actor'],
                    (int) $job['edition'],
                    new CopyInput((string) $job['actor'], '', (string) $job['code'], '', 'available', false, '', '', '', '', '', (string) $job['household']),
                );
                echo "ok\n";
            } catch (CopyValidationException $e) {
                echo isset($e->errors['inventory_code']) ? "taken\n" : 'refused:' . json_encode($e->errors) . "\n";
            } catch (WriteBusyException) {
                echo "busy\n";
            }
            break;

        case 'transfer':
            $waitUntil((float) $job['start']);
            try {
                $result = $services->inventoryTransfers()->apply((int) $job['actor'], (int) $job['operation'], (string) $job['digest']);
                echo $result['status'] . "\n";
            } catch (\DigitalLibrary\Households\TransferException $e) {
                echo $e->getMessage() . "\n";
            }
            break;

        case 'lend':
            $waitUntil((float) $job['start']);
            try {
                $services->loanService()->lend(
                    $services->users()->findById((int) $job['actor']),
                    (int) $job['copy'],
                    new LoanInput('user', (string) $job['borrower'], '', gmdate('Y-m-d'), '', ''),
                );
                echo "ok\n";
            } catch (LoanException $e) {
                echo $e->getMessage() . "\n";
            }
            break;

        default:
            throw new InvalidArgumentException('Unknown mode');
    }
} catch (Throwable $e) {
    echo 'error:' . $e::class . ':' . $e->getMessage() . "\n";
    exit(1);
}
