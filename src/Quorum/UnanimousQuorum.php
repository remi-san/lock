<?php

namespace RemiSan\Lock\Quorum;

use RemiSan\Lock\Quorum;

class UnanimousQuorum implements Quorum
{
    /**
     * @var int
     */
    private $quorum = null;

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
        if ($this->quorum === null) {
            throw new \RuntimeException('You must init the Quorum before querying it.');
        }

        if ($numberOfSuccess < 0 ||
            $numberOfSuccess > $this->quorum) {
            throw new \InvalidArgumentException(
                'Number of success cannot be inferior to zero or superior to the number of stores.'
            );
        }

        return $numberOfSuccess === $this->quorum;
    }
}
