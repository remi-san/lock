<?php

namespace RemiSan\Lock\Test\Locker;

use Mockery\Mock;
use RemiSan\Lock\Locker\SingleStoreLocker;
use RemiSan\Lock\LockStore;
use RemiSan\Lock\Exceptions\LockingException;
use RemiSan\Lock\Exceptions\UnlockingException;
use RemiSan\Lock\Locker\MultipleStoreLocker;
use RemiSan\Lock\Lock;
use RemiSan\Lock\Quorum;
use RemiSan\Lock\TokenGenerator;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Stopwatch\StopwatchEvent;

class SingleStoreLockerTest extends \PHPUnit_Framework_TestCase
{
    /** @var LockStore | Mock */
    private $store;

    /** @var TokenGenerator | Mock */
    private $tokenGenerator;

    /** @var Stopwatch | Mock */
    private $stopwatch;

    /** @var int */
    private $retryDelay;

    /** @var int */
    private $retryCount;

    /** @var string */
    private $resource;

    /** @var int */
    private $ttl;

    /** @var string */
    private $token;

    /** @var StopwatchEvent | Mock */
    private $stopwatchEvent;

    /** @var int */
    private $originTime;

    /** @var MultipleStoreLocker */
    private $classUnderTest;

    /**
     * Init the mocks
     */
    public function setUp()
    {
        $this->store = \Mockery::mock(LockStore::class, function ($store) {
            /** @var Mock $store */
            $store->shouldReceive('getDrift')->andReturn(3);
        });

        $this->tokenGenerator = \Mockery::mock(TokenGenerator::class);

        $this->stopwatch = \Mockery::mock(Stopwatch::class);
        $this->stopwatchEvent = \Mockery::mock(StopwatchEvent::class);

        $this->retryCount = 3;
        $this->retryDelay = 100;

        $this->resource = 'resource';
        $this->ttl = 100;

        $this->token = 'token';

        $this->originTime = 333;

        $this->classUnderTest = new SingleStoreLocker(
            $this->store,
            $this->tokenGenerator,
            $this->stopwatch
        );
    }

    public function tearDown()
    {
        \Mockery::close();
    }

    /**
     * @test
     */
    public function itShouldLockTheResourceIfStoreHasBeenAbleToLockIt()
    {
        $this->itWillGenerateAToken();

        $this->itWillMeasureATimePassedLowerThanTtl();

        $this->itWillSetValueOnStoreWithTtl(1);

        $lock = $this->classUnderTest->lock($this->resource, $this->ttl, $this->retryDelay, $this->retryCount);

        $this->assertEquals($this->resource, $lock->getResource());
        $this->assertEquals($this->token, $lock->getToken());
        $this->assertEquals($this->originTime + $this->ttl, $lock->getValidityEndTime());
    }

    /**
     * @test
     */
    public function itShouldFailLockingTheResourceAfterRetryingIfTimeToLockHasBeenGreaterThanTtlAndRetry()
    {
        $this->itWillGenerateAToken();

        $this->itWillMeasureATimePassedOverTtlAndDrift();

        $this->itWillSetValueOnStoreWithTtl(2);

        $this->setExpectedException(LockingException::class);

        $stopwatch = new Stopwatch();
        $timeMeasure = $stopwatch->start('test');

        try {
            $this->classUnderTest->lock($this->resource, $this->ttl, $this->retryDelay, 1);
        } catch (\Exception $e) {
            $timeMeasure->stop();
            $this->assertLessThan($timeMeasure->getDuration(), $this->ttl);
            throw $e;
        }
    }

    /**
     * @test
     */
    public function itShouldNotSetAValidityTimeEndIfNoTtlIsDefined()
    {
        $this->itWillGenerateAToken();

        $this->itWillMeasureATimePassedOverTtlAndDrift();

        $this->itWillSetValueOnStoreWithoutTtl(1);

        $lock = $this->classUnderTest->lock($this->resource, null, $this->retryDelay, $this->retryCount);

        $this->assertEquals($this->resource, $lock->getResource());
        $this->assertEquals($this->token, $lock->getToken());
        $this->assertNull($lock->getValidityEndTime());
    }

    /**
     * @test
     */
    public function itShouldAssertTheResourceIsNotLockedIfTheStoreHasNotTheResourceLocked()
    {
        $this->itWillAssertKeyHasNotBeenFoundInStore(1);

        $isLocked = $this->classUnderTest->isLocked($this->resource);

        $this->assertFalse($isLocked);
    }

    /**
     * @test
     */
    public function itShouldUnlockIfStoreUnlocksSuccessfully()
    {
        $this->itWillUnlockTheResourceOnStore(1);

        $lock = new Lock($this->resource, $this->token);

        $this->classUnderTest->unlock($lock);
    }

    /**
     * @test
     */
    public function itShouldNotFailIfUnlockFailsOnAStoreWhereTheResourceIsNotLocked()
    {
        $this->itWillFailUnlockingTheResourceOnStore(1);

        $this->itWillAssertKeyHasNotBeenFoundInStore(1);

        $lock = new Lock($this->resource, $this->token);

        $this->classUnderTest->unlock($lock);
    }

    /**
     * @test
     */
    public function itShouldFailIfUnlockFailsOnAStoreWhereTheResourceIsStillLocked()
    {
        $this->itWillFailUnlockingTheResourceOnStore(1);

        $this->itWillAssertKeyHasBeenFoundInStore(1);

        $lock = new Lock($this->resource, $this->token);

        $this->setExpectedException(UnlockingException::class);

        $this->classUnderTest->unlock($lock);
    }

    // Utilities

    private function itWillGenerateAToken()
    {
        $this->tokenGenerator
            ->shouldReceive('generateToken')
            ->andReturn($this->token);
    }

    private function itWillMeasureATimePassedLowerThanTtl()
    {
        $this->stopwatch
            ->shouldReceive('start')
            ->with($this->token)
            ->andReturn($this->stopwatchEvent);

        $this->stopwatchEvent
            ->shouldReceive('stop');

        $this->stopwatchEvent
            ->shouldReceive('getDuration')
            ->andReturn(5);

        $this->stopwatchEvent
            ->shouldReceive('getOrigin')
            ->andReturn($this->originTime);
    }

    private function itWillMeasureATimePassedOverTtlAndDrift()
    {
        $this->stopwatch
            ->shouldReceive('start')
            ->with($this->token)
            ->andReturn($this->stopwatchEvent);

        $this->stopwatchEvent
            ->shouldReceive('stop');

        $this->stopwatchEvent
            ->shouldReceive('getDuration')
            ->andReturn(97);

        $this->stopwatchEvent
            ->shouldReceive('getOrigin')
            ->andReturn($this->originTime);
    }

    private function itWillSetValueOnStoreWithTtl($times)
    {
        $this->store
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), $this->ttl)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillSetValueOnStoreWithoutTtl($times)
    {
        $this->store
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), null)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillUnlockTheResourceOnStore($times)
    {
        $this->store
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(true)
            ->times($times);
    }

    private function itWillFailUnlockingTheResourceOnStore($times)
    {
        $this->store
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(false)
            ->times($times);
    }

    private function itWillAssertKeyHasBeenFoundInStore($times)
    {
        $this->store
            ->shouldReceive('exists')
            ->with($this->resource)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillAssertKeyHasNotBeenFoundInStore($times)
    {
        $this->store
            ->shouldReceive('exists')
            ->with($this->resource)
            ->andReturn(false)
            ->times($times);
    }
}
