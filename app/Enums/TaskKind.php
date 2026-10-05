<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskKind: string
{
    case RequestResponse = 'REQUEST_RESPONSE';
    case CharterQuote = 'CHARTER_QUOTE';
    case OverdueDecision = 'OVERDUE_DECISION';
    case CommissionCap = 'COMMISSION_CAP';
    case WireWindow = 'WIRE_WINDOW';
    case RefundDecision = 'REFUND_DECISION';
    case DealQuote = 'DEAL_QUOTE';
    case Manual = 'MANUAL';
    case SubjectRequest = 'SUBJECT_REQUEST';
    case PostTripCall = 'POST_TRIP_CALL';
    case NpsReply = 'NPS_REPLY';
    case WaitlistFollowUp = 'WAITLIST_FOLLOW_UP';
    case CharterDeposit = 'CHARTER_DEPOSIT';
    case JourneyHandover = 'JOURNEY_HANDOVER';
    case FrontDesk = 'FRONT_DESK';

    public function label(): string
    {
        return match ($this) {
            self::RequestResponse => 'Request response',
            self::CharterQuote => 'Charter quote',
            self::OverdueDecision => 'Overdue decision',
            self::CommissionCap => 'Commission cap',
            self::WireWindow => 'Wire window',
            self::RefundDecision => 'Refund decision',
            self::DealQuote => 'Quote follow-up',
            self::Manual => 'Manual',
            self::SubjectRequest => 'Subject request',
            self::PostTripCall => 'Post-trip call',
            self::NpsReply => 'NPS reply',
            self::WaitlistFollowUp => 'Waitlist follow-up',
            self::CharterDeposit => 'Charter deposit',
            self::JourneyHandover => 'Journey handover',
            self::FrontDesk => 'Front desk',
        };
    }

    public function sourceLabel(int $responseHours): string
    {
        return match ($this) {
            self::RequestResponse => 'RMS · request.submitted + '.$responseHours.' h',
            self::CharterQuote => 'RMS · charter.received + '.$responseHours.' h',
            self::OverdueDecision => 'RMS · OPS-007',
            self::CommissionCap => 'RMS · FIN-005',
            self::WireWindow => 'RMS · wire window',
            self::RefundDecision => 'RMS · refund SLA',
            self::DealQuote => 'CRM · OPS-009 SLA timer',
            self::Manual => 'Manual',
            self::SubjectRequest => 'CRM · subject request',
            self::PostTripCall => 'RMS · MKT-006',
            self::NpsReply => 'RMS · NPS reply',
            self::WaitlistFollowUp => 'RMS · waitlist offer + 2 business days',
            self::CharterDeposit => 'RMS · charter deposit due',
            self::JourneyHandover => 'CRM · journey handover',
            self::FrontDesk => 'RMS · night audit',
        };
    }
}
