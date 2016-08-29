<?php

namespace RemiSan\Lock\Test\TokenGenerator;

use RemiSan\Lock\TokenGenerator\RandomTokenGenerator;

class RandomTokenGeneratorTest extends \PHPUnit_Framework_TestCase
{
    /** @var RandomTokenGenerator */
    private $classUnderTest;

    /**
     * Init the mocks
     */
    public function setUp()
    {
        $this->classUnderTest = new RandomTokenGenerator();
    }

    public function tearDown()
    {
        \Mockery::close();
    }

    /**
     * @test
     */
    public function itShouldAlwaysReturnADifferentToken()
    {
        $this->assertNotEquals($this->classUnderTest->generateToken(), $this->classUnderTest->generateToken());
        $this->assertNotEquals($this->classUnderTest->generateToken(), $this->classUnderTest->generateToken());
    }
}