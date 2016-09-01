<?php

namespace RemiSan\Lock\Test\Quorum;

use RemiSan\Lock\Quorum\MajorityQuorum;

class MajorityQuorumTest extends \PHPUnit_Framework_TestCase
{
    /** @var MajorityQuorum */
    private $classUnderTest;

    /**
     * Init the mocks
     */
    public function setUp()
    {
        $this->classUnderTest = new MajorityQuorum();
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
    public function itShouldFindTheQuorumIsMetIfFoundOneSuccessOutOfOne()
    {
        $this->classUnderTest->init(1);
        $this->assertFalse($this->classUnderTest->isMet(0));
        $this->assertTrue($this->classUnderTest->isMet(1));
    }

    /**
     * @test
     */
    public function itShouldFindTheQuorumIsMetIfFoundTwoSuccessOutOfTwo()
    {
        $this->classUnderTest->init(2);
        $this->assertFalse($this->classUnderTest->isMet(0));
        $this->assertFalse($this->classUnderTest->isMet(1));
        $this->assertTrue($this->classUnderTest->isMet(2));
    }

    /**
     * @test
     */
    public function itShouldFindTheQuorumIsMetIfFoundTwoSuccessOutOfThree()
    {
        $this->classUnderTest->init(3);
        $this->assertFalse($this->classUnderTest->isMet(0));
        $this->assertFalse($this->classUnderTest->isMet(1));
        $this->assertTrue($this->classUnderTest->isMet(2));
        $this->assertTrue($this->classUnderTest->isMet(3));
    }

    /**
     * @test
     */
    public function itShouldFindTheQuorumIsMetIfFoundThreeSuccessOutOfFour()
    {
        $this->classUnderTest->init(4);
        $this->assertFalse($this->classUnderTest->isMet(0));
        $this->assertFalse($this->classUnderTest->isMet(1));
        $this->assertFalse($this->classUnderTest->isMet(2));
        $this->assertTrue($this->classUnderTest->isMet(3));
        $this->assertTrue($this->classUnderTest->isMet(4));
    }
}
