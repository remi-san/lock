<?php

namespace RemiSan\Lock\Locker;

use RemiSan\Lock\LockStore;
use RemiSan\Lock\Exceptions\LockingException;
use RemiSan\Lock\Exceptions\UnlockingException;
use RemiSan\Lock\Lock;
use RemiSan\Lock\Locker;
use RemiSan\Lock\TokenGenerator;
use Symfony\Component\Stopwatch\Stopwatch;

final class SingleStoreLocker implements Locker
{
    /** @var LockStore */
    private $store;

    /** @var TokenGenerator */
    private $tokenGenerator;

    /** @var Stopwatch */
    private $stopwatch;

    /**
     * SingleStoreLocker constructor.
     *
     * @param LockStore      $store          The persisting store for the locks
     * @param TokenGenerator $tokenGenerator The token generator
     * @param Stopwatch      $stopwatch      A way to measure time passed
     */
    public function __construct(
        $store,
        TokenGenerator $tokenGenerator,
        Stopwatch $stopwatch
    ) {
        $this->store = $store;
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
                return $this->lockAndCheckQuorumAndTtl($lock, $ttl);
            } catch (LockingException $e) {
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
        return $this->store->exists((string) $resource);
    }

    /**
     * {@inheritdoc}
     */
    public function unlock(Lock $lock)
    {
        if (!$this->store->delete($lock)) {
            if ($this->store->exists($lock->getResource())) {
                // Only throw an exception if the lock is still present
                throw new UnlockingException('Failed releasing the lock.');
            }
        }
    }

    /**
     * Try locking resource on store.
     *
     * Measure the time to do it and reject if time to lock on store have exceeded the ttl.
     *
     * @param Lock $lock The lock instance
     * @param int  $ttl  Time to live in milliseconds
     *
     * @throws LockingException
     *
     * @return Lock
     */
    private function lockAndCheckQuorumAndTtl(Lock $lock, $ttl)
    {
        $timeMeasure = $this->stopwatch->start($lock->getToken());
        $this->store->set($lock, $ttl);
        $timeMeasure->stop();

        if ($ttl) {
            $this->checkTtl($timeMeasure->getDuration(), $ttl);
            $lock->setValidityEndTime($timeMeasure->getOrigin() + $ttl);
        }

        return $lock;
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
        $adjustedElapsedTime = $elapsedTime + $this->store->getDrift($ttl);

        if ($adjustedElapsedTime >= $ttl) {
            throw new LockingException('Time to lock the resource has exceeded the ttl.');
        }
    }
}
