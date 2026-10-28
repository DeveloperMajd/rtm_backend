<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Removes files from storage once nothing refers to them any more — the
 * attachments of a deleted message. Their rows are gone first, in the
 * request, so no new link to them can be made; this takes the files
 * themselves, and with them any link already handed out.
 *
 * Queued, so a slow or unreachable bucket never holds up (or fails) the
 * delete. Deleting a file that's already gone succeeds, so a retry is safe.
 */
class DeleteStoredFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /**
     * @param  list<array{disk: string, path: string}>  $files
     */
    public function __construct(public array $files) {}

    public function handle(): void
    {
        foreach (collect($this->files)->groupBy('disk') as $disk => $files) {
            Storage::disk($disk)->delete($files->pluck('path')->all());
        }
    }
}
