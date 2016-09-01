<?php

namespace RemiSan\Lock\Test\Quorum;

use RemiSan\Lock\Quorum\UnanimousQuorum;

class UnanimousQuorumTest extends \PHPUnit_Framework_TestCase
{
    /** @var UnanimousQuorum */
    private $classUnderTest;

    /**
     * Init the mocks
     */
    public function setUp()
    {
        $this->classUnderTest = new UnanimousQuorum();
    }

    public function tearDown()
    {
        \Mockery::close();
    }

    /**
     * @test
     */
    public function itShouldNotBeAllowedToGiveATotalLowerThanOne()
    {
        $this->setExpectedException(\InvalidArgumentException::class);

        $this->classUnderTest->init(0);
    }

    /**
     * @test
     */
    public function itShouldNotBeAbleToQueryTheQuorumWithoutInitializingIt()
    {
        $this->setExpectedException(\RuntimeException::class);

        $this->assertFalse($this->classUnderTest->isMet(1));
    }

    /**
     * @test
     */
    public function itShouldNotBeAbleToHaveLessSuccessesThanZero()
    {
        $this->classUnderTest->init(1);

        $this->setExpectedException(\InvalidArgumentException::class);

        $this->assertFalse($this->classUnderTest->isMet(-1));
    }

    /**
     * @test
     */
    public function itShouldNotBeAbleToHaveMoreSuccessesThanTotalStores()
    {
        $this->classUnderTest->init(1);

        $this->setExpectedException(\InvalidArgumentException::class);

        $this->assertFalse($this->classUnderTest->isMet(2));
    }

    /**
     * @test
     */
    public function itShouldFindTheQuorumIsMetIfTheNumberOfSuccessEqualsTheTotalNumber()
    {
        $this->classUnderTest->init(10);
        $this->assertFalse($this->classUnderTest->isMet(0));
        $this->assertFalse($this->classUnderTest->isMet(1));
        $this->assertFalse($this->classUnderTest->isMet(2));
        $this->assertFalse($this->classUnderTest->isMet(3));
        $this->assertFalse($this->classUnderTest->isMet(4));
        $this->assertFalse($this->classUnderTest->isMet(5));
        $this->assertFalse($this->classUnderTest->isMet(6));
        $this->assertFalse($this->classUnderTest->isMet(7));
        $this->assertFalse($this->classUnderTest->isMet(8));
        $this->assertFalse($this->classUnderTest->isMet(9));
        $this->assertTrue($this->classUnderTest->isMet(10));
    }
}
