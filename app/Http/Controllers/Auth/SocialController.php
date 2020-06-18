<?php

namespace Azuriom\Http\Controllers\Auth;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\SocialIdentity;
use Azuriom\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Socialite;

class SocialController extends Controller
{
    /**
     * Redirect the user to the GitHub authentication page.
     *
     * @return \Illuminate\Http\Response
     */
    public function redirectToProvider($provider)
    {
        if (! setting("enable_{$provider}_login")) {
            return redirect()->back()->with('error', "$provider is not enabled for login.");
        }

        if ($provider === 'sign-in-with-apple') {
            return Socialite::driver($provider)->scopes(['name', 'email'])->redirect();
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Obtain the user information from GitHub.
     *
     * @return \Illuminate\Http\Response
     */
    public function handleProviderCallback(Request $request, $provider)
    {
        try {
            $user = Socialite::driver($provider)->user();
        } catch (Exception $e) {
            return redirect('/login');
        }

        $authUser = $this->findOrCreateUser($request, $user, $provider);
        if ($authUser === false) {
            redirect('/')->with('error', "The user email is not given by $provider, please change your app permisions");
        }
        if ($authUser->refreshActiveBan()->is_banned || $authUser->is_deleted) {
            return redirect('/')->with('error', trans('auth.suspended'));
        }
        Auth::login($authUser, true);

        return redirect('/profile');
    }

    public function findOrCreateUser($request, $providerUser, $provider)
    {
        $account = SocialIdentity::whereProviderName($provider)
                    ->whereProviderId($providerUser->getId())
                    ->first();

        if ($account) {
            $account->data = [
                'avatar' => $providerUser->getAvatar(),
            ];
            $account->save();

            return $account->user;
        } else {
            $user = null;
            if ($request->user()) {
                $user = $request->user();
            } else {
                $user = User::whereEmail($providerUser->getEmail())->first();
            }

            if (! $user) {
                if (empty($providerUser->getEmail())) {
                    return false;
                }
                $user = User::create([
                    'email' => $providerUser->getEmail(),
                    'name'  => $providerUser->getName(),
                    'password' => Hash::make(Str::random(8)),
                    'game_id' => null,
                    'settings' => [
                        'new_user' => true,
                    ],
                ]);
            }

            $user->identities()->create([
                'provider_id'   => $providerUser->getId(),
                'provider_name' => $provider,
                'data' => [
                    'avatar' => $providerUser->getAvatar(),
                ],
            ]);
        }

        return $user;
    }
}