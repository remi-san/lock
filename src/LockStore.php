<?php

namespace RemiSan\Lock;

interface LockStore
{
    /**
     * Set the lock.
     *
     * @param Lock $lock
     * @param int  $ttl
     *
     * @return bool
     */
    public function set(Lock $lock, $ttl = null);

    /**
     * Checks if the resource is registered.
     *
     * @param string $resource
     *
     * @return bool
     */
    public function exists($resource);

    /**
     * Delete the lock.
     *
     * @param Lock $lock
     *
     * @return bool
     */
    public function delete(Lock $lock);

    /**
     * Get the drift time for the connection.
     *
     * @param $ttl
     *
     * @return int
     */
    public function getDrift($ttl);
}
