<?php
/***********************************************************
 Copyright (C) 2009 Hewlett-Packard Development Company, L.P.

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

class debian_lics extends FO_Plugin
{
  public $Name       = "debian_lics";
  public $Title      = "License Histogram For All Debian Uploads";
  public $MenuList   = "Jobs::Analyze::License Histogram for Debian Uploads";
  public $Version    = "1.0";
  public $Dependency = array("db");
  public $DBaccess   = PLUGIN_DB_READ;

  /*********************************************
   Output(): Generate the text for this plugin.
   *********************************************/
  function Output()
  {
    if ($this->State != PLUGIN_STATE_READY) { return; }
    global $DB;
    $V="";
    switch($this->OutputType)
    {
      case "XML":
	break;
      case "HTML":
	/* Get uploadtree_pk's for all debian uploads */
	$SQL = "SELECT uploadtree_pk, upload_pk, upload_filename FROM upload INNER JOIN uploadtree ON upload_fk=upload_pk AND parent IS NULL WHERE upload_filename LIKE '%debian%';";
	$Results = $DB->Action($SQL);
	if (empty($Results[0]['upload_pk']))
	  {
	  $V .= "There are no uploads with 'debian' in the description.";
	  }
	else
	  {
	  /* Loop thru results to obtain all licenses in their uploadtree recs*/
          $Lics = array();
	  foreach($Results as $Row)
	    {
	    if (empty($Row['upload_pk'])) { continue; }
	    else { LicenseGetAll($Row[uploadtree_pk], $Lics); }
	    $V .= "<option value='" . $Row['upload_pk'] . "'>$Name</option>\n";
	    }
	  $V .= "</select><P />\n";
          arsort($Lics);
          $V .= "<table border=1>\n"; foreach($Lics as $key => $value) 
	  {
	  if ($key==" Total ") 
	    { $V .= "<tr><th>$key<th>$value\n"; } 
	  else { $V .= "<tr><td>$key<td align='right'>$value\n"; } 
	  }
	  $V .= "</table>\n";
//	  print "<pre>"; print_r($Lics); print "</pre>";
	  }
        break;
      case "Text":
        break;
      default:
        break;
    }
    if (!$this->OutputToStdout) { return($V); }
    print($V);
    return;
  }
};
$NewPlugin = new debian_lics;
$NewPlugin->Initialize();
?>
