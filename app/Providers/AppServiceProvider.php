<?php

namespace App\Providers;

use App\Models\Episode;
use App\Models\Person;
use App\Models\Title;
use App\Models\User;
use App\Services\MediaRequests\MediaRequestService;
use App\Services\Seerr\SeerrClient;
use App\Support\IntegrationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(IntegrationSettings::class);

        $this->app->bind(MediaRequestService::class, SeerrClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDevCommands();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        RateLimiter::for('trakt-import-tmdb', fn () => Limit::perSecond(40));

        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        Relation::enforceMorphMap([
            'title' => Title::class,
            'episode' => Episode::class,
            'person' => Person::class,
            'user' => User::class,
        ]);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * `composer run dev` (`php artisan dev`) otherwise runs its queue worker
     * with the framework's default `queue:listen --tries=1 --timeout=0`,
     * which processes each job in a fresh `queue:work --once` child process
     * whose own default 60s process timeout is well under a slow Trakt
     * import job's actual runtime — that job gets killed mid-run, and with
     * `--tries=1` it's simply lost rather than retried. Registering our own
     * "queue" command here (userland priority beats the framework's default
     * one, regardless of boot order) raises both: `--timeout=120` keeps the
     * worker from killing a job before `ImportTraktTitles`'s own $timeout=55
     * would end it, and `--tries=3` matches production.
     *
     * `--timeout` here must in turn stay below the queue connection's
     * `retry_after` (config/queue.php, raised to 180s) — otherwise the queue
     * driver would consider a still-running job "lost" and hand it to a
     * second worker before this worker's own 120s kill ever fires, running
     * it twice. So: job $timeout (55s) < worker `--timeout` (120s) <
     * `retry_after` (180s). Recommended production worker: `php artisan
     * queue:work --timeout=120 --tries=3`.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::artisan('queue:listen --tries=3 --timeout=120', 'queue');
    }
}
