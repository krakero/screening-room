<?php

namespace App\Concerns;

use Flux\Flux;
use Throwable;

trait TestsIntegrationConnection
{
    /**
     * Ping an integration client and toast the result.
     */
    protected function testConnection(object $client, string $label): void
    {
        try {
            $ok = $client->testConnection();
        } catch (Throwable) {
            $ok = false;
        }

        Flux::toast(
            variant: $ok ? 'success' : 'danger',
            text: $ok
                ? __(':label connection successful.', ['label' => $label])
                : __(':label connection failed.', ['label' => $label]),
        );
    }
}
