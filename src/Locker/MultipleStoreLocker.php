<?php

namespace RemiSan\Lock\Locker;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use RemiSan\Lock\Exceptions\LockingException;
use RemiSan\Lock\Exceptions\UnlockingException;
use RemiSan\Lock\Lock;
use RemiSan\Lock\Locker;
use RemiSan\Lock\LockStore;
use RemiSan\Lock\Quorum;
use RemiSan\Lock\TokenGenerator;
use Symfony\Component\Stopwatch\Stopwatch;

final class MultipleStoreLocker implements Locker, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var LockStore[] */
    private $stores = [];

    /** @var TokenGenerator */
    private $tokenGenerator;

    /** @var Quorum */
    private $quorum;

    /** @var Stopwatch */
    private $stopwatch;

    /**
     * MultipleStoreLocker constructor.
     *
     * @param LockStore[]    $stores         Array of persistence stores for the locks
     * @param TokenGenerator $tokenGenerator The token generator
     * @param Quorum         $quorum         The quorum implementation to use
     * @param Stopwatch      $stopwatch      A way to measure time passed
     */
    public function __construct(
        array $stores,
        TokenGenerator $tokenGenerator,
        Quorum $quorum,
        Stopwatch $stopwatch
    ) {
        $this->setStores($stores);
        $this->setQuorum($quorum);

        $this->tokenGenerator = $tokenGenerator;
        $this->stopwatch = $stopwatch;

        $this->logger = new NullLogger();
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
                return $this->lockAndCheckQuorumAndTtlOnAllStores($lock, $ttl);
            } catch (LockingException $e) {
                $this->logger->notice($e->getMessage(), ['resource' => $lock->getResource()]);
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
        foreach ($this->stores as $store) {
            if ($store->exists((string) $resource)) {
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
        foreach ($this->stores as $store) {
            if (!$store->delete($lock)) {
                if ($store->exists($lock->getResource())) {
                    // Only throw an exception if the lock is still present
                    throw new UnlockingException('Failed releasing the lock.');
                }
            }
        }
    }

    /**
     * Try locking resource on all stores.
     *
     * Measure the time to do it and reject if not enough stores have successfully
     * locked the resource or if time to lock on all stores have exceeded the ttl.
     *
     * @param Lock $lock The lock instance
     * @param int  $ttl  Time to live in milliseconds
     *
     * @throws LockingException
     *
     * @return Lock
     */
    private function lockAndCheckQuorumAndTtlOnAllStores(Lock $lock, $ttl)
    {
        $timeMeasure = $this->stopwatch->start($lock->getToken());
        $storesLocked = $this->lockOnAllStores($lock, $ttl);
        $timeMeasure->stop();

        $this->checkQuorum($storesLocked);

        if ($ttl) {
            $this->checkTtl($timeMeasure->getDuration(), $ttl);
            $lock->setValidityEndTime($timeMeasure->getOrigin() + $ttl);
        }

        return $lock;
    }

    /**
     * Lock resource on all stores and count how many stores did it with success.
     *
     * @param Lock $lock The lock instance
     * @param int  $ttl  Time to live in milliseconds
     *
     * @return int The number of stores on which the resource has been locked
     */
    private function lockOnAllStores(Lock $lock, $ttl)
    {
        $storesLocked = 0;

        foreach ($this->stores as $store) {
            if ($store->set($lock, $ttl)) {
                ++$storesLocked;
            }
        }

        return $storesLocked;
    }

    /**
     * Unlock the resource on all stores.
     *
     * @param Lock $lock The lock to release
     */
    private function resetLock($lock)
    {
        foreach ($this->stores as $store) {
            $store->delete($lock);
        }
    }

    /**
     * Init all stores passed to the constructor.
     *
     * If no store is given, it will return an InvalidArgumentException.
     *
     * @param LockStore[] $stores The lock stores
     *
     * @throws \InvalidArgumentException
     */
    private function setStores(array $stores)
    {
        if (count($stores) === 0) {
            throw new \InvalidArgumentException('You must provide at least one LockStore.');
        }

        $this->stores = $stores;
    }

    /**
     * Set the quorum based on the number of stores passed to the constructor.
     *
     * @param Quorum $quorum The quorum implementation to use
     */
    private function setQuorum(Quorum $quorum)
    {
        $this->quorum = $quorum;
        $this->quorum->init(count($this->stores));
    }

    /**
     * Check if the number of stores where the resource has been locked meet the quorum.
     *
     * @param int $storesLocked The number of stores on which the resource has been locked
     *
     * @throws LockingException
     */
    private function checkQuorum($storesLocked)
    {
        if (!$this->quorum->isMet($storesLocked)) {
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
     * Return the max drift time of all stores
     *
     * @param int $ttl The time to live in milliseconds
     *
     * @return float
     */
    private function getDrift($ttl)
    {
        return max(array_map(function (LockStore $store) use ($ttl) {
            return $store->getDrift($ttl);
        }, $this->stores));
    }
}
