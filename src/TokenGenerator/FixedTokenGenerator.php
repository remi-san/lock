<?php

namespace RemiSan\Lock\TokenGenerator;

use RemiSan\Lock\TokenGenerator;

class FixedTokenGenerator implements TokenGenerator
{
    /** @var string */
    private $token;

    /**
     * FixedTokenGenerator constructor.
     *
     * @param string $token The token to return
     */
    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * {@inheritdoc}
     */
    public function generateToken()
    {
        return $this->token;
    }
}
