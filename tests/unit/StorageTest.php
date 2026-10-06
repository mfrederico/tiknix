<?php
/**
 * app\Storage — the access template: a file is public or private by the folder its key is in,
 * and the one rule that makes public/ readable is added to a bucket's policy without touching
 * what the policy already says.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\Storage;

class StorageTest extends TestCase {
    protected function setUp(): void { \Flight::set('app.baseurl', 'https://App.Example.test'); \app\Host::useHost(null); }
    protected function tearDown(): void { \app\Host::useHost(null); }

    public function testTheKeyCarriesTheAccessAndTheSiteAndCannotClimbOutOfItsFolder(): void {
        $this->assertSame('public/app.example.test/avatars/a.jpg', Storage::key('/avatars/a.jpg', Storage::PUBLIC_));
        $this->assertSame('private/app.example.test/records/7/x.pdf', Storage::key('records\\7\\x.pdf', Storage::PRIVATE_));
        $this->assertSame(Storage::PRIVATE_, Storage::accessOf('private/app.example.test/records/7/x.pdf'));
        foreach (['', '../secrets', 'a/../../b', './x', "a\0b"] as $bad) {
            try { Storage::key($bad, Storage::PUBLIC_); $this->fail('accepted ' . json_encode($bad)); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not a file path', $e->getMessage()); }
        }
        $this->expectException(\InvalidArgumentException::class);
        Storage::key('a.jpg', 'members');
    }

    public function testEachSiteOfAnAppFilesUnderItsOwnAddress(): void {
        $this->assertSame('app.example.test', Storage::siteFolder(), "the main site: the app's own address");
        \app\Host::useHost('second.example.org');
        $this->assertSame('public/second.example.org/photos/7/a.jpg', Storage::key('photos/7/a.jpg', Storage::PUBLIC_), 'another domain of the same app shares the bucket, not the folder');
        \app\Host::useHost(null);
        \Flight::set('app.baseurl', '');
        $this->expectExceptionMessage('no address to file its uploads under');
        Storage::siteFolder();
    }

    public function testAKeyThisAppDidNotMakeIsRefused(): void {
        $this->expectExceptionMessage('not a key this app stored');
        Storage::accessOf('backups/db.sql');
    }

    public function testAFileNameIsSafeAndNotGuessable(): void {
        $a = Storage::name('Holiday Photo (final) #2.JPG'); $b = Storage::name('Holiday Photo (final) #2.JPG');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}-holiday-photo-final-2\.jpg$/', $a);
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', Storage::name('../../'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}-evil\.php$/', Storage::name('../../evil.php'), 'a path is flattened to a name');
    }

    public function testTheRuleIsAddedOnceAndABucketsOwnRulesAreKept(): void {
        $fresh = json_decode(Storage::policyWith('', 'media.example.com'), true);
        $this->assertCount(2, $fresh['Statement']);
        $this->assertSame('arn:aws:s3:::media.example.com/public/*', $fresh['Statement'][0]['Resource']);
        $this->assertSame('s3:GetObject', $fresh['Statement'][0]['Action']);
        // …and a visitor with no credentials is refused everything else: only anonymous requests, so the app's key and its links still work.
        $deny = $fresh['Statement'][1];
        $this->assertSame(['Deny', 'arn:aws:s3:::media.example.com/public/*', 'Anonymous'], [$deny['Effect'], $deny['NotResource'], $deny['Condition']['StringEquals']['aws:PrincipalType']]);
        $this->assertArrayNotHasKey('Resource', $deny);

        $existing = json_encode(['Version' => '2012-10-17', 'Statement' => [['Sid' => 'TheirOwn', 'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:DeleteObject', 'Resource' => 'arn:aws:s3:::media.example.com/keep/*']]]);
        $once = Storage::policyWith($existing, 'media.example.com');
        $twice = json_decode(Storage::policyWith($once, 'media.example.com'), true);
        $this->assertSame(['TheirOwn', Storage::POLICY_SID, Storage::POLICY_SID_PRIVATE], array_column($twice['Statement'], 'Sid'), 'applied twice, still there once each');
        $single = json_decode(Storage::policyWith(json_encode(['Version' => '2012-10-17', 'Statement' => ['Sid' => 'Lone', 'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObject', 'Resource' => 'x']]), 'b'), true);
        $this->assertSame(['Lone', Storage::POLICY_SID, Storage::POLICY_SID_PRIVATE], array_column($single['Statement'], 'Sid'));
    }

    public function testAPolicyThatCannotBeReadIsLeftAlone(): void {
        $this->expectExceptionMessage('left untouched');
        Storage::policyWith('not json at all', 'b');
    }

    public function testAShareLinkLastsDaysNotForever(): void {
        $this->expectExceptionMessage('1 to 7 days');
        Storage::shareUrl('private/x.pdf', 30);
    }

    public function testTheConnectionTypeIsOfferedAndProvesItsFieldsBeforeItCallsOut(): void {
        $c = \app\services\connectors\ConnectorRegistry::get('s3');
        $this->assertNotNull($c, 'S3 storage is a connector every app has');
        $this->assertSame(['provider', 'region', 'bucket', 'access_key_id', 'endpoint', 'public_url'], array_column($c->meta()['fields'], 'name'));
        $this->assertSame('https://s3.us-east-1.wasabisys.com', \app\services\connectors\S3Connector::endpointFor('wasabi', 'us-east-1'));
        $this->assertSame('https://x.r2.cloudflarestorage.com', \app\services\connectors\S3Connector::endpointFor('r2', 'auto', 'https://x.r2.cloudflarestorage.com/'));
        foreach ([[['provider' => 'wasabi', 'region' => '', 'bucket' => 'b', 'access_key_id' => 'k'], '/region is required/'],
                  [['provider' => 'wasabi', 'region' => 'us-east-1', 'bucket' => '', 'access_key_id' => 'k'], '/bucket name is required/'],
                  [['provider' => 'r2', 'region' => 'auto', 'bucket' => 'b', 'access_key_id' => 'k'], '/no fixed address/'],
                  [['provider' => 'nope', 'region' => 'r', 'bucket' => 'b', 'access_key_id' => 'k'], '/Provider must be one of/']] as [$opts, $re]) {
            try { $c->validateApiKey('secret', $opts); $this->fail('accepted ' . json_encode($opts)); }
            catch (\Exception $e) { $this->assertMatchesRegularExpression($re, $e->getMessage()); }
        }
        $role = \app\ConnectionBindings::role(\app\ConnectionBindings::CORE, 'storage');
        $this->assertSame(['s3'], $role['types']);
    }
}
