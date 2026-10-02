<?php

test('the registration screen is not available', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('the registration form cannot be submitted', function () {
    $response = $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
});

test('the sign up link is not shown on the login page', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertDontSee('Sign up');
    $response->assertDontSee('/register', escape: false);
});
