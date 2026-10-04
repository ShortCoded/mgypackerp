<?php

use App\Models\User;

function closureSyntheticUser(): User
{
    $number = max(1000, (int) User::withTrashed()->max('doc_number') + 1);

    return User::factory()->create([
        'doc_number' => $number, 'doc_num' => 'SYNTHETIC-CLOSURE-USER-'.$number,
        'username' => 'synthetic-closure-'.$number, 'email' => 'synthetic-closure-'.$number.'@example.test',
        'name' => 'Synthetic closure operator '.$number,
    ]);
}
