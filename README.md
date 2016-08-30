Lock
====

[![Author](https://img.shields.io/badge/author-@RemiSan-blue.svg?style=flat-square)](https://twitter.com/RemiSan)
[![Build Status](https://img.shields.io/travis/remi-san/lock/master.svg?style=flat-square)](https://travis-ci.org/remi-san/lock)
[![Quality Score](https://img.shields.io/scrutinizer/g/remi-san/lock.svg?style=flat-square)](https://scrutinizer-ci.com/g/remi-san/lock)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/remi-san/lock.svg?style=flat-square)](https://packagist.org/packages/remi-san/lock)
[![Coverage Status](https://img.shields.io/scrutinizer/coverage/g/remi-san/lock.svg?style=flat-square)](https://scrutinizer-ci.com/g/remi-san/lock/code-structure)
[![SensioLabsInsight](https://insight.sensiolabs.com/projects/45db8cbd-70a8-4c09-9b80-32b51ba92c86/mini.png)](https://insight.sensiolabs.com/projects/45db8cbd-70a8-4c09-9b80-32b51ba92c86)

Description
----------------

**`Lock`** is a library aimed at providing a simple and reliable way to lock resources.

Main classes
-----------------

- `RemiSan\Lock\Locker` which provides an interface containing the following methods:
	- `lock` to lock a resource for a given time (`ttl` - not mandatory), allowing to retry a certain amount of times until success.
	- `isLocked` to check if a resource is still locked.
	- `unlock` to unlock a resource.

- `RemiSan\Lock\Lock` which provides a structure to store information about the lock:
	- `resource` the resource that has been locked
	- `token` a token generated by the `Locker` (using a `RemiSan\Lock\TokenGenerator` implementation) to ensure the requested unlocking resource is the same that the one who's been locked.
	- `validityEndTime` the time (in milliseconds since EPOCH) at which the lock will be automatically released (if a ttl has been defined).

Token Generators
------------------------

As the `Locker` will need to generate a unique token to lock the `resource`, a `TokenGenerator` interface has been defined, and 2 implementations are available:

- `RandomTokenGenerator` which will provide a random `md5 hash`
- `FixedTokenGenerator` which will always provide the token passed in the constructor.

**Examples:**

```php
use RemiSan\Lock\TokenGenerator\RandomTokenGenerator;

$tokenGenerator = new RandomTokenGenerator();
echo $tokenGenerator->generateToken(); // 'QcWY1WFoRTC68vTNIkTs5cuLmw9YuY9rwS6IsY0xjzA='
```

```php
use RemiSan\Lock\TokenGenerator\FixedTokenGenerator;

$tokenGenerator = new FixedTokenGenerator('my_token');
echo $tokenGenerator->generateToken(); // 'my_token'
```

Usage
--------
**Acquire a lock**

You can acquire a lock on a resource by providing its name, the `ttl`,  the retry count and the time (in milliseconds) to wait before retrying.

```php
$lock = $redLock->lock('my_resource_name', 1000, 3, 100);
```

This example will try to lock the resource `my_resource_name` for 1 second (1000ms) and will retry to acquire it 3 times if it fails the first time (4 in total if all fail), waiting 100ms between each try.

If the lock is acquired, it will return a `Lock` object describing it.

If it failed being acquired, it will throw a `RemiSan\Lock\Exceptions\LockingException`.

**Assert if a lock exists**

You can ask the `Locker` if a resource is still locked.

```php
$isLocked = $redLock->isLocked('my_resource_name');
```

If the resource is still locked (lock has been acquired and ttl hasn't expired), it will return `true`, it will return false otherwise.

**Release a lock**

To release a lock you have to provide it to the `Locker`.

```php
$redLock->unlock($lock);
```

If the lock is still active, it will release it. If it fails but the lock wasn't active anymore, it won't cause any error.

If it fails releasing the lock and the lock is still active, it will throw a `RemiSan\Lock\Exceptions\UnlockingException`.

Redis Implementation
-------------------------------
Based on [Redlock-rb](https://github.com/antirez/redlock-rb) by [Salvatore Sanfilippo](https://github.com/antirez) and [ronnylt/redlock-php](https://github.com/ronnylt/redlock-php).

This library implements the Redis-based distributed lock manager algorithm [described in this Redis article](http://redis.io/topics/distlock).

**Create**

You can create a `Redis Locker` or `RedLock` by providing an array of connected `Redis` instances.

```php
use RemiSan\Lock\Implementations\RedLock;

$instance1 = new \Redis();
$server->connect('127.0.0.1', 6379, 0.1);

$instance2 = new \Redis();
$server->connect('127.0.0.1', 6380, 0.1);

$redLock = new RedLock([ $instance1, $instance2 ]);
```

This class works as described earlier but has specificities due to the use of multiple `Redis` instances.

**Acquire a lock**

The lock will be acquired only if more than half of the instances of `Redis` have been able to acquire the lock. (The calculation of the `quorum` is the minimum value between the number of instances and half the instances plus one - ie. for 4 instances, the quorum will be 3 / for 10 instances, it will be 6).

**Assert if a lock exists**

If at least one `Redis` instance has the lock, it will consider having the lock.

**Release a lock**

If at least one `Redis` instance fails releasing the lock while still detaining it, the exception will be thrown.

**DISCLAIMER**: As stated in the original `antirez` version, this code implements an algorithm which is currently a proposal, it was not formally analyzed. Make sure to understand how it works before using it in your production environments.

Other Implementations
---------------------------------
That will come at some point, but it's not there yet.
