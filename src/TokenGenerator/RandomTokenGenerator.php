<?php

namespace RemiSan\Lock\TokenGenerator;

use RemiSan\Lock\TokenGenerator;

class RandomTokenGenerator implements TokenGenerator
{
    /**
     * @inheritDoc
     */
    public function generateToken()
    {
        return (string) base64_encode(openssl_random_pseudo_bytes(32));
    }
}
