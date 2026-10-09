<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function edit(Request $request)
    {
        return view('admin.account', ['user' => $request->user()]);
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);
        $request->user()->update(['password' => $data['password']]);

        return back()->with('status', 'Password changed.');
    }

    public function telegramCode(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'telegram_link_code' => strtoupper(Str::random(8)),
            'telegram_link_expires_at' => now()->addMinutes(15),
        ])->save();

        return back();
    }

    public function telegramUnlink(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'telegram_chat_id' => null,
            'telegram_link_code' => null,
            'telegram_link_expires_at' => null,
        ])->save();

        return back()->with('status', 'Telegram unlinked.');
    }
}
