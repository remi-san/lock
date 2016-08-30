<?php

namespace RemiSan\Lock\Implementations;

use RemiSan\Lock\Exceptions\LockingException;
use RemiSan\Lock\Exceptions\UnlockingException;
use RemiSan\Lock\Lock;
use RemiSan\Lock\Locker;
use RemiSan\Lock\TokenGenerator;
use Symfony\Component\Stopwatch\Stopwatch;

final class RedLock implements Locker
{
    /** @var float */
    const CLOCK_DRIFT_FACTOR = 0.01;

    /** @var \Redis[] */
    private $instances = [];

    /** @var TokenGenerator */
    private $tokenGenerator;

    /** @var Stopwatch */
    private $stopwatch;

    /** @var int */
    private $quorum;

    /**
     * @param \Redis[]       $instances      Array of pre-connected \Redis objects
     * @param TokenGenerator $tokenGenerator The token generator
     * @param Stopwatch      $stopwatch      A way to measure time passed
     */
    public function __construct(
        array $instances,
        TokenGenerator $tokenGenerator,
        Stopwatch $stopwatch
    ) {
        $this->setInstances($instances);
        $this->setQuorum();

        $this->tokenGenerator = $tokenGenerator;
        $this->stopwatch = $stopwatch;
    }

    /**
     * {@inheritdoc}
     */
    public function lock($resource, $ttl = null, $retryDelay = 0, $retryCount = 0)
    {
        $lock = new Lock($resource, $this->tokenGenerator->generateToken());

        $tried = 0;
        while (true) {
            try {
                return $this->monitoredLockingOfAllInstances($lock, $ttl);
            } catch (LockingException $e) {
                $this->resetLock($lock);
            }

            if ($tried++ === $retryCount) {
                break;
            }

            $this->waitBeforeRetrying($retryDelay);
        }

        throw new LockingException();
    }

    /**
     * {@inheritdoc}
     */
    public function isResourceLocked($resource)
    {
        foreach ($this->instances as $instance) {
            if ($this->isInstanceResourceLocked($instance, $resource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function unlock(Lock $lock)
    {
        foreach ($this->instances as $instance) {
            if (!$this->unlockInstance($instance, $lock)) {
                if ($this->isInstanceResourceLocked($instance, $lock->getResource())) {
                    throw new UnlockingException(); // Only throw an exception if the lock is still present
                }
            }
        }
    }

    /**
     * @param Lock $lock
     * @param int  $ttl
     *
     * @throws LockingException
     *
     * @return Lock
     */
    private function monitoredLockingOfAllInstances(Lock $lock, $ttl)
    {
        $timeMeasure = $this->stopwatch->start($lock->getToken());
        $instancesLocked = $this->lockInstances($lock, $ttl);
        $timeMeasure->stop();

        $this->checkQuorum($instancesLocked);

        if ($ttl) {
            self::checkTtl($timeMeasure->getDuration(), $ttl);
            $lock->setValidityTimeEnd($timeMeasure->getOrigin() + $ttl);
        }

        return $lock;
    }

    /**
     * @param Lock $lock
     * @param int  $ttl
     *
     * @return int The number of instances locked
     */
    private function lockInstances(Lock $lock, $ttl)
    {
        $instancesLocked = 0;

        foreach ($this->instances as $instance) {
            if ($this->lockInstance($instance, $lock, $ttl)) {
                ++$instancesLocked;
            }
        }

        return $instancesLocked;
    }

    /**
     * @param \Redis $instance Server instance to be locked
     * @param Lock   $lock     The lock instance
     * @param int    $ttl      Time to live in milliseconds
     *
     * @return bool
     */
    private function lockInstance(\Redis $instance, Lock $lock, $ttl)
    {
        $options = ['NX'];

        if ($ttl) {
            $options['PX'] = (int) $ttl;
        }

        return (bool) $instance->set($lock->getResource(), (string) $lock->getToken(), $options);
    }

    /**
     * @param Lock $lock
     */
    private function resetLock($lock)
    {
        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $lock);
        }
    }

    /**
     * @param \Redis $instance
     * @param string $resource
     *
     * @return bool
     */
    private function isInstanceResourceLocked(\Redis $instance, $resource)
    {
        return (bool) $instance->exists($resource);
    }

    /**
     * @param \Redis $instance Server instance to be unlocked
     * @param Lock   $lock     The lock to unlock
     *
     * @return bool
     */
    private function unlockInstance(\Redis $instance, Lock $lock)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';

        return (bool) $instance->evaluate(
            $script,
            [$lock->getResource(), (string) $lock->getToken()],
            1
        );
    }

    /**
     * @param \Redis[] $instances
     *
     * @throws \Exception
     */
    private function setInstances(array $instances)
    {
        if (count($instances) === 0) {
            throw new \InvalidArgumentException('You must provide at least one Redis instance.');
        }

        foreach ($instances as $instance) {
            if (!$instance->isConnected()) {
                throw new \InvalidArgumentException('The Redis must be connected.');
            }
        }

        $this->instances = $instances;
    }

    private function setQuorum()
    {
        $numberOfRedisInstances = count($this->instances);
        $this->quorum = round(min($numberOfRedisInstances, ($numberOfRedisInstances / 2) + 1));
    }

    /**
     * @param $instancesLocked
     *
     * @throws LockingException
     */
    private function checkQuorum($instancesLocked)
    {
        if ($instancesLocked < $this->quorum) {
            throw new LockingException();
        }
    }

    /**
     * @param $retryDelay
     */
    private function waitBeforeRetrying($retryDelay)
    {
        usleep($retryDelay * 1000);
    }

    /**
     * @param int $elapsedTime
     * @param int $ttl
     *
     * @throws LockingException
     */
    private static function checkTtl($elapsedTime, $ttl)
    {
        $adjustedElapsedTime = ($elapsedTime + self::getDrift($ttl));

        if ($adjustedElapsedTime >= $ttl) {
            throw new LockingException();
        }
    }

    /**
     * Get the drift time based on ttl in ms.
     *
     * @param int $ttl
     *
     * @return float
     */
    private static function getDrift($ttl)
    {
        // Add 2 milliseconds to the drift to account for Redis expires
        // precision, which is 1 millisecond, plus 2 millisecond min drift
        // for small TTLs.

        $redisExpiresPrecision = 2;
        $minDrift = ($ttl) ? ceil($ttl * self::CLOCK_DRIFT_FACTOR) : 0;

        return $minDrift + $redisExpiresPrecision;
    }
}
