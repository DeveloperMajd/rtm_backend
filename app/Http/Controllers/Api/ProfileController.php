<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\StoreAvatarRequest;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($request->user());
    }

    public function update(ProfileUpdateRequest $request): ProfileResource
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'bio']));
        $user->save();

        return new ProfileResource($user);
    }

    public function updatePassword(ChangePasswordRequest $request): Response
    {
        $request->user()->forceFill([
            'password' => $request->string('password')->toString(),
        ])->save();

        return response()->noContent();
    }

    public function updateAvatar(StoreAvatarRequest $request): ProfileResource
    {
        $user = $request->user();
        $disk = config('filesystems.avatars');

        $path = $request->file('avatar')->storePublicly('avatars', ['disk' => $disk]);

        $this->deleteStoredAvatar($user);

        $user->avatar_path = $path;
        $user->avatar_url = Storage::disk($disk)->url($path);
        $user->save();

        return new ProfileResource($user);
    }

    public function destroyAvatar(Request $request): ProfileResource
    {
        $user = $request->user();

        $this->deleteStoredAvatar($user);

        $user->avatar_path = null;
        $user->avatar_url = null;
        $user->save();

        return new ProfileResource($user);
    }

    /**
     * Remove the user's previously uploaded avatar file, if any. A provider
     * (OAuth) avatar has no `avatar_path` and its file is not ours to delete.
     */
    private function deleteStoredAvatar(User $user): void
    {
        if ($user->avatar_path !== null) {
            Storage::disk(config('filesystems.avatars'))->delete($user->avatar_path);
        }
    }
}
