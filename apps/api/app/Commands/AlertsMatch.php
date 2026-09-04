<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Notifications\AlertMatcherService;

/**
 * AlertsMatch Command
 *
 * Scans newly published notices and evaluates active subscriber alert profiles,
 * dispatching in-app notifications and logging external delivery states.
 */
class AlertsMatch extends BaseCommand
{
    protected $group       = 'TenderHub';
    protected $name        = 'alerts:match';
    protected $description = 'Match recent notices against active alert profiles and dispatch notifications.';

    public function run(array $params)
    {
        CLI::write('Running TenderHub Alert Matcher...', 'green');

        $sinceHours = isset($params[0]) ? (int) $params[0] : 24;
        $since = date('Y-m-d H:i:s', strtotime("-{$sinceHours} hours"));

        $service = new AlertMatcherService();
        $result = $service->dispatchBatch($since);

        CLI::write("Scanned {$result['notices_scanned']} notices published since {$result['since']}.", 'yellow');
        CLI::write("Created {$result['alerts_created']} notification alert(s).", 'green');
    }
}
