<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The group as a member last saw it, kept on their row when they leave
     * or are removed: its title and who was in it, with their roles
     * ({"title": ..., "members": [{"user_id": ..., "role": ...}]}). What
     * they're shown of it afterwards comes from here, not from the group as
     * it is now. See ConversationParticipantService.
     *
     * Members who left before this column existed get the closest the rows
     * can tell: everyone who had joined by then and hadn't left before them,
     * with the role they have now, and the title the first rename after
     * they left changed (or the title now, if there's been none). Someone
     * who was in it then, left later and has since been added back joined
     * again after them, and isn't listed. It errs that way, never towards
     * someone who joined later.
     *
     * Nullable with no default, so adding it is a catalogue change in
     * Postgres: no table rewrite and no lock held while the app runs.
     */
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->jsonb('left_snapshot')->nullable();
        });

        DB::table('conversation_participants as former')
            ->join('conversations', 'conversations.id', '=', 'former.conversation_id')
            ->whereNotNull('former.left_at')
            ->select('former.id', 'former.conversation_id', 'former.user_id', 'former.left_at', 'former.left_at_message_id', 'conversations.title')
            ->lazyById(100, 'former.id', 'id')
            ->each(function (object $former): void {
                $members = DB::table('conversation_participants')
                    ->where('conversation_id', $former->conversation_id)
                    ->where('user_id', '!=', $former->user_id)
                    ->where('joined_at', '<=', $former->left_at)
                    ->where(fn (Builder $stillIn): Builder => $former->left_at_message_id !== null
                        ? $stillIn->whereNull('left_at_message_id')->orWhere('left_at_message_id', '>', $former->left_at_message_id)
                        : $stillIn->whereNull('left_at')->orWhere('left_at', '>', $former->left_at))
                    ->orderBy('id')
                    ->get(['user_id', 'role']);

                $renamedSince = DB::table('messages')
                    ->where('conversation_id', $former->conversation_id)
                    ->where('event_type', 'group_renamed')
                    ->when(
                        $former->left_at_message_id !== null,
                        fn (Builder $after): Builder => $after->where('id', '>', $former->left_at_message_id),
                        fn (Builder $after): Builder => $after->where('created_at', '>', $former->left_at),
                    )
                    ->orderBy('id')
                    ->value('metadata');

                $title = $renamedSince !== null
                    ? (json_decode($renamedSince, true)['old_title'] ?? $former->title)
                    : $former->title;

                DB::table('conversation_participants')->where('id', $former->id)->update([
                    'left_snapshot' => json_encode([
                        'title' => $title,
                        'members' => $members->map(fn (object $member): array => [
                            'user_id' => $member->user_id,
                            'role' => $member->role,
                        ])->all(),
                    ]),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropColumn('left_snapshot');
        });
    }
};
