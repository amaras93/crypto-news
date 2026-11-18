<?php

namespace App;

enum CandidateStatus: string
{
    case New = 'new';
    case ReviewRequired = 'review_required';
    case ApprovedForDraft = 'approved_for_draft';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case ProcessingFailed = 'processing_failed';
}
