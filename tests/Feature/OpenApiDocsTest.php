<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiDocsTest extends TestCase
{
    public function test_openapi_docs_ui_is_publicly_available(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('Stock App Backend API')
            ->assertSee('/docs/openapi.yaml');
    }

    public function test_openapi_yaml_is_publicly_available(): void
    {
        $this->get('/docs/openapi.yaml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml; charset=UTF-8')
            ->assertSee('openapi:')
            ->assertSee('components:');
    }

    public function test_openapi_yaml_documents_bet_review_result_and_payout_contracts(): void
    {
        $this->get('/docs/openapi.yaml')
            ->assertOk()
            ->assertSee('bet_result_status:')
            ->assertSee('enum: [OPEN, WON, LOST, VOID]')
            ->assertSee('payout_status:')
            ->assertSee('enum: [PENDING, PAID_OUT]')
            ->assertSee('Client-writable fields only. Review/result/payout statuses are server-managed and rejected if supplied.');
    }
}
