<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class Throttle implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $limit  = (int) ($arguments[0] ?? 60);
        $bucket = 'throttle_' . md5($request->getIPAddress() . $request->getUri()->getPath());
        $cache  = service('cache');
        $hits   = (int) ($cache->get($bucket) ?? 0);

        if ($hits >= $limit) {
            $resp = problem(429, 'too_many_requests', 'Too many requests. Try again shortly.', [
                'retry_after' => 60,
            ]);
            return $resp->setHeader('Retry-After', '60');
        }

        $cache->save($bucket, $hits + 1, 60);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
