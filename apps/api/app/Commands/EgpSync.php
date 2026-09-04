<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\EGP\PromiseEgpAdapter;

/**
 * EgpSync Command
 *
 * Synchronizes official tenders from Sri Lanka e-GP (PROMISe) system.
 */
class EgpSync extends BaseCommand
{
    protected $group       = 'TenderHub';
    protected $name        = 'egp:sync';
    protected $description = 'Synchronize tenders from Sri Lanka e-GP PROMISe system.';

    public function run(array $params)
    {
        CLI::write('Executing Sri Lanka e-GP (PROMISe) Sync Adapter...', 'green');

        $adapter = new PromiseEgpAdapter();

        if (! $adapter->hasLiveCredentials()) {
            CLI::write('STATUS: EXTERNAL / NOT VERIFIED (PENDING LIVE NETWORK CREDENTIALS/FEEDS)', 'yellow');
            CLI::write('No live PROMISE_EGP_API_KEY detected. Ingestion engine, schema transformation, deduplication, and Event Ledger remain locally verified.', 'light_gray');
            return;
        }

        $res = $adapter->syncLive();
        if ($res['ok']) {
            CLI::write("Synced {$res['items_inserted']} notices from e-GP.", 'green');
        } else {
            CLI::error("e-GP sync failed: " . ($res['error'] ?? $res['message'] ?? 'Unknown error'));
        }
    }
}
