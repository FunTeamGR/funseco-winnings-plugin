<?php

namespace Funseco\Winnings\Tests;

use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Betting\BettingServiceProvider;
use Funseco\Winnings\WinningsServiceProvider;

abstract class TestCase extends CoreTestCase
{
    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            BettingServiceProvider::class,
            WinningsServiceProvider::class,
        ];
    }
}
