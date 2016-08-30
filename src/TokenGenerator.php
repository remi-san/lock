<?php

namespace RemiSan\Lock;

interface TokenGenerator
{
    /**
     * Generate a string token.
     *
     * @return string
     */
    public function generateToken();
}
