<?php

namespace GlpiPlugin\Ninjaone\Sync;

final class SyncResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public int $errors = 0;
    public array $messages = [];

    public function status(): string
    {
        return $this->errors > 0 ? 'warning' : 'success';
    }
}

