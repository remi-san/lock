<?php

namespace RemiSan\Lock\LockStore;

use RemiSan\Lock\LockStore;
use RemiSan\Lock\Lock;

class RedisLockStore implements LockStore
{
    /** @var float */
    const CLOCK_DRIFT_FACTOR = 0.01;
    
    /** @var int */
    const REDIS_EXPIRES_PRECISION = 2;

    /** @var \Redis */
    private $redis;

    /**
     * RedisLockStore constructor.
     *
     * @param \Redis $redis
     */
    public function __construct(\Redis $redis)
    {
        if (! $redis->isConnected()) {
            throw new \InvalidArgumentException('The Redis instance must be connected.');
        }
        $this->redis = $redis;
    }
    /**
     * @inheritDoc
     */
    public function set(Lock $lock, $ttl = null)
    {
        $options = ['NX'];

        if ($ttl !== null && $ttl > 0) {
            $options['PX'] = (int) $ttl;
        }

        return (bool) $this->redis->set($lock->getResource(), (string) $lock->getToken(), $options);
    }

    /**
     * @inheritDoc
     */
    public function exists($resource)
    {
        return (bool) $this->redis->get($resource);
    }

    /**
     * @inheritDoc
     */
    public function delete(Lock $lock)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';

        return (bool) $this->redis->evaluate(
            $script,
            [$lock->getResource(), (string) $lock->getToken()],
            1
        );
    }

    /**
     * @inheritDoc
     */
    public function getDrift($ttl)
    {
        // Add 2 milliseconds to the drift to account for Redis expires
        // precision, which is 1 millisecond, plus 1 millisecond min drift
        // for small TTLs.
        
        $minDrift = ($ttl) ? (int) ceil($ttl * self::CLOCK_DRIFT_FACTOR) : 0;

        return $minDrift + self::REDIS_EXPIRES_PRECISION;
    }
}
