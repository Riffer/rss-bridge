<?php

declare(strict_types=1);

namespace RssBridge\Tests;

use PHPUnit\Framework\TestCase;

class GoComicsBridgeTest extends TestCase
{
    public function testOpenCircuitBlocksFeedRequests(): void
    {
        $cache = new \ArrayCache();
        $retryAt = time() + 60;
        $cache->set('GoComicsBridge_circuit_breaker', $retryAt, 60);
        $bridge = new \GoComicsBridge($cache, new \NullLogger());
        $bridge->setInput(['comicname' => 'calvinandhobbes']);

        $this->expectException(\RateLimitException::class);
        $this->expectExceptionMessageMatches(
            '/GoComics requests are paused until ' . gmdate('Y-m-d H:i:s', $retryAt) . ' UTC \(about \d+ seconds remaining\)\./'
        );
        $bridge->collectData();
    }

    public function testForbiddenResponseOpensCircuitForSixHours(): void
    {
        $cache = $this->createMock(\CacheInterface::class);
        $cache->expects($this->once())
            ->method('set')
            ->with('GoComicsBridge_circuit_breaker', $this->isType('int'), 21600);

        $bridge = new \GoComicsBridge($cache, new \NullLogger());

        $this->expectException(\Exception::class);
        $this->invokeHttpExceptionHandler($bridge, new \HttpException('', 403, new \Response('', 403)));
    }

    public function testRateLimitUsesRetryAfterHeader(): void
    {
        $cache = $this->createMock(\CacheInterface::class);
        $cache->expects($this->once())
            ->method('set')
            ->with('GoComicsBridge_circuit_breaker', $this->isType('int'), 120);

        $bridge = new \GoComicsBridge($cache, new \NullLogger());

        $this->expectException(\RateLimitException::class);
        $this->invokeHttpExceptionHandler(
            $bridge,
            new \HttpException('', 429, new \Response('', 429, ['Retry-After' => '120']))
        );
    }

    public function testOtherHttpErrorsDoNotOpenCircuit(): void
    {
        $cache = $this->createMock(\CacheInterface::class);
        $cache->expects($this->never())->method('set');
        $bridge = new \GoComicsBridge($cache, new \NullLogger());

        $this->expectException(\HttpException::class);
        $this->invokeHttpExceptionHandler($bridge, new \HttpException('', 500, new \Response('', 500)));
    }

    private function invokeHttpExceptionHandler(\GoComicsBridge $bridge, \HttpException $exception): void
    {
        $method = new \ReflectionMethod(\GoComicsBridge::class, 'handleHttpException');
        $method->setAccessible(true);
        $method->invoke($bridge, $exception);
    }
}