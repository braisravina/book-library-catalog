<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('gd')]
final class CoverTest extends IntegrationTestCase
{
    private int $memberId;
    private int $editionId;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberId = $this->createUser('member@example.org', Role::Member);
        $this->editionId = $this->makeBook($this->memberId, 'Covered')['edition_id'];
        self::$trustUploads = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
    }

    public function testUploadServeAndCacheValidation(): void
    {
        [$browser, $token] = $this->signedIn('member@example.org');
        $response = $this->upload($browser, $token, $this->image(800, 1200));
        self::assertSame(303, $response->status());

        $cover = $this->pdo()->query('SELECT * FROM covers')->fetch();
        self::assertSame(1, (int) $cover['is_primary']);
        self::assertSame('image/jpeg', $cover['mime_type']);
        self::assertFileExists(self::coverDirectory() . '/' . $cover['storage_key']);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'cover.uploaded'"));

        $full = $this->request($browser, 'GET', '/covers/' . $cover['id']);
        self::assertSame(200, $full->status());
        self::assertSame('image/jpeg', $full->header('Content-Type'));
        self::assertSame('nosniff', $full->header('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $full->header('Content-Security-Policy'));
        self::assertStringStartsWith('private', (string) $full->header('Cache-Control'));
        self::assertSame("\xFF\xD8\xFF", substr($full->body(), 0, 3), 'JPEG magic bytes');

        $thumb = $this->request($browser, 'GET', '/covers/' . $cover['id'] . '?size=thumb');
        self::assertLessThan(strlen($full->body()), strlen($thumb->body()));
        self::assertNotSame($full->header('ETag'), $thumb->header('ETag'));

        $notModified = $this->request($browser, 'GET', '/covers/' . $cover['id'], [], ['HTTP_IF_NONE_MATCH' => (string) $full->header('ETag')]);
        self::assertSame(304, $notModified->status());
        self::assertSame('', $notModified->body());

        self::assertStringContainsString('src="/covers/' . $cover['id'] . '?size=thumb"', $this->request($browser, 'GET', '/books')->body());
    }

    public function testNewUploadBecomesPrimaryAndDeletionPromotesAndRemovesFiles(): void
    {
        [$browser, $token] = $this->signedIn('member@example.org');
        $this->upload($browser, $token, $this->image(100, 150));
        $this->upload($browser, $token, $this->image(120, 180));
        $rows = $this->pdo()->query('SELECT id, is_primary, storage_key FROM covers ORDER BY id')->fetchAll();
        self::assertSame([0, 1], array_map('intval', array_column($rows, 'is_primary')));

        $response = $this->request($browser, 'POST', '/covers/' . $rows[1]['id'] . '/delete', ['_csrf' => $token]);
        self::assertSame(303, $response->status());
        self::assertSame(1, $this->countRows("SELECT is_primary FROM covers WHERE id = {$rows[0]['id']}"), 'Remaining cover promoted');
        $deleted = self::coverDirectory() . '/' . $rows[1]['storage_key'];
        self::assertFileDoesNotExist($deleted);
        self::assertFileDoesNotExist(substr($deleted, 0, -4) . '.thumb.jpg');
    }

    public function testMaliciousUploadsAreRejectedWithoutSideEffects(): void
    {
        [$browser, $token] = $this->signedIn('member@example.org');
        $before = $this->filesInStore();

        $php = $this->tempFile('<?php echo "pwned"; ?>');
        $response = $this->request($browser, 'POST', "/editions/{$this->editionId}/covers", ['_csrf' => $token], [], [
            'cover' => ['name' => 'cover.jpg', 'type' => 'image/jpeg', 'tmp_name' => $php, 'error' => UPLOAD_ERR_OK, 'size' => filesize($php)],
        ]);
        self::assertSame(303, $response->status());
        self::assertStringContainsString('Upload a JPEG, PNG or WebP image.', $this->request($browser, 'GET', "/editions/{$this->editionId}")->body());

        $missing = $this->request($browser, 'POST', "/editions/{$this->editionId}/covers", ['_csrf' => $token]);
        self::assertSame(303, $missing->status());

        self::$trustUploads = false; // a path that did not arrive via HTTP upload
        $this->upload($browser, $token, $this->image(50, 50));

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM covers'));
        self::assertSame($before, $this->filesInStore());
    }

    public function testOnlyRecordEditorsManageCoversButEveryoneSignedInCanView(): void
    {
        $this->createUser('other@example.org', Role::Member);
        $this->createUser('viewer@example.org', Role::Viewer);
        [$owner, $token] = $this->signedIn('member@example.org');
        $this->upload($owner, $token, $this->image(60, 90));
        $coverId = $this->countRows('SELECT id FROM covers');

        [$other, $otherToken] = $this->signedIn('other@example.org');
        self::assertSame(403, $this->upload($other, $otherToken, $this->image(60, 90))->status());
        self::assertSame(403, $this->request($other, 'POST', "/covers/{$coverId}/delete", ['_csrf' => $otherToken])->status());
        self::assertSame(200, $this->request($other, 'GET', "/covers/{$coverId}")->status());

        [$viewer] = $this->signedIn('viewer@example.org');
        self::assertSame(200, $this->request($viewer, 'GET', "/covers/{$coverId}")->status());
        self::assertSame(303, $this->request($this->browser(), 'GET', "/covers/{$coverId}")->status(), 'Guests never receive images');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM covers'));
    }

    private function upload(\DigitalLibrary\Security\Session $browser, string $token, string $file): \DigitalLibrary\Http\Response
    {
        return $this->request($browser, 'POST', "/editions/{$this->editionId}/covers", ['_csrf' => $token], [], [
            'cover' => ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)],
        ]);
    }

    private function image(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 200; $i++) {
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
        }
        $file = $this->tempFile('');
        imagepng($img, $file);

        return $file;
    }

    private function tempFile(string $contents): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cov');
        file_put_contents($file, $contents);
        $this->temp[] = $file;

        return $file;
    }

    /**
     * @return list<string>
     */
    private function filesInStore(): array
    {
        $files = glob(self::coverDirectory() . '/*/*') ?: [];
        sort($files);

        return $files;
    }
}
