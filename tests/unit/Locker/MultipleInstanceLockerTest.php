<?php

namespace RemiSan\Lock\Test\Locker;

use Mockery\Mock;
use RemiSan\Lock\Connection;
use RemiSan\Lock\Exceptions\LockingException;
use RemiSan\Lock\Exceptions\UnlockingException;
use RemiSan\Lock\Locker\MultipleInstanceLocker;
use RemiSan\Lock\Lock;
use RemiSan\Lock\Quorum;
use RemiSan\Lock\TokenGenerator;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Stopwatch\StopwatchEvent;

class RedLockTest extends \PHPUnit_Framework_TestCase
{
    /** @var Connection | Mock */
    private $instance1;

    /** @var Connection | Mock */
    private $instance2;

    /** @var TokenGenerator | Mock */
    private $tokenGenerator;

    /** @var Quorum | Mock */
    private $quorum;

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

    /** @var MultipleInstanceLocker */
    private $classUnderTest;

    /**
     * Init the mocks
     */
    public function setUp()
    {
        $this->instance1 = \Mockery::mock(Connection::class, function ($instance) {
            /** @var Mock $instance */
            $instance->shouldReceive('getDrift')->andReturn(3);
        });
        $this->instance2 = \Mockery::mock(Connection::class);

        $this->tokenGenerator = \Mockery::mock(TokenGenerator::class);

        $this->quorum = \Mockery::mock(Quorum::class, function ($quorum) {
            /** @var Mock $quorum */
            $quorum->shouldReceive('init');
        });

        $this->stopwatch = \Mockery::mock(Stopwatch::class);
        $this->stopwatchEvent = \Mockery::mock(StopwatchEvent::class);

        $this->retryCount = 3;
        $this->retryDelay = 100;

        $this->resource = 'resource';
        $this->ttl = 100;

        $this->token = 'token';

        $this->originTime = 333;

        $this->classUnderTest = new MultipleInstanceLocker(
            [ $this->instance1, $this->instance2 ],
            $this->tokenGenerator,
            $this->quorum,
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
    public function itNeedsAtLeastOneRedisInstanceToInstantiateTheClass()
    {
        $this->setExpectedException(\InvalidArgumentException::class);

        new MultipleInstanceLocker(
            [],
            $this->tokenGenerator,
            $this->quorum,
            $this->stopwatch
        );
    }

    /**
     * @test
     */
    public function itShouldLockTheResourceIfAllRedisInstancesHaveBeenAbleToLockIt()
    {
        $this->itWillGenerateAToken();

        $this->itWillMeasureATimePassedLowerThanTtl();

        $this->itWillSetValueOnRedisInstanceOneWithTtl(1);
        $this->itWillSetValueOnRedisInstanceTwoWithTtl(1);

        $this->itWillMeetQuorum();

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

        $this->itWillSetValueOnRedisInstanceOneWithTtl(2);
        $this->itWillSetValueOnRedisInstanceTwoWithTtl(2);

        $this->itWillUnlockTheResourceOnInstanceOne(2);
        $this->itWillUnlockTheResourceOnInstanceTwo(2);

        $this->itWillMeetQuorum();

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
    public function itShouldFailLockingIfQuorumForTheAcquisitionOfTheLockIsNotMetAndItShouldRetry()
    {
        $this->itWillGenerateAToken();

        $this->itWillMeasureATimePassedLowerThanTtl();

        $this->itWillSetValueOnRedisInstanceOneWithTtl(2);
        $this->itWillFailSettingValueOnRedisInstanceTwoWithTtl(2);

        $this->itWillUnlockTheResourceOnInstanceOne(2);
        $this->itWillUnlockTheResourceOnInstanceTwo(2);

        $this->itWillNotMeetQuorum();

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

        $this->itWillSetValueOnRedisInstanceOneWithoutTtl(1);
        $this->itWillSetValueOnRedisInstanceTwoWithoutTtl(1);

        $this->itWillMeetQuorum();

        $lock = $this->classUnderTest->lock($this->resource, null, $this->retryDelay, $this->retryCount);

        $this->assertEquals($this->resource, $lock->getResource());
        $this->assertEquals($this->token, $lock->getToken());
        $this->assertNull($lock->getValidityEndTime());
    }

    // TODO test other failing cases

    /**
     * @test
     */
    public function itShouldAssertTheResourceIsLockedIfAtLeastOneInstanceHasTheResourceLocked()
    {
        $this->itWillAssertKeyHasNotBeenFoundInInstanceOne(1);
        $this->itWillAssertKeyHasBeenFoundInInstanceTwo(1);

        $isLocked = $this->classUnderTest->isLocked($this->resource);

        $this->assertTrue($isLocked);
    }

    /**
     * @test
     */
    public function itShouldAssertTheResourceIsLockedIfTheFirstInstanceHasTheResourceLocked()
    {
        $this->itWillAssertKeyHasBeenFoundInInstanceOne(1);
        $this->itWillAssertKeyHasBeenFoundInInstanceTwo(0);

        $isLocked = $this->classUnderTest->isLocked($this->resource);

        $this->assertTrue($isLocked);
    }

    /**
     * @test
     */
    public function itShouldAssertTheResourceIsNotLockedIfNoInstanceHasTheResourceLocked()
    {
        $this->itWillAssertKeyHasNotBeenFoundInInstanceOne(1);
        $this->itWillAssertKeyHasNotBeenFoundInInstanceTwo(1);

        $isLocked = $this->classUnderTest->isLocked($this->resource);

        $this->assertFalse($isLocked);
    }

    /**
     * @test
     */
    public function itShouldUnlockOnAllInstances()
    {
        $this->itWillUnlockTheResourceOnInstanceOne(1);
        $this->itWillUnlockTheResourceOnInstanceTwo(1);

        $lock = new Lock($this->resource, $this->token);

        $this->classUnderTest->unlock($lock);
    }

    /**
     * @test
     */
    public function itShouldNotFailIfUnlockFailsOnAnInstanceWhereTheResourceIsNotLocked()
    {
        $this->itWillUnlockTheResourceOnInstanceOne(1);
        $this->itWillFailUnlockingTheResourceOnInstanceTwo(1);

        $this->itWillAssertKeyHasNotBeenFoundInInstanceTwo(1);

        $lock = new Lock($this->resource, $this->token);

        $this->classUnderTest->unlock($lock);
    }

    /**
     * @test
     */
    public function itShoulFailIfUnlockFailsOnAnInstanceWhereTheResourceIsStillLocked()
    {
        $this->itWillUnlockTheResourceOnInstanceOne(1);
        $this->itWillFailUnlockingTheResourceOnInstanceTwo(1);

        $this->itWillAssertKeyHasBeenFoundInInstanceTwo(1);

        $lock = new Lock($this->resource, $this->token);

        $this->setExpectedException(UnlockingException::class);

        $this->classUnderTest->unlock($lock);
    }

    /**
     * @test
     */
    public function itShoulFailIfUnlockFailsOnFirstInstanceWhereTheResourceIsStillLocked()
    {
        $this->itWillFailUnlockingTheResourceOnInstanceOne(1);
        $this->itWillFailUnlockingTheResourceOnInstanceTwo(0);

        $this->itWillAssertKeyHasBeenFoundInInstanceOne(1);

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

    private function itWillSetValueOnRedisInstanceOneWithTtl($times)
    {
        $this->instance1
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), $this->ttl)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillSetValueOnRedisInstanceTwoWithTtl($times)
    {
        $this->instance2
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), $this->ttl)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillSetValueOnRedisInstanceOneWithoutTtl($times)
    {
        $this->instance1
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), null)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillSetValueOnRedisInstanceTwoWithoutTtl($times)
    {
        $this->instance2
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), null)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillFailSettingValueOnRedisInstanceTwoWithTtl($times)
    {
        $this->instance2
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), $this->ttl)
            ->andReturn(false)
            ->times($times);
    }

    private function itWillUnlockTheResourceOnInstanceOne($times)
    {
        $this->instance1
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(true)
            ->times($times);
    }

    private function itWillUnlockTheResourceOnInstanceTwo($times)
    {
        $this->instance2
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(true)
            ->times($times);
    }

    private function itWillFailUnlockingTheResourceOnInstanceOne($times)
    {
        $this->instance1
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(false)
            ->times($times);
    }

    private function itWillFailUnlockingTheResourceOnInstanceTwo($times)
    {
        $this->instance2
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(false)
            ->times($times);
    }

    private function itWillAssertKeyHasBeenFoundInInstanceOne($times)
    {
        $this->instance1
            ->shouldReceive('exists')
            ->with($this->resource)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillAssertKeyHasBeenFoundInInstanceTwo($times)
    {
        $this->instance2
            ->shouldReceive('exists')
            ->with($this->resource)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillAssertKeyHasNotBeenFoundInInstanceOne($times)
    {
        $this->instance1
            ->shouldReceive('exists')
            ->with($this->resource)
            ->andReturn(false)
            ->times($times);
    }

    private function itWillAssertKeyHasNotBeenFoundInInstanceTwo($times)
    {
        $this->instance2
            ->shouldReceive('exists')
            ->with($this->resource)
            ->andReturn(false)
            ->times($times);
    }

    private function itWillMeetQuorum()
    {
        $this->quorum
            ->shouldReceive('isMet')
            ->andReturn(true);
    }

    private function itWillNotMeetQuorum()
    {
        $this->quorum
            ->shouldReceive('isMet')
            ->andReturn(false);
    }
}