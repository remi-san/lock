<?php

namespace RemiSan\Lock\Test\Mock;

use Webmozart\Assert\Assert;

class MockableRedis extends \Redis
{
    /** @var string */
    public static $expectedScript;

    /** @var string */
    public static $expectedArgs;

    /** @var string */
    public static $expectedNumKeys;

    /**
     * @param string $script
     * @param array  $args
     * @param int    $numKeys
     *
     * @return bool
     */
    public function eval($script, $args = [], $numKeys = 0)
    {
        if (self::$expectedScript !== null) {
            Assert::eq(self::$expectedScript, $script);
        }

        if (self::$expectedArgs !== null) {
            Assert::eq(self::$expectedArgs, $args);
        }

        if (self::$expectedNumKeys !== null) {
            Assert::eq(self::$expectedNumKeys, $numKeys);
        }

        return $this->getEvalReturn();
    }

    /**
     * @return bool
     */
    public function getEvalReturn()
    {
        return true;
    }

    /**
     * @return void
     */
    public static function reset()
    {
        self::$expectedScript = null;
        self::$expectedArgs = null;
        self::$expectedNumKeys = null;
    }
}