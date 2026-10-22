<?php

use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * What AuthenticateSession keeps in a session: an HMAC of the password hash
 * the session was signed in with. It ends the session once that no longer
 * matches the account's password.
 */
function sessionFingerprint(string $passwordHash): string
{
    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');

    return $guard->hashPasswordForCookie($passwordHash);
}

/**
 * The testing default (BROADCAST_CONNECTION=null) skips channel
 * authorization entirely: the null driver's auth() is a no-op, so
 * /broadcasting/auth would return 200 whatever routes/channels.php says.
 * This swaps in the Pusher-protocol driver (what Reverb uses) with dummy
 * local credentials, so signing runs in-process with no network involved.
 * Call it right before the /broadcasting/auth request itself: the same
 * driver would try, and fail, to really deliver any ShouldBroadcast event
 * raised by the setup before it.
 */
function useLocalPusherAuth(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
    ]);

    // Broadcast::channel() registers against whichever driver is current at
    // call time, and routes/channels.php already ran at boot against the
    // null driver, so its channels live in that driver's registry. Running
    // the file again (Laravel's bootstrap uses a plain `require`, so this is
    // safe) registers them on the driver just selected.
    require base_path('routes/channels.php');
}
