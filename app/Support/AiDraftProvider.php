<?php

namespace App\Support;

interface AiDraftProvider
{
    /** @param array{run_id: int} $input
     * @return array{document: array, usage: ?array, response_id: ?string}
     */
    public function generateDraft(array $input): array;
}
