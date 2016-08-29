<?php

namespace RemiSan\Lock;

interface TokenGenerator
{
    /**
     * @return string
     */
    public function generateToken();
}
