<?php

namespace App\Jobs;

use App\Actions\Requests\RequestTitle;
use App\Enums\LibraryState;
use App\Events\TitleRequestFailed;
use App\Models\LibraryStatus;
use App\Models\Title;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Submits a title request to Seerr off the request/response cycle. The
 * controller marks the title "pending" and returns immediately; this job
 * confirms it as "requested" on success or "failed" on failure.
 */
class SubmitTitleRequest implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, int>  $seasonNumbers  Ignored for movies.
     */
    public function __construct(public Title $title, public array $seasonNumbers = []) {}

    public function handle(RequestTitle $requestTitle): void
    {
        try {
            $requestTitle->handle($this->title, $this->seasonNumbers);
        } catch (Throwable $exception) {
            LibraryStatus::updateOrCreate(
                ['title_id' => $this->title->id],
                ['state' => LibraryState::Failed],
            );

            TitleRequestFailed::dispatch($this->title);

            throw $exception;
        }
    }
}
