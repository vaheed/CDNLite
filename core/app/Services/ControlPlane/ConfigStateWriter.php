<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;

final class ConfigStateWriter
{
    public function __construct(private AuditWriter $audit)
    {
    }

    public function markDirty(string $reason): void
    {
        DB::table('config_state')->insertOrIgnore([
            'id' => 1,
            'version' => 0,
        ]);

        $wasDirty = (bool) DB::table('config_state')->where('id', 1)->value('dirty');

        DB::table('config_state')->upsert([[
            'id' => 1,
            'version' => 0,
            'dirty' => true,
            'dirty_at' => UnixTime::now(),
        ]], ['id'], ['dirty', 'dirty_at']);

        if (!$wasDirty) {
            $this->audit->write('config.dirty', 'config_state', '1', ['dirty' => false], ['dirty' => true], 'system', null, null, ['reason' => $reason]);
        }
    }
}
