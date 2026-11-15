<?php

/*
 * Random finisher winnings (XAseco plugin.nouse.winning2.php). Pays real coppers; ON by default
 * (owner decision 2026-10-29), needs a United server account. Values from the owner's
 * nouse_winning.xml; ignored logins are left empty (the file only held examples).
 */
return [
    'active' => env('WINNINGS_ACTIVE', true),
    'limit' => 5,                    // minimum finishers on a bonus map
    'min_server_coppers' => 500000,  // rewards pause below this balance
    'interval' => 3,                 // every Nth map is a bonus map
    'copper_reward' => 40,
    'scenario_b_limit' => 10,        // this many finishers: bigger reward and one ticket fewer
    'scenario_b_coppers' => 60,
    'lottery' => true,               // tickets need a lottery_tickets table (login, purchased_at)
    'lottery_fallback' => 100,       // coppers paid instead of a ticket when there is no lottery
    'ignored' => [],                 // logins that never win
];
