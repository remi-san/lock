<?php

namespace RemiSan\Lock\Test;

use RemiSan\Lock\Lock;

class LockTest extends \PHPUnit_Framework_TestCase
{
    /** @var string */
    private $resource;

    /** @var string */
    private $token;

    /** @var int */
    private $validityEndTime;

    /** @var Lock */
    private $classUnderTest;

    /**
     * Init the mocks
     */
    public function setUp()
    {
        $this->resource = '';
        $this->token = '';
        $this->validityEndTime = (int) microtime(true) * 1000;

        $this->classUnderTest = new Lock($this->resource, $this->token);
    }

    public function tearDown()
    {
        \Mockery::close();
    }

    /**
     * @test
     */
    public function testGetters()
    {
        $this->assertEquals($this->resource, $this->classUnderTest->getResource());
        $this->assertEquals($this->token, $this->classUnderTest->getToken());
        $this->assertNull($this->classUnderTest->getValidityEndTime());
    }

    /**
     * @test
     */
    public function validityTimeEndIsSettable()
    {
        $this->classUnderTest->setValidityEndTime($this->validityEndTime);
        $this->assertEquals($this->validityEndTime, $this->classUnderTest->getValidityEndTime());
    }
}