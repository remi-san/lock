<?php
date_default_timezone_set('Europe/Paris');
include_once dirname(__DIR__) . '/vendor/autoload.php';

\Mockery::getConfiguration()->allowMockingNonExistentMethods(false);
