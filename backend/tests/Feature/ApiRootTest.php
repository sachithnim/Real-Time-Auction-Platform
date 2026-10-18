<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiRootTest extends TestCase
{
    public function test_root_identifies_the_api_instead_of_a_framework_welcome_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJsonPath('name', 'Real-Time Auction Platform API')
            ->assertJsonStructure(['name', 'api', 'health']);
    }

    public function test_health_check_still_answers(): void
    {
        $this->get('/up')->assertOk();
    }
}
