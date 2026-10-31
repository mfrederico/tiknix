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

    public function testCloudflareR2HasNoBucketPolicyButTakesCors(): void {
        $c = \app\services\connectors\S3Connector::class;
        $this->assertFalse($c::hasPolicy('r2'));
        $this->assertTrue($c::hasCors('r2'), 'browser uploads are still set from the page');
        $this->assertFalse($c::hasPolicy('bunny'));
        $this->assertFalse($c::hasCors('bunny'));
        $this->assertTrue($c::hasPolicy('wasabi'));
        $this->assertTrue($c::hasCors('wasabi'));
    }

    public function testOnlyR2sOwnRefusalCountsAsARefusalAt400(): void {
        $r2 = '<?xml version="1.0"?><Error><Code>InvalidArgument</Code><Message>Authorization</Message></Error>';
        $this->assertTrue(\app\Storage::refused(400, $r2, true, 'r2'));
        $this->assertFalse(\app\Storage::refused(400, '<Error><Code>InvalidRequest</Code></Error>', true, 'r2'), 'any other 400 is still a fault');
        $this->assertFalse(\app\Storage::refused(400, $r2, true, 'wasabi'), 'only R2 refuses this way');
        $this->assertFalse(\app\Storage::refused(400, $r2, false, 'r2'), 'only at its own address');
        $this->assertFalse(\app\Storage::refused(200, 'private', true, 'r2'), 'the file itself is never a refusal');
        foreach ([401, 403] as $code) $this->assertTrue(\app\Storage::refused($code, '', true, 'wasabi'));
        $this->assertTrue(\app\Storage::refused(404, '', false, 'wasabi'), 'a CDN that will not serve it');
        $this->assertFalse(\app\Storage::refused(404, '', true, 'wasabi'), 'not found at the store itself proves nothing');
    }

    public function testTheHelpNamesWhatStandsInFrontAndTheRuleComesFirst(): void {
        $none = \app\Storage::noPolicyHelp('r2', false);
        $this->assertStringContainsString('custom domain', $none);
        $this->assertStringContainsString('BEFORE you turn it on', $none);
        $this->assertStringContainsString('/private/', $none);
        $this->assertStringNotContainsString('Pull Zone', $none);
        $this->assertStringContainsString('Pull Zone', \app\Storage::noPolicyHelp('bunny', true));
    }

    /** The storage page, for each kind of store: drawn for real, so a mistake in the view fails here. */
    private function storagePage(array $over): string {
        $storage = $over + ['bucket' => 'b', 'alias' => 'a', 'public_url' => '', 'provider' => 'r2', 'policy' => false, 'cors' => true];
        $problem = ''; $applied = false; $policy = '{}'; $origins = ['https://app.example.com']; $check = [];
        ob_start();
        try { include \app\Paths::runtime() . '/views/connections/storage.php'; }
        finally { $html = (string) ob_get_clean(); }
        return $html;
    }

    public function testTheStoragePageDrawsForEveryKindOfStore(): void {
        $r2 = $this->storagePage([]);
        $this->assertStringContainsString('Public files do not open yet.', $r2);
        $this->assertStringContainsString('Allow uploads from this site', $r2);
        $this->assertStringNotContainsString('Apply the access template', $r2);
        $this->assertStringContainsString('None yet', $r2);
        $withDomain = $this->storagePage(['public_url' => 'https://media.example.com']);
        $this->assertStringContainsString('This store has no access template to apply.', $withDomain);
        $this->assertStringContainsString('https://media.example.com', $withDomain);
        $bunny = $this->storagePage(['provider' => 'bunny', 'cors' => false]);
        $this->assertStringNotContainsString('Allow uploads from this site', $bunny);
        $this->assertStringContainsString('Pull Zone', $bunny);
        $wasabi = $this->storagePage(['provider' => 'wasabi', 'policy' => true]);
        $this->assertStringContainsString('Apply the access template', $wasabi);
        $this->assertStringNotContainsString('Allow uploads from this site', $wasabi);
    }
}
