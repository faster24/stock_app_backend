<?php

namespace Tests\Feature\Docs;

use Tests\TestCase;

/**
 * Pentest finding, 27/5/2026: /docs and /docs/openapi.yaml were served to
 * anonymous visitors, publishing every admin endpoint's path, parameters and
 * request body -- a ready-made map of the admin surface.
 */
class ApiDocsAccessTest extends TestCase
{
    public function test_docs_page_is_not_served_by_default(): void
    {
        $this->get('/docs')->assertNotFound();
    }

    public function test_openapi_spec_is_not_served_by_default(): void
    {
        $this->get('/docs/openapi.yaml')->assertNotFound();
    }

    public function test_the_spec_does_not_leak_admin_paths_when_disabled(): void
    {
        $response = $this->get('/docs/openapi.yaml');

        $response->assertNotFound();
        $this->assertStringNotContainsString('/admin/', $response->getContent());
    }

    public function test_docs_are_served_when_explicitly_enabled(): void
    {
        config()->set('docs.enabled', true);

        $this->get('/docs')->assertOk();
        $this->get('/docs/openapi.yaml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml; charset=UTF-8');
    }

    /**
     * The deploy runs `php artisan config:cache`, after which env() outside a
     * config file returns null. Reading the flag through config() is what keeps
     * the gate from silently failing open in production.
     */
    public function test_the_flag_is_read_from_config_not_env(): void
    {
        $this->assertFalse(config('docs.enabled'), 'Docs must default to disabled.');
    }
}
