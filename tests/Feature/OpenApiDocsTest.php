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
            ->assertSee('/two-d-results:')
            ->assertSee('/two-d-results/latest:')
            ->assertSee('/three-d-results:')
            ->assertSee('/three-d-results/latest:')
            ->assertSee('/app-settings/maintenance:')
            ->assertSee('/admin/analytics/kpis:')
            ->assertSee('/admin/analytics/trends/daily:')
            ->assertSee('/admin/analytics/status-distribution:')
            ->assertSee('/admin/analytics/payouts:')
            ->assertSee('/admin/analytics/top-numbers:')
            ->assertSee('/admin/analytics/settlement-runs:')
            ->assertSee('/admin/health/thaistock2d-live:')
            ->assertSee('/admin/app-settings/maintenance:')
            ->assertSee('/admin/three-d-results:')
            ->assertSee('/admin/three-d-results/{threeDResult}:')
            ->assertSee('/admin/bets/{bet}/status:')
            ->assertSee('/admin/bets/{bet}/refund:')
            ->assertSee('/admin/users:')
            ->assertSee('/admin/users/{user}:')
            ->assertSee('/admin/users/{user}/role:')
            ->assertSee('/admin/users/{user}/ban:')
            ->assertSee('/admin/users/{user}/unban:')
            ->assertSee('BetAdminStatusUpdateRequest:')
            ->assertSee('TwoDResultListResponse:')
            ->assertSee('TwoDResultItemResponse:')
            ->assertSee('TwoDResult:')
            ->assertSee('ThreeDResultListResponse:')
            ->assertSee('ThreeDResultItemResponse:')
            ->assertSee('ThreeDResult:')
            ->assertSee('AdminThaiStockLiveHealthResponse:')
            ->assertSee('AppMaintenanceSettingResponse:')
            ->assertSee('AdminUserListResponse:')
            ->assertSee('AdminUserDetailResponse:')
            ->assertSee('BetUserWithWallet:')
            ->assertSee('enum: [ACCEPTED, REJECTED, REFUNDED]')
            ->assertSee('bet_result_status:')
            ->assertSee('enum: [OPEN, WON, LOST, INVALID]')
            ->assertSee('payout_status:')
            ->assertSee('enum: [PENDING, PAID_OUT, REFUNDED]')
            ->assertSee('Client-writable fields only. Review/result/payout statuses are server-managed and rejected if supplied.');
    }
}
