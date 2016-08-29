<?php

namespace RemiSan\Lock\Test\TokenGenerator;

use RemiSan\Lock\TokenGenerator\FixedTokenGenerator;

class FixedTokenGeneratorTest extends \PHPUnit_Framework_TestCase
{
    /** @var string */
    private $token;

    /** @var FixedTokenGenerator */
    private $classUnderTest;

    /**
     * Init the mocks
     */
    public function setUp()
    {
        $this->token = 'token';

        $this->classUnderTest = new FixedTokenGenerator($this->token);
    }

    public function tearDown()
    {
        \Mockery::close();
    }

    /**
     * @test
     */
    public function itShouldAlwaysReturnTheGivenToken()
    {
        $this->assertEquals($this->token, $this->classUnderTest->generateToken());
    }
}