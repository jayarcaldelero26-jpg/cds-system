<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'office_designated' => ['required', 'string', 'max:255'],
            'section' => ['required', 'string', 'in:CDS,MES'],
        ]);

        // Awtomatikong 'no_role' ug inactive pagka-register, lakip ang office ug section
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'office_designated' => $request->office_designated,
            'section' => $request->section,
            'is_active' => false,
        ]);

        // I-assign ang 'no_role' gamit ang Spatie roles
        $user->assignRole('no_role');

        event(new Registered($user));

        // 🚀 Gitangtang ang Auth::login($user) aron mo-pop up ang success dialog
        // ug dili mo-diretso og login samtang pending pa sa admin approval.

        return back()->with('status', 'registered-successfully');
    }
}
