<?php
/**
 * app\Video — a video library as a connection: the two signatures the service checks (an upload,
 * a player address), what its status numbers mean to a page, and a connection type that proves
 * its fields before it calls out.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\Video;

class VideoTest extends TestCase {
    private const ID = '0f2c1a3e-9b7d-4c21-8a55-3e1f6d9b2c40';

    public function testTheUploadSignatureIsTheServicesFormula(): void {
        // SHA256(library_id + api_key + expiration_time + video_id), as Bunny's tus upload reference states it.
        $this->assertSame(hash('sha256', '123456' . 'api-key' . '1790000000' . self::ID), Video::uploadSignature('123456', 'api-key', 1790000000, self::ID));
        $this->assertNotSame(Video::uploadSignature('123456', 'api-key', 1790000000, self::ID), Video::uploadSignature('123456', 'api-key', 1790000001, self::ID));
    }

    public function testThePlayerTokenIsTheServicesFormula(): void {
        // SHA256_HEX(token_security_key + video_id + expiration), as its token authentication page states it.
        $this->assertSame(hash('sha256', 'token-key' . self::ID . '1790000000'), Video::embedToken('token-key', self::ID, 1790000000));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Video::embedToken('token-key', self::ID, 1790000000));
    }

    public function testAStatusNumberBecomesWhatAPageShows(): void {
        $v = fn(int $status, array $more = []) => Video::describe(['guid' => self::ID, 'status' => $status, 'encodeProgress' => 40] + $more);
        $this->assertSame('waiting', $v(0)['state']);
        foreach ([1, 2, 3, 7] as $n) $this->assertSame('processing', $v($n)['state'], "status {$n}");
        $this->assertSame(40, $v(3)['progress']);
        $ready = $v(4, ['length' => 93, 'width' => 1920, 'height' => 1080, 'storageSize' => 5000, 'title' => 'Potluck', 'thumbnailUrl' => 'https://vz-x.b-cdn.net/t.jpg']);
        $this->assertSame(['ready', 100, 93, 1920, 1080, 5000, 'Potluck', 'https://vz-x.b-cdn.net/t.jpg'],
            [$ready['state'], $ready['progress'], $ready['seconds'], $ready['width'], $ready['height'], $ready['bytes'], $ready['title'], $ready['thumbnail']]);
        foreach ([5, 6] as $n) $this->assertSame('failed', $v($n)['state'], "status {$n}");
        foreach (array_unique(array_column(array_map($v, [0, 1, 4, 5]), 'state')) as $state) $this->assertArrayHasKey($state, Video::STATE_LABELS);
    }

    public function testAStatusTheAppDoesNotKnowIsAnErrorNotProcessing(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/status this app does not know/');
        Video::describe(['guid' => self::ID, 'status' => 42]);
    }

    public function testOnlyAVideoIdIsAVideoId(): void {
        $this->assertTrue(Video::isId(self::ID));
        foreach (['', '12', '../library/9', self::ID . '/x', 'not-a-guid-at-all-not-a-guid-at-all-00'] as $no) $this->assertFalse(Video::isId($no), $no);
    }

    public function testTheConnectionTypeIsOfferedAndProvesItsFieldsBeforeItCallsOut(): void {
        $c = \app\services\connectors\ConnectorRegistry::get('bunnystream');
        $this->assertNotNull($c, 'Bunny Stream is a connector every app has');
        $this->assertSame(['library_id', 'token_key'], array_column($c->meta()['fields'], 'name'));
        $this->assertSame('password', $c->meta()['fields'][1]['type'], 'the token key is a secret: never drawn as plain text');
        foreach ([['', ['library_id' => '123'], '/API key is required/'],
                  ['key', ['library_id' => ''], '/library ID is the number/'],
                  ['key', ['library_id' => 'abc'], '/library ID is the number/'],
                  ['key', ['library_id' => '12/../3'], '/library ID is the number/']] as [$key, $opts, $re]) {
            try { $c->validateApiKey($key, $opts); $this->fail('accepted ' . json_encode($opts)); }
            catch (\Exception $e) { $this->assertMatchesRegularExpression($re, $e->getMessage()); }
        }
        $role = \app\ConnectionBindings::role(\app\ConnectionBindings::CORE, 'video');
        $this->assertSame(['bunnystream'], $role['types']);
    }

    public function testBunnyStorageIsAnS3ProviderWithNoPolicyOfItsOwn(): void {
        $s3 = \app\services\connectors\S3Connector::class;
        $this->assertSame('https://ny-s3.storage.bunnycdn.com', $s3::endpointFor('bunny', 'ny'));
        $this->assertFalse($s3::hasPolicy('bunny'), 'Bunny Storage takes no bucket policy: public files come from a Pull Zone');
        foreach (['wasabi', 'aws', 'r2', 'b2', 'digitalocean', 'other'] as $p) $this->assertTrue($s3::hasPolicy($p), $p);
    }
}
