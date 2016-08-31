<?php

namespace RemiSan\Lock\Locker;

use RemiSan\Lock\Connection;
use RemiSan\Lock\Exceptions\LockingException;
use RemiSan\Lock\Exceptions\UnlockingException;
use RemiSan\Lock\Lock;
use RemiSan\Lock\Locker;
use RemiSan\Lock\Quorum;
use RemiSan\Lock\TokenGenerator;
use Symfony\Component\Stopwatch\Stopwatch;

final class MultipleInstanceLocker implements Locker
{
    /** @var Connection[] */
    private $instances = [];

    /** @var TokenGenerator */
    private $tokenGenerator;

    /** @var Quorum */
    private $quorum;

    /** @var Stopwatch */
    private $stopwatch;

    /**
     * RedLock constructor.
     *
     * @param Connection[]   $instances      Array of persistence system connections
     * @param TokenGenerator $tokenGenerator The token generator
     * @param Quorum         $quorum         The quorum implementation to use
     * @param Stopwatch      $stopwatch      A way to measure time passed
     */
    public function __construct(
        array $instances,
        TokenGenerator $tokenGenerator,
        Quorum $quorum,
        Stopwatch $stopwatch
    ) {
        $this->setInstances($instances);
        $this->setQuorum($quorum);

        $this->tokenGenerator = $tokenGenerator;
        $this->stopwatch = $stopwatch;
    }

    /**
     * {@inheritdoc}
     */
    public function lock($resource, $ttl = null, $retryDelay = 0, $retryCount = 0)
    {
        $lock = new Lock((string) $resource, $this->tokenGenerator->generateToken());

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

        throw new LockingException('Failed locking the resource.');
    }

    /**
     * {@inheritdoc}
     */
    public function isLocked($resource)
    {
        foreach ($this->instances as $instance) {
            if ($instance->exists((string) $resource)) {
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
            if (!$instance->delete($lock)) {
                if ($instance->exists($lock->getResource())) {
                    // Only throw an exception if the lock is still present
                    throw new UnlockingException('Failed releasing the lock.');
                }
            }
        }
    }

    /**
     * Try locking all connected instances.
     *
     * Measure the time to do it and reject if not enough connected instance have successfully
     * locked the resource or if time to lock all instances have exceeded the ttl.
     *
     * @param Lock $lock The lock instance
     * @param int  $ttl  Time to live in milliseconds
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
            $this->checkTtl($timeMeasure->getDuration(), $ttl);
            $lock->setValidityEndTime($timeMeasure->getOrigin() + $ttl);
        }

        return $lock;
    }

    /**
     * Lock resource in connected instances and count how many instance did it with success.
     *
     * @param Lock $lock The lock instance
     * @param int  $ttl  Time to live in milliseconds
     *
     * @return int The number of instances locked
     */
    private function lockInstances(Lock $lock, $ttl)
    {
        $instancesLocked = 0;

        foreach ($this->instances as $instance) {
            if ($instance->set($lock, $ttl)) {
                ++$instancesLocked;
            }
        }

        return $instancesLocked;
    }

    /**
     * Unlock the resource on all Redis instances.
     *
     * @param Lock $lock The lock to release
     */
    private function resetLock($lock)
    {
        foreach ($this->instances as $instance) {
            $instance->delete($lock);
        }
    }

    /**
     * Init all Redis instances passed to the constructor.
     *
     * If no Redis instance is given, it will return a InvalidArgumentException.
     * If one or more Redis instance is not connected, it will return a InvalidArgumentException.
     *
     * @param \Redis[] $instances The connected Redis instances
     *
     * @throws \InvalidArgumentException
     */
    private function setInstances(array $instances)
    {
        if (count($instances) === 0) {
            throw new \InvalidArgumentException('You must provide at least one Redis instance.');
        }

        $this->instances = $instances;
    }

    /**
     * Set the quorum based on the number of instances passed to the constructor.
     *
     * @param Quorum $quorum The quorum implementation to use
     */
    private function setQuorum(Quorum $quorum)
    {
        $this->quorum = $quorum;
        $this->quorum->init(count($this->instances));
    }

    /**
     * Check if the number of instances that have been locked reach the quorum.
     *
     * @param int $instancesLocked The number of instances that have been locked
     *
     * @throws LockingException
     */
    private function checkQuorum($instancesLocked)
    {
        if (!$this->quorum->isMet($instancesLocked)) {
            throw new LockingException('Quorum has not been met.');
        }
    }

    /**
     * Make the script wait before retrying to lock.
     *
     * @param int $retryDelay The retry delay in milliseconds
     */
    private function waitBeforeRetrying($retryDelay)
    {
        usleep($retryDelay * 1000);
    }

    /**
     * Checks if the elapsed time is inferior to the ttl.
     *
     * To the elapsed time is added a drift time to have a margin of error.
     * If this adjusted time is greater than the ttl, it will throw a LockingException.
     *
     * @param int $elapsedTime The time elapsed in milliseconds
     * @param int $ttl         The time to live in milliseconds
     *
     * @throws LockingException
     */
    private function checkTtl($elapsedTime, $ttl)
    {
        $adjustedElapsedTime = ($elapsedTime + $this->getDrift($ttl));

        if ($adjustedElapsedTime >= $ttl) {
            throw new LockingException('Time to lock the resource has exceeded the ttl.');
        }
    }

    /**
     * Get the drift time based on ttl in ms.
     *
     * @param int $ttl The time to live in milliseconds
     *
     * @return float
     */
    private function getDrift($ttl)
    {
        return array_values($this->instances)[0]->getDrift($ttl);
    }
}
