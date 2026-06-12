#!/usr/bin/env sh
set -eu
php -r '$s=@fsockopen("127.0.0.1",80,$errno,$errstr,2); if(!$s){exit(1);} fwrite($s,"GET / HTTP/1.0\r\nHost: localhost\r\n\r\n"); $line=fgets($s); fclose($s); if($line===false){exit(1);} if(preg_match("#^HTTP/[0-9.]+ [23][0-9][0-9]#",$line)){exit(0);} exit(1);'
