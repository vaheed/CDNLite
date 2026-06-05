<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnRedirectListCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])){fwrite(STDERR,"Missing --domain_id\n"); return 1;} CommandIO::printJson(['data'=>(new TrafficRulesService())->listRedirects((string)$o['domain_id'])]); return 0; } }
