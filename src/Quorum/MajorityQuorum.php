<?php

namespace RemiSan\Lock\Quorum;

use RemiSan\Lock\Quorum;

class MajorityQuorum implements Quorum
{
    /**
     * @var int
     */
    private $total;

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

        $this->total = $totalNumber;
        $this->quorum = floor($totalNumber / 2) + 1;
    }

    /**
     * @inheritDoc
     */
    public function isMet($numberOfSuccess)
    {
        if ($numberOfSuccess < 0 ||
            $numberOfSuccess > $this->total) {
            throw new \InvalidArgumentException();
        }

        return $numberOfSuccess >= $this->quorum;
    }
}
