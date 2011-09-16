<?php
/***********************************************************
 Copyright (C) 2008-2011 Hewlett-Packard Development Company, L.P.

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

/*****************************************************************
 These are common functions to be used by anyone.
 *****************************************************************/

$ModsEnabledDir = "$SYSCONFDIR/$PROJECT/mods-enabled/www/ui";
if (is_dir($ModsEnabledDir))
{
  require_once("$ModsEnabledDir/template/template-plugin.php");
}

require_once("common-sysconfig.php");
require_once("common-scheduler.php");
require_once("common-menu.php");
require_once("common-plugin.php");
require_once("common-folders.php");
require_once("common-dir.php");
require_once("common-parm.php");
require_once("common-repo.php");
require_once("common-license.php");
require_once("common-license-file.php");
require_once("common-job.php");
require_once("common-agents.php");
require_once("common-active.php");
require_once("common-cache.php");
require_once("common-ui.php");
require_once("common-buckets.php");
require_once("common-pkg.php");
require_once("common-tags.php");
require_once("common-compare.php");
require_once("common-db.php");
require_once("common-auth.php");
require_once("common-perms.php");

/* Only include the command-line interface functions if it is required. */
global $UI_CLI;
if (!empty($UI_CLI) && ($UI_CLI == 1))
  {
  require_once("common-cli.php");
  }

?>
