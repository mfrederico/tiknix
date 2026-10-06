<?php
/**
 * app\S3Client signs with AWS Signature Version 4. These are AWS's own published examples
 * (Amazon S3 API reference, "Signature Calculations … Examples"): if they hold, any
 * S3-compatible store accepts what this sends.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\S3Client;

class S3ClientTest extends TestCase {
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    private const HOST = 'examplebucket.s3.amazonaws.com';
    private const DATE = '20130524T000000Z';
    private const EMPTY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testAGetIsSignedAsAwsPublishes(): void {
        $h = ['host' => self::HOST, 'range' => 'bytes=0-9', 'x-amz-content-sha256' => self::EMPTY, 'x-amz-date' => self::DATE];
        $this->assertSame('f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            S3Client::signature('GET', '/test.txt', [], $h, self::EMPTY, 'us-east-1', self::SECRET, self::DATE));
    }

    public function testAPutWithABodyAndAnAwkwardKeyIsSignedAsAwsPublishes(): void {
        $hash = hash('sha256', 'Welcome to Amazon S3.');
        $this->assertSame('44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', $hash);
        $h = ['date' => 'Fri, 24 May 2013 00:00:00 GMT', 'host' => self::HOST, 'x-amz-content-sha256' => $hash, 'x-amz-date' => self::DATE, 'x-amz-storage-class' => 'REDUCED_REDUNDANCY'];
        $this->assertSame('/test%24file.text', '/' . S3Client::path('test$file.text'));
        $this->assertSame('98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
            S3Client::signature('PUT', '/' . S3Client::path('test$file.text'), [], $h, $hash, 'us-east-1', self::SECRET, self::DATE));
    }

    public function testAListingsQueryIsSortedAndSignedAsAwsPublishes(): void {
        $h = ['host' => self::HOST, 'x-amz-content-sha256' => self::EMPTY, 'x-amz-date' => self::DATE];
        $this->assertSame('max-keys=2&prefix=J', S3Client::query(['prefix' => 'J', 'max-keys' => '2']));
        $this->assertSame('34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7',
            S3Client::signature('GET', '/', ['max-keys' => '2', 'prefix' => 'J'], $h, self::EMPTY, 'us-east-1', self::SECRET, self::DATE));
    }

    public function testATimeLimitedLinkIsSignedAsAwsPublishes(): void {
        $q = ['X-Amz-Algorithm' => 'AWS4-HMAC-SHA256', 'X-Amz-Credential' => 'AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request',
              'X-Amz-Date' => self::DATE, 'X-Amz-Expires' => '86400', 'X-Amz-SignedHeaders' => 'host'];
        $this->assertSame('aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            S3Client::signature('GET', '/test.txt', $q, ['host' => self::HOST], 'UNSIGNED-PAYLOAD', 'us-east-1', self::SECRET, self::DATE));
    }

    public function testLinksArePathStyleSoABucketNamedLikeADomainWorks(): void {
        $c = new S3Client('https://s3.us-east-1.wasabisys.com/', 'us-east-1', 'media.example.com', 'AKIDEXAMPLE', 'secret');
        $this->assertSame('https://s3.us-east-1.wasabisys.com/media.example.com/public/a%20b/c.jpg', $c->url('public/a b/c.jpg'));
        $link = $c->presign('GET', 'private/x.pdf', 600, [], gmmktime(0, 0, 0, 5, 24, 2013));
        $this->assertStringStartsWith('https://s3.us-east-1.wasabisys.com/media.example.com/private/x.pdf?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIDEXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20130524T000000Z&X-Amz-Expires=600&X-Amz-SignedHeaders=host&X-Amz-Signature=', $link);
        $this->assertMatchesRegularExpression('/X-Amz-Signature=[0-9a-f]{64}$/', $link);
        $this->assertStringNotContainsString('secret', $link);
    }

    public function testAnEndpointThatIsNotAUrlOrAMissingKeyIsRefused(): void {
        foreach ([['s3.wasabisys.com', 'us-east-1', 'b', 'k', 's'], ['https://s3.wasabisys.com', '', 'b', 'k', 's'], ['https://s3.wasabisys.com', 'us-east-1', 'b', 'k', '']] as $a) {
            try { new S3Client(...$a); $this->fail('accepted ' . json_encode($a)); } catch (\InvalidArgumentException $e) { $this->assertStringStartsWith('S3:', $e->getMessage()); }
        }
    }
}
