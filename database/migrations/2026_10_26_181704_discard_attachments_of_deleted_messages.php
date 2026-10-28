<?php

use App\Jobs\DeleteStoredFiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Messages deleted before their files went with them (see
     * Message::discardAttachments()) still have theirs, in the table and in
     * storage. Lets go of those the same way: the rows here, the files on
     * the queue.
     */
    public function up(): void
    {
        DB::table('attachments')
            ->join('messages', 'messages.id', '=', 'attachments.message_id')
            ->whereNotNull('messages.deleted_at')
            ->select('attachments.id', 'attachments.disk', 'attachments.path')
            ->chunkById(500, function (Collection $attachments): void {
                DeleteStoredFiles::dispatch(
                    $attachments->map(fn (object $attachment): array => [
                        'disk' => $attachment->disk,
                        'path' => $attachment->path,
                    ])->values()->all(),
                );

                DB::table('attachments')->whereIn('id', $attachments->pluck('id'))->delete();
            }, 'attachments.id', 'id');
    }

    /**
     * The files are gone; there's nothing to put back.
     */
    public function down(): void
    {
        //
    }
};
