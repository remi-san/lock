<?php

namespace RemiSan\Lock;

interface Quorum
{
    /**
     * Init the quorum wth the total number of instances.
     *
     * @param int $totalNumber
     */
    public function init($totalNumber);

    /**
     * Check if the quorum has been met.
     *
     * @param int $numberOfSuccess
     *
     * @return mixed
     */
    public function isMet($numberOfSuccess);
}
