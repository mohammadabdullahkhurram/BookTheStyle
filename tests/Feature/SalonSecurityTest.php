<?php

use App\Models\Salon;
use App\Models\SalonGhlConnection;
use Illuminate\Support\Facades\DB;

/*
| Security details that aren't user-facing routes: the per-salon GoHighLevel
| Private Integration Token must never sit in the database as plaintext, nor
| leak through array/JSON serialisation.
*/

it('encrypts the GHL token at rest and decrypts it transparently', function () {
    $salon = Salon::factory()->create();
    $connection = SalonGhlConnection::factory()->for($salon)->create([
        'private_integration_token' => 'pit-super-secret',
    ]);

    $stored = DB::table('salon_ghl_connections')
        ->where('id', $connection->id)
        ->value('private_integration_token');

    // Raw column is ciphertext, not the plaintext token.
    expect($stored)->not->toBeNull();
    expect($stored)->not->toBe('pit-super-secret');

    // The model still returns the plaintext via the 'encrypted' cast.
    expect($connection->fresh()->private_integration_token)->toBe('pit-super-secret');
});

it('hides the GHL token from array/JSON output', function () {
    $connection = SalonGhlConnection::factory()->create([
        'private_integration_token' => 'pit-super-secret',
    ]);

    expect($connection->toArray())->not->toHaveKey('private_integration_token');
    expect($connection->toJson())->not->toContain('pit-super-secret');
});
