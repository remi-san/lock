<?php

namespace RemiSan\Lock;

final class Lock
{
    /** @var string */
    private $resource;

    /** @var string */
    private $token;

    /** @var int */
    private $validityTimeEnd;

    /**
     * Lock constructor.
     *
     * @param string $resource
     * @param string $token
     */
    public function __construct($resource, $token)
    {
        $this->resource = $resource;
        $this->token = $token;
        $this->validityTimeEnd = null;
    }

    /**
     * @return string
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * @param int $validityTimeEnd
     */
    public function setValidityTimeEnd($validityTimeEnd)
    {
        $this->validityTimeEnd = $validityTimeEnd;
    }

    /**
     * @return int
     */
    public function getValidityTimeEnd()
    {
        return $this->validityTimeEnd;
    }
}
