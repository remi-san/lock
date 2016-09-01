<?php

namespace RemiSan\Lock\Test\Connection;

use Mockery\Mock;
use RemiSan\Lock\LockStore\RedisLockStore;
use RemiSan\Lock\Lock;

class RedisLockStoreTest extends \PHPUnit_Framework_TestCase
{
    /** @var \Redis | Mock */
    private $redis;

    /** @var Lock */
    private $lock;

    /** @var int */
    private $ttl;

    /** @var RedisLockStore */
    private $classUnderTest;

    /**
     * Init the mocks
     */
    public function setUp()
    {
        $this->redis = \Mockery::mock(\Redis::class, function ($redis) {
            /** @var \Redis $redis */
            $redis->shouldReceive('isConnected')->andReturn(true)->once();
        });

        $this->lock = new Lock('resource', 'token');
        $this->ttl = 150;

        $this->classUnderTest = new RedisLockStore($this->redis);
    }

    public function tearDown()
    {
        \Mockery::close();
    }

    /**
     * @test
     */
    public function itShouldFailBuildingTheConnectionIfRedisIsNotConnected()
    {
        $this->redis = \Mockery::mock(\Redis::class, function ($redis) {
            /** @var \Redis $redis */
            $redis->shouldReceive('isConnected')->andReturn(false)->once();
        });

        $this->setExpectedException(\InvalidArgumentException::class);

        $this->classUnderTest = new RedisLockStore($this->redis);
    }

    /**
     * @test
     */
    public function itShouldSendTheRequestToAnExclusiveWriteToRedisWhenAskingForLockWithTtl()
    {
        $this->redisWillReceiveASetCommandWithTtl(true);

        $return = $this->classUnderTest->set($this->lock, $this->ttl);

        $this->assertTrue($return);
    }

    /**
     * @test
     */
    public function itShouldSendTheRequestToAnExclusiveWriteToRedisWhenAskingForLockWithoutTtl()
    {
        $this->redisWillReceiveASetCommandWithoutTtl(false);

        $return = $this->classUnderTest->set($this->lock);

        $this->assertFalse($return);
    }

    /**
     * @test
     */
    public function itShouldAssertTheResourceExistsIfRedisFindsIt()
    {
        $this->redisWillFindTheResource();

        $return = $this->classUnderTest->exists($this->lock->getResource());

        $this->assertTrue($return);
    }

    /**
     * @test
     */
    public function itShouldAssertTheResourceDoesNotExistIfRedisDoesNotFindIt()
    {
        $this->redisWillNotFindTheResource();

        $return = $this->classUnderTest->exists($this->lock->getResource());

        $this->assertFalse($return);
    }

    /**
     * @test
     */
    public function itShouldSuccessfullyUseRedLockPatternToDeleteTheLock()
    {
        $this->redLockWillReturnASuccess();

        $return = $this->classUnderTest->delete($this->lock);

        $this->assertTrue($return);
    }

    /**
     * @test
     */
    public function itShouldReturnItHasFailedWhenRedLockPatternToDeleteTheLockFailed()
    {
        $this->redLockWillReturnAFailure();

        $return = $this->classUnderTest->delete($this->lock);

        $this->assertFalse($return);
    }

    /**
     * @test
     */
    public function itShouldReturnADriftTimeRelativeToTheTtl()
    {
        $this->assertEquals(4, $this->classUnderTest->getDrift($this->ttl));
    }

    // Utilities

    private function redisWillReceiveASetCommandWithTtl($return)
    {
        $this->redis
            ->shouldReceive('set')
            ->with(
                $this->lock->getResource(),
                $this->lock->getToken(),
                [ 'NX', 'PX' => $this->ttl ]
            )->andReturn($return)
            ->once();
    }

    private function redisWillReceiveASetCommandWithoutTtl($return)
    {
        $this->redis
            ->shouldReceive('set')
            ->with(
                $this->lock->getResource(),
                $this->lock->getToken(),
                [ 'NX' ]
            )->andReturn($return)
            ->once();
    }

    private function redisWillFindTheResource()
    {
        $this->redis
            ->shouldReceive('get')
            ->with($this->lock->getResource())
            ->andReturn('resource_found')
            ->once();
    }

    private function redisWillNotFindTheResource()
    {
        $this->redis
            ->shouldReceive('get')
            ->with($this->lock->getResource())
            ->andReturn(false)
            ->once();
    }

    private function redLockWillReturnASuccess()
    {
        $this->redis
            ->shouldReceive('evaluate')
            ->with(
                \Mockery::on(function () {
                    return true;
                }),
                [ $this->lock->getResource(), $this->lock->getToken() ],
                1
            )->andReturn(1)
            ->once();
    }

    private function redLockWillReturnAFailure()
    {
        $this->redis
            ->shouldReceive('evaluate')
            ->with(
                \Mockery::on(function () {
                    return true;
                }),
                [ $this->lock->getResource(), $this->lock->getToken() ],
                1
            )->andReturn(0)
            ->once();
    }
}
