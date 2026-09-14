<?php

use Illuminate\Support\Facades\Route;
Route::get('/email/verify/{id}/{hash}', function (\Illuminate\Http\Request $request, int $id, string $hash) {
    $user = \App\Models\User::findOrFail($id);
    abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
    if (!$user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new \Illuminate\Auth\Events\Verified($user));
    }
    return response('Email verified. Return to Court Hub and refresh your account.', 200)->header('Content-Type', 'text/plain');
})->middleware(['signed', 'throttle:10,1'])->name('verification.verify');
Route::get('/payments/return', fn () => response('Return to Court Hub and check payment status. This page does not confirm payment.', 200)->header('Content-Type', 'text/plain'));

Route::get('/', function () {
    return view('welcome');
});
