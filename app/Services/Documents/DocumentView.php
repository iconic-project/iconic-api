<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\DocumentKind;

final class DocumentView
{
    public static function name(DocumentKind $kind): string
    {
        return match ($kind) {
            DocumentKind::Invoice,
            DocumentKind::FinalInvoice => 'documents.invoice',
            DocumentKind::Summary => 'documents.summary',
            DocumentKind::Receipt => 'documents.receipt',
            DocumentKind::Voucher => 'documents.voucher',
            DocumentKind::PreArrival, DocumentKind::Pretrip => 'documents.pretrip',
            DocumentKind::WireInstructions => 'documents.wire-instructions',
            DocumentKind::CharterProposal => 'documents.charter-proposal',
        };
    }
}
