<?php

it('redirects guests to login from the authenticated application root', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
