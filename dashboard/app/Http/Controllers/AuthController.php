<?php

/**
 * Authentication Controller
 */

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // â”€â”€ Show Login â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.login');
    }

    // â”€â”€ Handle Login â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Invalid email or password.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))
            ->with('success', 'Welcome back, ' . Auth::user()->name . '!');
    }

    // â”€â”€ Show Register â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function showRegister()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.register');
    }

    // â”€â”€ Handle Register â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function register(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|min:2|max:255',
            'email'         => 'required|email|max:255|unique:users',
            'password'      => 'required|min:6|confirmed',
            'business_name' => 'required|string|min:2|max:255',
            'industry'      => 'nullable|string|max:100',
        ]);

        DB::transaction(function () use ($request) {
            // 1. Create business
            $business = Business::create([
                'name'     => $request->input('business_name'),
                'industry' => $request->input('industry'),
                'owner_id' => 0, // temp â€” will update after user creation
                'timezone' => 'UTC',
            ]);

            // 2. Create user linked to business
            $user = User::create([
                'name'        => $request->input('name'),
                'email'       => $request->input('email'),
                'password'    => Hash::make($request->input('password')),
                'role'        => 'owner',
                'business_id' => $business->id,
            ]);

            // 3. Update business owner
            $business->update(['owner_id' => $user->id]);

            // 4. Log in immediately
            Auth::login($user);
        });

        request()->session()->regenerate();

        return redirect()->route('dashboard.setup')
            ->with('success', 'Account created! Let\'s finish setting up your business.');
    }

    // â”€â”€ Logout â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('info', 'You have been logged out.');
    }

    // â”€â”€ Setup Wizard â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function showSetup()
    {
        return view('setup');
    }

    public function handleSetup(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'industry'    => 'nullable|string|max:100',
            'website'     => 'nullable|url|max:500',
            'phone'       => 'nullable|string|max:50',
            'address'     => 'nullable|string|max:1000',
            'timezone'    => 'nullable|string|max:50',
            'brand_voice' => 'nullable|string|max:2000',
        ]);

        /** @var User $authUser */
        $authUser = Auth::user();
        $business = $authUser->business;

        if (! $business) {
            // Create business if somehow missing
            $business = Business::create([
                'owner_id' => Auth::id(),
                'name'     => $request->input('name', $authUser->name . '\'s Business'),
            ]);
            User::where('id', Auth::id())->update(['business_id' => $business->id]);
        }

        $business->update($request->only([
            'name', 'industry', 'website', 'phone', 'address', 'timezone', 'brand_voice',
        ]));

        return redirect()->route('dashboard')
            ->with('success', 'Business setup complete!');
    }
}
