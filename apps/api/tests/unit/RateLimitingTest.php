<?php

namespace Tests\Unit;

use App\Filters\Throttle;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Validates Gate 3D: Rate limiting filter behavior, 429 status,
 * Retry-After header injection, RFC 9457 problem payload,
 * and bucket isolation per IP and URI path.
 */
class RateLimitingTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear cache service
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        service('cache')->clean();
        unset($_SERVER['REMOTE_ADDR']);
    }

    private function createRequest(string $path, string $ip = '127.0.0.1'): IncomingRequest
    {
        $uri = new URI('http://localhost:8080' . $path);
        $request = new IncomingRequest(new App(), $uri, 'php://input', new UserAgent());
        $request->setMethod('GET');
        // Simulate client IP
        $_SERVER['REMOTE_ADDR'] = $ip;
        $request->setGlobal('server', ['REMOTE_ADDR' => $ip]);
        return $request;
    }

    public function testRequestsUnderLimitPassThrough(): void
    {
        $throttle = new Throttle();
        $request = $this->createRequest('/api/v1/tenders', '192.168.1.10');

        // Allow up to 5 requests
        for ($i = 0; $i < 5; $i++) {
            $result = $throttle->before($request, ['5']);
            $this->assertNull($result, "Request #{$i} should pass through (return null).");
        }
    }

    public function testBreachingLimitReturns429WithRetryAfterHeader(): void
    {
        $throttle = new Throttle();
        $request = $this->createRequest('/api/v1/auth/login', '192.168.1.20');
        $limit = 3;

        // Exhaust quota
        for ($i = 0; $i < $limit; $i++) {
            $result = $throttle->before($request, [(string) $limit]);
            $this->assertNull($result);
        }

        // Limit is now reached. Next request must be throttled.
        $blocked = $throttle->before($request, [(string) $limit]);
        $this->assertNotNull($blocked, 'Exceeded request must be blocked.');
        $this->assertSame(429, $blocked->getStatusCode());

        // Must include Retry-After: 60 header
        $this->assertTrue($blocked->hasHeader('Retry-After'));
        $this->assertSame('60', $blocked->getHeaderLine('Retry-After'));

        // Body must conform to RFC 9457 Problem Details
        $body = json_decode($blocked->getBody(), true);
        $this->assertSame('too_many_requests', $body['reason']);
        $this->assertSame(429, $body['status']);
        $this->assertSame(60, $body['retry_after']);
    }

    public function testBucketIsolationAcrossDifferentIps(): void
    {
        $throttle = new Throttle();
        $limit = 2;

        $reqIp1 = $this->createRequest('/api/v1/auth/register', '10.0.0.1');
        $reqIp2 = $this->createRequest('/api/v1/auth/register', '10.0.0.2');

        // Exhaust IP 1
        $throttle->before($reqIp1, [(string) $limit]);
        $throttle->before($reqIp1, [(string) $limit]);
        $blockedIp1 = $throttle->before($reqIp1, [(string) $limit]);
        $this->assertNotNull($blockedIp1);
        $this->assertSame(429, $blockedIp1->getStatusCode());

        // IP 2 must NOT be blocked
        $resultIp2 = $throttle->before($reqIp2, [(string) $limit]);
        $this->assertNull($resultIp2, 'Different IP must have independent rate limiting bucket.');
    }

    public function testBucketIsolationAcrossDifferentPaths(): void
    {
        $throttle = new Throttle();
        $limit = 2;

        $reqPath1 = $this->createRequest('/api/v1/auth/login', '172.16.0.5');
        $reqPath2 = $this->createRequest('/api/v1/tenders', '172.16.0.5');

        // Exhaust login path
        $throttle->before($reqPath1, [(string) $limit]);
        $throttle->before($reqPath1, [(string) $limit]);
        $blockedPath1 = $throttle->before($reqPath1, [(string) $limit]);
        $this->assertNotNull($blockedPath1);
        $this->assertSame(429, $blockedPath1->getStatusCode());

        // Tenders path must NOT be blocked for same IP
        $resultPath2 = $throttle->before($reqPath2, [(string) $limit]);
        $this->assertNull($resultPath2, 'Different path must have independent rate limiting bucket.');
    }
}
