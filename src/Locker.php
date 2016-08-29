<?php

namespace RemiSan\Lock;

use RemiSan\Lock\Exceptions\LockingException;
use RemiSan\Lock\Exceptions\UnlockingException;

interface Locker
{
    /**
     * @param  string $resource Name of the resource to be locked
     * @param  int    $ttl      Time in milliseconds for the lock to be held
     *
     * @throws LockingException
     * @return Lock
     */
    public function lock($resource, $ttl);

    /**
     * @param $resource
     *
     * @return boolean
     */
    public function isResourceLocked($resource);

    /**
     * @param  Lock $lock   The lock
     *
     * @throws UnlockingException
     * @return void
     */
    public function unlock(Lock $lock);
}
