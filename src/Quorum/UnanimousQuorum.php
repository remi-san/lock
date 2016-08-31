<?php

namespace RemiSan\Lock\Quorum;

use RemiSan\Lock\Quorum;

class UnanimousQuorum implements Quorum
{
    /**
     * @var int
     */
    private $quorum;

    /**
     * @inheritDoc
     */
    public function init($totalNumber)
    {
        if ($totalNumber < 1) {
            throw new \InvalidArgumentException();
        }

        $this->quorum = $totalNumber;
    }

    /**
     * @inheritDoc
     */
    public function isMet($numberOfSuccess)
    {
        if ($numberOfSuccess < 0 ||
            $numberOfSuccess > $this->quorum) {
            throw new \InvalidArgumentException();
        }

        return $numberOfSuccess >= $this->quorum;
    }
}
