<?php
namespace Funseco\Winnings\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Funseco\Betting\Tests\Support\FakeBilling;
use Funseco\Core\Testing\FakesServer;
use Funseco\Winnings\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Values\ChallengeInfo;
use Funseco\Core\Servers\TmForever\Values\PlayerRankingsCollection;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\Billing\BillResult;
use Funseco\Core\SharedKernel\Billing\Billing;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Winnings\Winnings;
use Funseco\Winnings\WinningState;

class WinningsTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private FakeBilling $billing;
    private Winnings $winnings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        state()->flush();
        config([
            'winnings.active' => true, 'winnings.limit' => 5, 'winnings.min_server_coppers' => 500000,
            'winnings.interval' => 3, 'winnings.copper_reward' => 40, 'winnings.scenario_b_limit' => 10,
            'winnings.scenario_b_coppers' => 60, 'winnings.lottery' => true, 'winnings.lottery_fallback' => 100,
            'winnings.ignored' => ['p3'],
        ]);
        $this->responses['GetServerCoppers'] = 600000;
        CurrentPlayers::clear();
        for ($i = 1; $i <= 12; $i++) {
            CurrentPlayers::add(Player::factory()->create(['login' => "p$i", 'nickname' => "Nick$i"]));
            state()->forever("funseco:player_united:p$i", true);
        }
        $this->billing = new FakeBilling();
        $this->app->instance(Billing::class, $this->billing);
        $this->app->forgetInstance(Winnings::class);
        $this->winnings = app(Winnings::class);
        $this->winnings->shuffler = function (array &$a) { /* keep order: first winners are deterministic */ };
    }

    private function ended(int $finishers, bool $restart = false): MapEnded
    {
        $rows = [];
        for ($i = 1; $i <= $finishers; $i++) {
            $rows[] = (object) ['login' => "p$i", 'best_time' => 30000 + $i, 'score' => 0];
        }
        $rows[] = (object) ['login' => 'p12', 'best_time' => 0, 'score' => 0];   // did not finish

        return new MapEnded(new Map(), new ChallengeInfo(array_fill(0, 14, 0)), new PlayerRankingsCollection($rows), false, $restart);
    }

    private function counter(): int
    {
        return $this->winnings->counter();
    }

    private function broadcasts(): array
    {
        return $this->messages('ChatSendServerMessage');
    }

    private function bonusMapEnds(int $finishers): void
    {
        WinningState::updateOrCreate(['name' => 'map_counter'], ['value' => '2']);
        $this->winnings->mapEnded($this->ended($finishers));
    }

    public function test_counter_counts_maps_and_announces_the_bonus_map(): void
    {
        $this->winnings->mapEnded($this->ended(6));
        $this->assertSame(1, $this->counter());
        $this->assertContains('$F90» Bonus map in 2 maps!', $this->broadcasts());
        $this->winnings->mapEnded($this->ended(6));
        $this->assertContains('$F90» Bonus map in 1 map!', $this->broadcasts());
        $this->assertSame([], $this->billing->bills);
    }

    public function test_restart_does_not_count(): void
    {
        $this->winnings->mapEnded($this->ended(6, true));
        $this->assertSame(0, $this->counter());
    }

    public function test_hud_and_banner_on_the_bonus_map_only(): void
    {
        config(['funseco.sounds.Cheer' => 'http://x/cheer.mp3']);
        WinningState::create(['name' => 'map_counter', 'value' => '2']);
        $this->winnings->mapStarted();
        $this->assertStringContainsString('cheer.mp3', implode('', array_column($this->sent('SendDisplayManialinkPage'), 0)));
        $this->assertContains('$F90>> BONUS MAP! $F90 3-4 random finishers will win coppers or lottery tickets!', $this->broadcasts());
        $this->assertStringContainsString('BONUS MAP ACTIVE', implode('', array_column($this->sent('SendDisplayManialinkPage'), 0)));
    }

    public function test_startup_clears_a_leftover_bonus_banner(): void
    {
        $this->winnings->startup();

        $this->assertStringContainsString("<manialink id='".\Funseco\Core\Services\ManiaLink\Facades\ManiaLink::getAction(Winnings::HUD)."'></manialink>", implode('', array_column($this->sent('SendDisplayManialinkPage'), 0)));
    }

    public function test_scenario_a_two_copper_winners_and_a_ticket_fallback(): void
    {
        Schema::dropIfExists('lottery_tickets');
        $this->bonusMapEnds(6);   // p1..p6, p3 ignored => 5 eligible
        $this->assertSame(3, $this->counter());

        $this->assertSame([
            ['login' => 'p1', 'coppers' => 40, 'label' => 'Bonus Map Reward!'],
            ['login' => 'p2', 'coppers' => 40, 'label' => 'Bonus Map Reward!'],
            ['login' => 'p4', 'coppers' => 100, 'label' => 'Bonus Reward (Ticket Value)'],
        ], $this->billing->of('pay'));
        $b = $this->broadcasts();
        $this->assertContains('$F90'.str_repeat('━', 35), $b);
        $this->assertContains('$F90>> BONUS MAP WINNERS', $b);
        $this->assertContains('$F90» $fffNick1 $6c0won $F9040 $6c0coppers!', $b);
        $this->assertContains('$F90» $fffNick4 $6c0won $F90100 $6c0coppers!', $b);
    }

    public function test_scenario_b_with_a_real_ticket(): void
    {
        Schema::create('lottery_tickets', function ($t) {
            $t->id();
            $t->string('login');
            $t->integer('purchased_at');
        });
        $this->bonusMapEnds(11);   // 10 eligible (p3 ignored)
        $this->assertSame([['login' => 'p1', 'coppers' => 60, 'label' => 'Bonus Map Reward!'], ['login' => 'p2', 'coppers' => 60, 'label' => 'Bonus Map Reward!']], $this->billing->of('pay'));
        $this->assertSame(['p4', 'p5'], DB::table('lottery_tickets')->orderBy('id')->pluck('login')->all());
        $this->assertContains('$F90» $fffNick4 $6c0won a $F90Free Lottery Ticket $6c0(100 C value)!', $this->broadcasts());
        $this->assertContains('>> You won a Free Lottery Ticket!', array_map(fn ($m) => preg_replace('/^.*>> /', '>> ', $m), $this->messages()));
    }

    public function test_failed_payout_is_not_announced(): void
    {
        $this->billing->autoPay = false;
        Schema::dropIfExists('lottery_tickets');
        $this->bonusMapEnds(6);
        $this->billing->settle(0, BillResult::ERROR, 'Not enough coppers');
        $this->billing->settle(1, BillResult::PAID);
        $text = implode("\n", $this->broadcasts());
        $this->assertStringNotContainsString('Nick1 ', $text);
        $this->assertStringContainsString('Nick2 ', $text);
    }

    public function test_not_enough_finishers_and_low_wallet(): void
    {
        $this->bonusMapEnds(5);   // 4 eligible
        $this->assertContains('$F90» Not enough finishers (4/5). Rewards skipped.', $this->broadcasts());
        $this->assertSame([], $this->billing->bills);

        $this->responses['GetServerCoppers'] = 499999;
        $this->bonusMapEnds(8);
        $this->assertContains('$F90» Server wallet too low. Rewards paused.', $this->broadcasts());
        $this->assertSame([], $this->billing->bills);
    }

    public function test_non_united_and_disconnected_players_cannot_win(): void
    {
        Schema::dropIfExists('lottery_tickets');
        state()->forever('funseco:player_united:p1', false);
        CurrentPlayers::forget('p2');
        $this->bonusMapEnds(8);
        $paid = array_column($this->billing->of('pay'), 'login');
        $this->assertNotContains('p1', $paid);
        $this->assertNotContains('p2', $paid);
        $this->assertNotContains('p3', $paid);
    }

    public function test_inactive_plugin_does_nothing_and_counter_wraps(): void
    {
        config(['winnings.active' => false]);
        $this->winnings->mapEnded($this->ended(6));
        $this->assertSame(0, $this->counter());

        config(['winnings.active' => true]);
        WinningState::create(['name' => 'map_counter', 'value' => '999']);
        $this->winnings->mapEnded($this->ended(6));
        $this->assertSame(0, $this->counter());
    }

    public function test_provider_wiring(): void
    {
        $this->assertTrue(Event::hasListeners(MapStarted::class));
        $this->assertTrue(Event::hasListeners(MapEnded::class));
        $this->assertIsInt(ManiaLink::getAction(Winnings::HUD));
        $this->assertIsInt(ManiaLink::getAction('betting_bet'));
        $this->assertNotNull(app(\Funseco\Core\SharedKernel\UI\WidgetRegistry::class)->placement(Winnings::HUD));
    }
}
