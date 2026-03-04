<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example — unauthenticated users are redirected to login.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        // Redirects to login for unauthenticated users
        $response->assertRedirect('/login');
    }
}
