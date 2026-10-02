<?php

namespace App\Console\Commands;

use App\Services\Updates\DockerCommandsImplementation;
use App\Services\Updates\Updater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdaterRun extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'updater:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the updater sidecar loop';

    public function handle(): int
    {
        $projectDir = env('UPDATER_PROJECT_DIR', '/project');
        $projectName = env('UPDATER_COMPOSE_PROJECT', 'screening-room');
        $serviceName = env('UPDATER_APP_SERVICE', 'app');

        $updater = new Updater(
            docker: new DockerCommandsImplementation,
            projectDir: $projectDir,
            projectName: $projectName,
            serviceName: $serviceName,
        );

        $this->info('Updater sidecar started');
        $this->info("Project: {$projectName} ({$projectDir})");
        $this->info("Service: {$serviceName}");
        $this->newLine();

        while (true) {
            try {
                $updater->writeHeartbeat();
                $updater->processRequest();
            } catch (\Throwable $e) {
                Log::error('Updater error: '.$e->getMessage(), [
                    'exception' => $e,
                ]);
                $this->error('Updater error: '.$e->getMessage());
                $this->error($e->getTraceAsString());
            }

            sleep(3);
        }
    }
}
