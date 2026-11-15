<?php
namespace Funseco\Winnings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Funseco\PlayerAccounts;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\Billing\BillResult;
use Funseco\Core\SharedKernel\Billing\Billing;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\UI\Region;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;

/**
 * Every Nth map is a bonus map: after it a few random finishers win coppers or a lottery ticket.
 * Payouts go through Billing::pay; a winner is only announced once the payout went through.
 */
class Winnings
{
    public const HUD = 'winnings_hud';
    private const COUNTER = 'map_counter';

    /** Test seam: replaces shuffle(). */
    public ?\Closure $shuffler = null;

    public function __construct(private Billing $billing)
    {
    }

    public static function registerWidget(WidgetRegistry $registry): void
    {
        $registry->regionWidget(self::HUD, Region::TopRight, 94, width: 15.0, height: 2.5);
    }

    private function active(): bool
    {
        return (bool) config('winnings.active', false);
    }

    private function interval(): int
    {
        return max(1, (int) config('winnings.interval', 3));
    }

    public function counter(): int
    {
        return (int) WinningState::where('name', self::COUNTER)->value('value');
    }

    private function saveCounter(int $value): void
    {
        WinningState::updateOrCreate(['name' => self::COUNTER], ['value' => (string) $value]);
    }

    /** After a restart an old BONUS banner may still be on screen: clear it. */
    public function startup(): void
    {
        $this->hideHud();
    }

    public function mapStarted(): void
    {
        if (!$this->active()) {
            return;
        }
        if (($this->counter() + 1) % $this->interval() === 0) {
            $this->showHud();
            $this->say('$F90>> BONUS MAP! $F90 3-4 random finishers will win coppers or lottery tickets!');
            app(\Funseco\Core\SharedKernel\Sound::class)->play('Cheer');
        } else {
            $this->hideHud();
        }
    }

    public function mapEnded(MapEnded $e): void
    {
        if (!$this->active() || $e->wasWarmup || $e->restart) {
            return;
        }
        $counter = $this->counter() + 1;
        if ($counter >= 1000) {
            $counter = 0;
        }
        $this->saveCounter($counter);
        $this->hideHud();

        $interval = $this->interval();
        if ($counter % $interval !== 0) {
            $left = $interval - $counter % $interval;
            $this->say('$F90» Bonus map in '.$left.' '.($left === 1 ? 'map' : 'maps').'!');

            return;
        }
        // counter 0 after the 1000 wrap is a multiple of the interval too: a bonus map.
        \Server::query()->GetServerCoppers(function ($result) use ($e) {
            if ((int) ($result[0] ?? 0) < (int) config('winnings.min_server_coppers')) {
                $this->say('$F90» Server wallet too low. Rewards paused.');

                return;
            }
            $this->draw($e);
        });
    }

    private function draw(MapEnded $e): void
    {
        $accounts = app(PlayerAccounts::class);
        $ignored = (array) config('winnings.ignored', []);
        $eligible = [];
        foreach ($e->rankings as $r) {
            $player = CurrentPlayers::get((string) $r->login);
            if ((int) $r->best_time > 0 && $player && !in_array($r->login, $ignored, true) && $accounts->isUnited($player)) {
                $eligible[$r->login] = $player->nickname;
            }
        }

        $limit = (int) config('winnings.limit');
        if (count($eligible) < $limit) {
            $this->say('$F90» Not enough finishers ('.count($eligible).'/'.$limit.'). Rewards skipped.');

            return;
        }

        $b = count($eligible) >= (int) config('winnings.scenario_b_limit');
        $amount = (int) config($b ? 'winnings.scenario_b_coppers' : 'winnings.copper_reward');
        $coppers = 2;
        $tickets = $b ? 2 : 1;

        $logins = array_keys($eligible);
        $this->shuffler ? ($this->shuffler)($logins) : shuffle($logins);
        $winners = array_slice($logins, 0, $coppers + $tickets);   // never more winners than finishers
        $copperWinners = array_slice($winners, 0, $coppers);
        $ticketWinners = array_slice($winners, $coppers);

        $rule = '$F90'.str_repeat('━', 35);
        $this->say($rule);
        $this->say('$F90>> BONUS MAP WINNERS');
        app(\Funseco\Core\SharedKernel\Sound::class)->play('Planets');
        foreach ($copperWinners as $login) {
            $this->payCoppers($login, $eligible[$login], $amount, 'Bonus Map Reward!');
        }
        foreach ($ticketWinners as $login) {
            $this->ticket($login, $eligible[$login]);
        }
        $this->say($rule);
    }

    private function ticket(string $login, string $nick): void
    {
        if (config('winnings.lottery') && Schema::hasTable('lottery_tickets')) {
            DB::table('lottery_tickets')->insert(['login' => $login, 'purchased_at' => now()->getTimestamp()]);
            $this->say('$F90» $fff{nick} $6c0won a $F90Free Lottery Ticket $6c0(100 C value)!', $nick);
            if (CurrentPlayers::has($login)) {
                \Server::query()->ChatSendServerMessageToLogin(format_colors('{#server}>> You won a Free Lottery Ticket!'), $login);
            }

            return;
        }
        if (CurrentPlayers::has($login)) {
            $this->payCoppers($login, $nick, (int) config('winnings.lottery_fallback'), 'Bonus Reward (Ticket Value)');
        }
    }

    private function payCoppers(string $login, string $nick, int $amount, string $label): void
    {
        $this->billing->pay($login, $amount, $label)->then(function (BillResult $r) use ($login, $nick, $amount) {
            if ($r->paid()) {
                $this->say('$F90» $fff{nick} $6c0won $F90'.$amount.' $6c0coppers!', $nick);
            } else {
                Log::warning("Winnings: payout of {$amount} coppers to \"{$login}\" failed ({$r->state}: {$r->stateName})");
            }
        });
    }

    private function say(string $message, ?string $nick = null): void
    {
        \Server::query()->ChatSendServerMessage(strtr(format_colors($message), ['{nick}' => (string) $nick]));
    }

    private function showHud(): void
    {
        $p = app(WidgetRegistry::class)->placement(self::HUD);
        if (!$p) {
            return;
        }
        ManiaLink::displayAll('<manialink id="'.ManiaLink::getAction(self::HUD).'"><frame posn="'.$p->x.' '.$p->y.' 0">'
            .'<label posn="15 0 1" sizen="40 3" halign="right" scale="0.7" text="$F90BONUS MAP ACTIVE"/>'
            .'</frame></manialink>');
    }

    private function hideHud(): void
    {
        ManiaLink::resetAll(self::HUD);
    }
}
