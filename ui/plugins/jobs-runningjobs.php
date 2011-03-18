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

/*************************************************
 Restrict usage: Every PHP file should have this
 at the very beginning.
 This prevents hacking attempts.
 *************************************************/
global $GlobalReady;
if (!isset($GlobalReady)) { exit; }

define("TITLE_jobs_runningjobs", _("Show Scheduler Status"));

class jobs_runningjobs extends FO_Plugin
  {
  var $Name       = "runningjobs";
  var $Title      = TITLE_jobs_runningjobs;
  var $Version    = "1.0";
  var $MenuList   = "Admin::Scheduler::Status";
  var $Dependency = array("db");
  var $DBaccess   = PLUGIN_DB_DELETE;

  /***********************************************************
   Output(): This function returns the scheduler status.
   ***********************************************************/
  function Output()
    {
    if ($this->State != PLUGIN_STATE_READY) { return(0); }
    $V="";
    global $Plugins;
    switch($this->OutputType)
      {
      case "XML":
	break;
      case "HTML":
	/* Get the list of running jobs */
	/** Find how to sort the results **/
	/*** NOTE: While I am just copying over the value from the user
	     form, the switch() ensures that hostile input is ignored. ***/
	switch(GetParm('order',PARM_STRING))
	  {
	  case 'agent_status_date': $OrderBy='agent_status_date'; break;
	  case 'agent_status': $OrderBy='agent_status'; break;
	  case 'agent_fk': $OrderBy='agent_fk'; break;
	  case 'agent_host': $OrderBy='agent_host'; break;
	  case 'agent_param': $OrderBy='agent_param'; break;
	  default: $OrderBy='agent_status_date';
	  }
	switch(GetParm('rev',PARM_STRING))
	  {
	  case 'asc': $OrderDir='ASC'; break;
	  case 'desc': $OrderDir='DESC'; break;
	  default: $OrderDir='DESC';
	  }

	/** Do the SQL **/
	global $DB;
	$Results = $DB->Action("SELECT * FROM scheduler_status WHERE record_update > now()-interval '600' ORDER BY $OrderBy $OrderDir;");
	if (!is_array($Results) || count($Results) == 0)
    {
$text = _("The FOSSology job scheduler does not appear to be running.");
      echo "<h3>$text</h3>";
      echo _("If this is unexpected, look in the log file (typically /var/log/fossology/fossology.log) for errors. ");
      echo _(" Otherwise, you may want to restart the scheduler (typically sudo /etc/init.d/fossology start)");
      return;
    }

	/* Put the results in a table */
    $Headings = "";
	$V .= menu_to_1html(menu_find("Jobs",$MenuDepth),1);
	$V .= "<table border=1 cellpadding=0 width='100%'>\n";
	$Headings .= "  <tr>\n";
	$Uri=Traceback_uri() . '?mod=' . $this->Name;
	$Ord = 'agent_status_date';
	if (!strcmp($OrderBy,$Ord)) { $Rev = (!strcmp($OrderDir,"ASC") ? 'desc' : 'asc'); } else { $Rev='desc'; }
$text = _("Update time");
	$Headings .= "    <th><a href='$Uri&order=$Ord&rev=$Rev'>$text</a></th>\n";
	$Ord = 'agent_status';
	if (!strcmp($OrderBy,$Ord)) { $Rev = (!strcmp($OrderDir,"ASC") ? 'desc' : 'asc'); } else { $Rev='desc'; }
$text = _("Status");
	$Headings .= "    <th><a href='$Uri&order=$Ord&rev=$Rev'>$text</a></th>\n";
	$Ord = 'agent_fk';
	if (!strcmp($OrderBy,$Ord)) { $Rev = (!strcmp($OrderDir,"ASC") ? 'desc' : 'asc'); } else { $Rev='desc'; }
$text = _("Agent Name");
	$Headings .= "    <th><a href='$Uri&order=$Ord&rev=$Rev'>$text</a></th>\n";
	$Ord = 'agent_host';
	if (!strcmp($OrderBy,$Ord)) { $Rev = (!strcmp($OrderDir,"ASC") ? 'desc' : 'asc'); } else { $Rev='desc'; }
$text = _("Host");
	$Headings .= "    <th><a href='$Uri&order=$Ord&rev=$Rev'>$text</a></th>\n";
	$Ord = 'agent_param';
	if (!strcmp($OrderBy,$Ord)) { $Rev = (!strcmp($OrderDir,"ASC") ? 'desc' : 'asc'); } else { $Rev='desc'; }
$text = _("Parameters");
	$Headings .= "    <th><a href='$Uri&order=$Ord&rev=$Rev'>$text</a></th>\n";
	$Headings .= "  </tr>\n";

	$BGColor=array(
		"FAIL" => "#FF6666", // "red",
		"FREE" => "white",
		"FREEING" => "white",
		"PREPARING" => "yellow",
		"SPAWNED" => "yellow",
		"READY" => "#99FFFF", // "limegreen",
		"RUNNING" => "#99FF99", // "cornflowerblue",
		"DONE" => "white",
		"END" => "white"
		);

	$Count=0;
	foreach($Results as $Val)
	  {
	  if ($Count % 16 == 0) { $V .= $Headings; }
	  $Count++;

	  $V .= "  <tr bgcolor='" . $BGColor[$Val['agent_status']] . "'>\n";
	  $V .= "    <td>" .  preg_replace("/^(....-..-.. ..:..:..).*/","\\1",
	   $Val['record_update']) . "</td>\n";
	  $V .= "    <td align=center>" . $Val['agent_status'] . "</td>\n";
	  if ($Val['agent_number'] == -1)
	    {
	    $VV = $Val['agent_attrib'];
	    }
	  else
	    {
	    $VV = preg_replace("/.*agent=([^ ]*).*/","\\1",$Val['agent_attrib']);
	    }
	  $V .= "    <td align=center>" . htmlentities($VV) . "</td>\n";
	  $V .= "    <td align=center>" . htmlentities($Val['agent_host']) . "</td>\n";
	  if ($Val['agent_status'] != 'FREE')
		{
		$V .= "    <td>" . htmlentities($Val['agent_param']) . "</td>\n";
		}
	  else
		{
		$V .= "    <td></td>\n";
		}
	  $V .= "  </tr>\n";
	  }

	$V .= "</table>\n";
	break;
      case "Text":
	break;
      default:
	break;
      }
    if (!$this->OutputToStdout) { return($V); }
    print "$V";
    return;
    }

  };
$NewPlugin = new jobs_runningjobs;
$NewPlugin->Initialize();

?>
