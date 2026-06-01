<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnWafDeleteCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['site_id'])||empty($o['id'])){fwrite(STDERR,"Missing --site_id/--id\n"); return 1;} $ok=(new TrafficRulesService())->deleteWaf((string)$o['site_id'],(string)$o['id']); if(!$ok){fwrite(STDERR,"waf_not_found\n"); return 1;} CommandIO::printJson(['ok'=>true]); return 0; } }
