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
     * @return boolean
     */
    public function set(Lock $lock, $ttl = null);

    /**
     * Checks if the resource is registered.
     *
     * @param string $resource
     *
     * @return boolean
     */
    public function exists($resource);

    /**
     * Delete the lock.
     *
     * @param Lock $lock
     *
     * @return boolean
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
