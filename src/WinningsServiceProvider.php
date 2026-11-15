<?php
namespace Funseco\Winnings;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingType;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;

class WinningsServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/winnings.php', 'winnings');
        $this->publishPluginConfig(__DIR__.'/..');

        // All read at use time. Real coppers: amounts have min 0.
        $this->app->make(SettingsRegistry::class)->declare('winnings', 'winnings', [
            Setting::bool('active', 'Winnings enabled'),
            Setting::int('limit', 'Minimum finishers on a bonus map', min: 1),
            Setting::int('min_server_coppers', 'Pause rewards below server balance', min: 0, unit: 'coppers'),
            Setting::int('interval', 'Bonus map every Nth map', min: 1),
            Setting::int('copper_reward', 'Reward', min: 0, unit: 'coppers'),
            Setting::int('scenario_b_limit', 'Finishers for the bigger reward', min: 1),
            Setting::int('scenario_b_coppers', 'Bigger reward', min: 0, unit: 'coppers'),
            Setting::bool('lottery', 'Lottery tickets'),
            Setting::int('lottery_fallback', 'Coppers paid when no lottery', min: 0, unit: 'coppers'),
            Setting::list('ignored', 'Ignored logins'),
        ]);
        $this->app->singleton(Winnings::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        app(ManiaLinkService::class)->addAction(Winnings::HUD);
        Winnings::registerWidget(app(WidgetRegistry::class));

        Event::listen(StartupEvent::class, fn () => app(Winnings::class)->startup());
        Event::listen(MapStarted::class, fn () => app(Winnings::class)->mapStarted());
        Event::listen(MapEnded::class, fn (MapEnded $e) => app(Winnings::class)->mapEnded($e));
    }
}
