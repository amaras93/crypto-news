<?php

namespace App;

enum AiRunStatus: string
{
    case Queued = 'queued';
    case Requesting = 'requesting';
    case Received = 'response_received';
    case Validated = 'validated';
    case Stored = 'stored';
    case QualityBlocked = 'quality_blocked';
    case Failed = 'failed';
    case Uncertain = 'uncertain_cost';
    case Cancelled = 'cancelled';
}
