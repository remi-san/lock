<?php

namespace RemiSan\Lock\Test\Locker;

use Mockery\Mock;
use RemiSan\Lock\LockStore;
use RemiSan\Lock\Exceptions\LockingException;
use RemiSan\Lock\Exceptions\UnlockingException;
use RemiSan\Lock\Locker\MultipleStoreLocker;
use RemiSan\Lock\Lock;
use RemiSan\Lock\Quorum;
use RemiSan\Lock\TokenGenerator;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Stopwatch\StopwatchEvent;

class MultipleStoreLockerTest extends \PHPUnit_Framework_TestCase
{
    /** @var LockStore | Mock */
    private $store1;

    /** @var LockStore | Mock */
    private $store2;

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

    /** @var MultipleStoreLocker */
    private $classUnderTest;

    /**
     * Init the mocks
     */
    public function setUp()
    {
        $this->store1 = \Mockery::mock(LockStore::class, function ($store) {
            /** @var Mock $store */
            $store->shouldReceive('getDrift')->andReturn(2);
        });
        $this->store2 = \Mockery::mock(LockStore::class, function ($store) {
            /** @var Mock $store */
            $store->shouldReceive('getDrift')->andReturn(3);
        });

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

        $this->classUnderTest = new MultipleStoreLocker(
            [ $this->store1, $this->store2 ],
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
    public function itNeedsAtLeastOneStoreToInstantiateTheClass()
    {
        $this->setExpectedException(\InvalidArgumentException::class);

        new MultipleStoreLocker(
            [],
            $this->tokenGenerator,
            $this->quorum,
            $this->stopwatch
        );
    }

    /**
     * @test
     */
    public function itShouldLockTheResourceIfAllStoresHaveBeenAbleToLockIt()
    {
        $this->itWillGenerateAToken();

        $this->itWillMeasureATimePassedLowerThanTtl();

        $this->itWillSetValueOnStoreOneWithTtl(1);
        $this->itWillSetValueOnStoreTwoWithTtl(1);

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

        $this->itWillSetValueOnStoreOneWithTtl(2);
        $this->itWillSetValueOnStoreTwoWithTtl(2);

        $this->itWillUnlockTheResourceOnStoreOne(2);
        $this->itWillUnlockTheResourceOnStoreTwo(2);

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

        $this->itWillSetValueOnStoreOneWithTtl(2);
        $this->itWillFailSettingValueOnStoreTwoWithTtl(2);

        $this->itWillUnlockTheResourceOnStoreOne(2);
        $this->itWillUnlockTheResourceOnStoreTwo(2);

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

        $this->itWillSetValueOnStoreOneWithoutTtl(1);
        $this->itWillSetValueOnStoreTwoWithoutTtl(1);

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
    public function itShouldAssertTheResourceIsLockedIfAtLeastOneStoresHasTheResourceLocked()
    {
        $this->itWillAssertKeyHasNotBeenFoundInStoreOne(1);
        $this->itWillAssertKeyHasBeenFoundInStoreTwo(1);

        $isLocked = $this->classUnderTest->isLocked($this->resource);

        $this->assertTrue($isLocked);
    }

    /**
     * @test
     */
    public function itShouldAssertTheResourceIsLockedIfTheFirstStoreHasTheResourceLocked()
    {
        $this->itWillAssertKeyHasBeenFoundInStoreOne(1);
        $this->itWillAssertKeyHasBeenFoundInStoreTwo(0);

        $isLocked = $this->classUnderTest->isLocked($this->resource);

        $this->assertTrue($isLocked);
    }

    /**
     * @test
     */
    public function itShouldAssertTheResourceIsNotLockedIfNoStoreHasTheResourceLocked()
    {
        $this->itWillAssertKeyHasNotBeenFoundInStoreOne(1);
        $this->itWillAssertKeyHasNotBeenFoundInStoreTwo(1);

        $isLocked = $this->classUnderTest->isLocked($this->resource);

        $this->assertFalse($isLocked);
    }

    /**
     * @test
     */
    public function itShouldUnlockOnAllStores()
    {
        $this->itWillUnlockTheResourceOnStoreOne(1);
        $this->itWillUnlockTheResourceOnStoreTwo(1);

        $lock = new Lock($this->resource, $this->token);

        $this->classUnderTest->unlock($lock);
    }

    /**
     * @test
     */
    public function itShouldNotFailIfUnlockFailsOnAStoreWhereTheResourceIsNotLocked()
    {
        $this->itWillUnlockTheResourceOnStoreOne(1);
        $this->itWillFailUnlockingTheResourceOnStoreTwo(1);

        $this->itWillAssertKeyHasNotBeenFoundInStoreTwo(1);

        $lock = new Lock($this->resource, $this->token);

        $this->classUnderTest->unlock($lock);
    }

    /**
     * @test
     */
    public function itShoulFailIfUnlockFailsOnAStoreWhereTheResourceIsStillLocked()
    {
        $this->itWillUnlockTheResourceOnStoreOne(1);
        $this->itWillFailUnlockingTheResourceOnStoreTwo(1);

        $this->itWillAssertKeyHasBeenFoundInStoreTwo(1);

        $lock = new Lock($this->resource, $this->token);

        $this->setExpectedException(UnlockingException::class);

        $this->classUnderTest->unlock($lock);
    }

    /**
     * @test
     */
    public function itShoulFailIfUnlockFailsOnFirstStoreWhereTheResourceIsStillLocked()
    {
        $this->itWillFailUnlockingTheResourceOnStoreOne(1);
        $this->itWillFailUnlockingTheResourceOnStoreTwo(0);

        $this->itWillAssertKeyHasBeenFoundInStoreOne(1);

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

    private function itWillSetValueOnStoreOneWithTtl($times)
    {
        $this->store1
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), $this->ttl)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillSetValueOnStoreTwoWithTtl($times)
    {
        $this->store2
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), $this->ttl)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillSetValueOnStoreOneWithoutTtl($times)
    {
        $this->store1
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), null)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillSetValueOnStoreTwoWithoutTtl($times)
    {
        $this->store2
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), null)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillFailSettingValueOnStoreTwoWithTtl($times)
    {
        $this->store2
            ->shouldReceive('set')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }), $this->ttl)
            ->andReturn(false)
            ->times($times);
    }

    private function itWillUnlockTheResourceOnStoreOne($times)
    {
        $this->store1
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(true)
            ->times($times);
    }

    private function itWillUnlockTheResourceOnStoreTwo($times)
    {
        $this->store2
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(true)
            ->times($times);
    }

    private function itWillFailUnlockingTheResourceOnStoreOne($times)
    {
        $this->store1
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(false)
            ->times($times);
    }

    private function itWillFailUnlockingTheResourceOnStoreTwo($times)
    {
        $this->store2
            ->shouldReceive('delete')
            ->with(\Mockery::on(function (Lock $lock) {
                $this->assertEquals($this->resource, $lock->getResource());
                $this->assertEquals($this->token, $lock->getToken());
                return true;
            }))
            ->andReturn(false)
            ->times($times);
    }

    private function itWillAssertKeyHasBeenFoundInStoreOne($times)
    {
        $this->store1
            ->shouldReceive('exists')
            ->with($this->resource)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillAssertKeyHasBeenFoundInStoreTwo($times)
    {
        $this->store2
            ->shouldReceive('exists')
            ->with($this->resource)
            ->andReturn(true)
            ->times($times);
    }

    private function itWillAssertKeyHasNotBeenFoundInStoreOne($times)
    {
        $this->store1
            ->shouldReceive('exists')
            ->with($this->resource)
            ->andReturn(false)
            ->times($times);
    }

    private function itWillAssertKeyHasNotBeenFoundInStoreTwo($times)
    {
        $this->store2
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
