#!/usr/bin/php
<?php
/***********************************************************
 Copyright (C) 2008 Hewlett-Packard Development Company, L.P.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/

/**
 * configTestEnv: configure the test environment
 *
 * @param string $url the url to test, full url with ending /
 * @param string $user the Data-Base user account (e.g. fossy)
 * @param string $password the Data-Base user's password
 *
 * @return 0 if file created, 1 otherwise.
 *
 * @version "$Id$"
 *
 * Created on Jul 31, 2008
 */

// TODO : $usage = "$argv[0] Url User Password [path-to-suite]\n";

// usage done this way as here doc's mess up eclipse colors.
$U = NULL;
$U .= "Usage: $argv[0] Url User Password [proxy]\n\nUrl is a full url with ending /\n";
$U .= "e.g. http://someHost.somedomain/repo/\n\n";
$U .= "Data-Base User and Data-Base Password\n\n";
$U .= "Optional proxy in the form http://web-proxy.xx.com:80xx\n";
$U .= "The proxy format is not checked, so make sure it's correct\n";
$U .= "Very little parameter checking is done.\n\n";
$U .= "For example,\n$argv[0] 'http://fossology.org/' dbuser dbpasswd 'http://web-proxy.somebody.com:8080'\n";
$U .= "Note the single quotes to keep the shell happy.\n";
$usage = $U;

// simple parameter checks
if((int)$argc < 4)
{
  print $usage;
  exit(1);
}

list($me, $url, $user, $password, $proxy) = $argv;
//print "Params: U:$url USR:$user PW:password PROX:$proxy\n";
if(empty($url)) { exit(1); }
if('http://' != substr($url,0,7))
{
  print "$me ERROR not a valid URL\n$url\n\n$usage";
  exit(1);
}

$FD = fopen('./TestEnvironment.php', 'w') or die("Can't open ./TestEnvironment $php_errormsg\n");
$startphp = "<?php\n";
$fullUrl = "\$URL='$url';\n";
$usr = "\$USER='$user';\n";
$passwd = "\$PASSWORD='$password';\n";
$useproxy = NULL;
$endphp = "?>\n";
$tests = getcwd();
$define ="define('TESTROOT',\"$tests\");\n";
if(!(empty($proxy)))
{
  $useproxy = "\$PROXY='$proxy';\n";
  fwrite($FD, "$startphp$fullUrl$usr$passwd$useproxy$define$endphp");
}
else
{
  fwrite($FD, "$startphp$fullUrl$usr$passwd$define$endphp");
}
fclose($FD);
print "./TestEnvironment.php created sucessfully\n";
?>
