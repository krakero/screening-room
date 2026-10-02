<?php

namespace App\Console\Commands;

use App\Services\Plex\PlexDiscovery;
use App\Support\IntegrationSettings;
use Illuminate\Console\Command;

class RecheckPlexConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plex:recheck-connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-runs Plex server connection selection (local → remote → relay) when discovery is in use, updating plex.url if a better or working connection is found.';

    public function handle(IntegrationSettings $settings, PlexDiscovery $discovery): int
    {
        if ((bool) $settings->get('plex.manual_override', false)) {
            $this->components->info('Manual Plex URL override is on; skipping connection re-check.');

            return self::SUCCESS;
        }

        $switched = $discovery->reselectConnection();

        if ($switched) {
            $this->components->info(sprintf(
                'Connection re-check: using %s (%s, %sms).',
                (string) $settings->get('plex.url'),
                (string) $settings->get('plex.connection_mode'),
                (string) $settings->get('plex.connection_latency_ms'),
            ));
        } elseif ((bool) $settings->get('plex.unreachable', false)) {
            $this->components->warn('Connection re-check: Plex is unreachable on every known connection.');
        } else {
            $this->components->info('Connection re-check: nothing to do (no server selected, or checked recently).');
        }

        return self::SUCCESS;
    }
}
