<?php

namespace Tests\Feature\Betting;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Services\Bet\BetStatusTransitionPolicy;
use DomainException;
use Tests\TestCase;

class BetStatusTransitionPolicyTest extends TestCase
{
    public function test_review_transitions_allow_expected_paths(): void
    {
        $policy = new BetStatusTransitionPolicy;

        $policy->assertReviewTransitionAllowed(BetStatus::PENDING, BetStatus::ACCEPTED);
        $policy->assertReviewTransitionAllowed(BetStatus::PENDING, BetStatus::REJECTED);
        $policy->assertReviewTransitionAllowed(BetStatus::ACCEPTED, BetStatus::REFUNDED);

        $this->assertTrue(true);
    }

    public function test_review_transitions_reject_disallowed_paths_with_exact_message(): void
    {
        $policy = new BetStatusTransitionPolicy;

        try {
            $policy->assertReviewTransitionAllowed(BetStatus::REJECTED, BetStatus::ACCEPTED);
            $this->fail('Expected DomainException for disallowed review transition.');
        } catch (DomainException $exception) {
            $this->assertSame('Illegal review status transition.', $exception->getMessage());
        }

        try {
            $policy->assertReviewTransitionAllowed(BetStatus::REFUNDED, BetStatus::ACCEPTED);
            $this->fail('Expected DomainException for disallowed review transition.');
        } catch (DomainException $exception) {
            $this->assertSame('Illegal review status transition.', $exception->getMessage());
        }
    }

    public function test_review_transition_rejects_invalid_status_string_with_exact_message(): void
    {
        $policy = new BetStatusTransitionPolicy;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid review status for transition.');

        $policy->assertReviewTransitionAllowed('NOT_A_REAL_STATUS', BetStatus::PENDING);
    }

    public function test_result_transitions_allow_open_to_terminal_paths(): void
    {
        $policy = new BetStatusTransitionPolicy;

        $policy->assertResultTransitionAllowed(BetResultStatus::OPEN, BetResultStatus::WON);
        $policy->assertResultTransitionAllowed(BetResultStatus::OPEN, BetResultStatus::LOST);
        $policy->assertResultTransitionAllowed(BetResultStatus::OPEN, BetResultStatus::VOID);

        $this->assertTrue(true);
    }

    public function test_result_transitions_reject_terminal_to_other_states_with_exact_message(): void
    {
        $policy = new BetStatusTransitionPolicy;

        try {
            $policy->assertResultTransitionAllowed(BetResultStatus::WON, BetResultStatus::LOST);
            $this->fail('Expected DomainException for disallowed result transition.');
        } catch (DomainException $exception) {
            $this->assertSame('Illegal result status transition.', $exception->getMessage());
        }

        try {
            $policy->assertResultTransitionAllowed(BetResultStatus::LOST, BetResultStatus::VOID);
            $this->fail('Expected DomainException for disallowed result transition.');
        } catch (DomainException $exception) {
            $this->assertSame('Illegal result status transition.', $exception->getMessage());
        }

        try {
            $policy->assertResultTransitionAllowed(BetResultStatus::VOID, BetResultStatus::WON);
            $this->fail('Expected DomainException for disallowed result transition.');
        } catch (DomainException $exception) {
            $this->assertSame('Illegal result status transition.', $exception->getMessage());
        }
    }

    public function test_payout_transitions_allow_pending_to_paid_out_only_when_result_is_won(): void
    {
        $policy = new BetStatusTransitionPolicy;

        $policy->assertPayoutTransitionAllowed(BetPayoutStatus::PENDING, BetPayoutStatus::PAID_OUT, BetResultStatus::WON);

        $this->assertTrue(true);
    }

    public function test_payout_transitions_allow_pending_to_refunded_for_any_result(): void
    {
        $policy = new BetStatusTransitionPolicy;

        $policy->assertPayoutTransitionAllowed(
            BetPayoutStatus::PENDING,
            BetPayoutStatus::REFUNDED,
            BetResultStatus::LOST
        );

        $this->assertTrue(true);
    }

    public function test_payout_transitions_reject_paid_out_to_other_states_with_exact_message(): void
    {
        $policy = new BetStatusTransitionPolicy;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Illegal payout status transition.');

        $policy->assertPayoutTransitionAllowed(BetPayoutStatus::PAID_OUT, BetPayoutStatus::PENDING, BetResultStatus::WON);
    }

    public function test_payout_transitions_reject_pending_to_paid_out_when_result_is_lost_or_void_with_exact_message(): void
    {
        $policy = new BetStatusTransitionPolicy;

        try {
            $policy->assertPayoutTransitionAllowed(BetPayoutStatus::PENDING, BetPayoutStatus::PAID_OUT, BetResultStatus::LOST);
            $this->fail('Expected DomainException for non-WON payout transition.');
        } catch (DomainException $exception) {
            $this->assertSame('Payout status PAID_OUT requires result status WON.', $exception->getMessage());
        }

        try {
            $policy->assertPayoutTransitionAllowed(BetPayoutStatus::PENDING, BetPayoutStatus::PAID_OUT, BetResultStatus::VOID);
            $this->fail('Expected DomainException for non-WON payout transition.');
        } catch (DomainException $exception) {
            $this->assertSame('Payout status PAID_OUT requires result status WON.', $exception->getMessage());
        }
    }

    public function test_payout_transitions_reject_refunded_to_other_states_with_exact_message(): void
    {
        $policy = new BetStatusTransitionPolicy;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Illegal payout status transition.');

        $policy->assertPayoutTransitionAllowed(BetPayoutStatus::REFUNDED, BetPayoutStatus::PAID_OUT, BetResultStatus::WON);
    }
}
