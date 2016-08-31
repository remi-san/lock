<?php

namespace RemiSan\Lock;

use RemiSan\Lock\Exceptions\UnlockingException;

interface Locker
{
    /**
     * Lock a resource.
     *
     * @param string $resource   Name of the resource to be locked
     * @param int    $ttl        Time in milliseconds for the lock to be held
     * @param int    $retryCount The number of times to retry locking
     * @param int    $retryDelay The time in milliseconds to wait before retrying
     *
     * @return Lock
     */
    public function lock($resource, $ttl = null, $retryCount = 0, $retryDelay = 0);

    /**
     * Check if a resource is locked.
     *
     * @param string $resource
     *
     * @return bool
     */
    public function isLocked($resource);

    /**
     * Unlock a resource.
     *
     * @param Lock $lock The lock
     *
     * @throws UnlockingException
     */
    public function unlock(Lock $lock);
}
