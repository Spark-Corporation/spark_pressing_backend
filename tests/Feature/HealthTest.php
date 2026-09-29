<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.name', 'Spark Pressing API')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.database', 'ok')
            ->assertJsonPath('errors', null)
            ->assertHeader('X-Request-Id');
    }

    public function test_root_redirects_to_docs(): void
    {
        $this->get('/')->assertRedirect('/docs');
    }

    public function test_scribe_docs_page(): void
    {
        $this->get('/docs')->assertOk();
    }
}
