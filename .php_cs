<?php

return Symfony\CS\Config\Config::create()
    ->level(Symfony\CS\FixerInterface::SYMFONY_LEVEL)
    ->fixers(array(
        'ordered_use',
        'concat_with_spaces',
        'header_comment',
        'newline_after_open_tag',
        'phpdoc_order',
        'short_array_syntax'
    ))
    ->finder(
        Symfony\CS\Finder\DefaultFinder::create()
            ->in(array('src'))
    )
;
