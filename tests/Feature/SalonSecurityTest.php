<?php

use App\Models\Salon;
use Illuminate\Support\Facades\DB;

/*
| Security details that aren't user-facing routes: secrets must never sit in
| the database as plaintext.
*/

it('encrypts the GHL token at rest and decrypts it transparently', function () {
    $salon = Salon::factory()->create(['ghl_token' => 'pit-super-secret']);

    $stored = DB::table('salons')->where('id', $salon->id)->value('ghl_token');

    // Raw column is ciphertext, not the plaintext token.
    expect($stored)->not->toBeNull();
    expect($stored)->not->toBe('pit-super-secret');

    // The model still returns the plaintext via the 'encrypted' cast.
    expect($salon->fresh()->ghl_token)->toBe('pit-super-secret');
});

it('hides the GHL token from array/JSON output', function () {
    $salon = Salon::factory()->create(['ghl_token' => 'pit-super-secret']);

    expect($salon->toArray())->not->toHaveKey('ghl_token');
});
