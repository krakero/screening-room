<?php

use App\Models\User;

test('it creates a user non-interactively via options', function () {
    $this->artisan('user:create', [
        '--name' => 'Jane Doe',
        '--email' => 'jane@example.com',
        '--password' => 'password',
    ])->assertExitCode(0);

    $user = User::where('email', 'jane@example.com')->firstOrFail();

    expect($user->name)->toBe('Jane Doe')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('it creates a user interactively via prompts', function () {
    $this->artisan('user:create')
        ->expectsQuestion('Name', 'John Doe')
        ->expectsQuestion('Email address', 'john@example.com')
        ->expectsQuestion('Password', 'password')
        ->expectsQuestion('Confirm password', 'password')
        ->assertExitCode(0);

    $user = User::where('email', 'john@example.com')->firstOrFail();

    expect($user->name)->toBe('John Doe')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('it refuses to create a user with a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('user:create', [
        '--name' => 'Someone Else',
        '--email' => 'taken@example.com',
        '--password' => 'password',
    ])->assertExitCode(1);

    expect(User::where('email', 'taken@example.com')->count())->toBe(1);
});
