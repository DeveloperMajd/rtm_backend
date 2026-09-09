<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchContactsRequest;
use App\Http\Requests\StoreContactRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\ConversationResource;
use App\Models\Contact;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $contacts = $request->user()
            ->contacts()
            ->orderBy('name')
            ->get();

        return ContactResource::collection($contacts);
    }

    /**
     * Directory search for the "add contact" flow: match name or email,
     * excluding the current user and people already in their contacts.
     */
    public function search(SearchContactsRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $term = '%'.$request->string('q')->trim().'%';

        $matches = User::query()
            ->whereKeyNot($user->id)
            ->whereNotIn('id', function ($query) use ($user): void {
                $query->from('contacts')
                    ->select('contact_user_id')
                    ->where('user_id', $user->id);
            })
            ->where(function ($query) use ($term): void {
                $query->where('name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term);
            })
            ->orderBy('name')
            ->limit(10)
            ->get();

        return ContactResource::collection($matches);
    }

    public function store(StoreContactRequest $request, ConversationService $conversations): JsonResponse
    {
        $user = $request->user();
        $target = User::findOrFail($request->input('user_id'));

        Contact::firstOrCreate([
            'user_id' => $user->id,
            'contact_user_id' => $target->id,
        ]);

        $conversation = $conversations->findOrCreateDirect($user, $target);
        $conversation->load('participants.user');

        return response()->json([
            'data' => [
                'contact' => new ContactResource($target),
                'conversation' => new ConversationResource($conversation),
            ],
        ], 201);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $request->user()->contacts()->detach($user->id);

        return response()->json(['data' => ['message' => 'Contact removed.']]);
    }
}
