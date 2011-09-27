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

define("TITLE_agent_unpack", _("Schedule an Unpack"));

class agent_unpack extends FO_Plugin
{
  public $Name       = "agent_unpack";
  public $Title      = TITLE_agent_unpack;
  // public $MenuList   = "Jobs::Agents::Unpack";
  public $Version    = "1.0";
  public $Dependency = array("db");
  public $DBaccess   = PLUGIN_DB_UPLOAD;

  /***********************************************************
   RegisterMenus(): Register additional menus.
   ***********************************************************/
  function RegisterMenus()
    {
    if ($this->State != PLUGIN_STATE_READY) { return(0); } // don't run
    menu_insert("Agents::" . $this->Title,0,$this->Name);
    }

  /*********************************************
   AgentCheck(): Check if the job is already in the
   queue.  Returns:
     0 = not scheduled
     1 = scheduled but not completed
     2 = scheduled and completed
   *********************************************/
  function AgentCheck($uploadpk)
  {
    global $DB;
    $SQL = "SELECT jq_pk,jq_starttime,jq_endtime FROM jobqueue INNER JOIN job ON job_upload_fk = '$uploadpk' AND job_pk = jq_job_fk AND jq_type = 'ununpack';";
    $Results = $DB->Action($SQL);
    if (empty($Results[0]['jq_pk'])) { return(0); }
    if (empty($Results[0]['jq_endtime'])) { return(1); }
    return(2);
  } // AgentCheck()

  /*********************************************
   AgentAdd(): Given an uploadpk, add a job.
   $Depends is for specifying other dependencies.
   $Depends can be a jq_pk, or an array of jq_pks, or NULL.
   Returns NULL on success, string on failure.
   *********************************************/
  function AgentAdd ($uploadpk,$Depends=NULL,$Priority=0)
  {
    /* Prepare the job: job "unpack" */
    $jobpk = JobAddJob($uploadpk,"unpack",$Priority);
    if (empty($jobpk) || ($jobpk < 0)) { 
$text = _("Failed to insert job record");
	return($text); }
    if (!empty($Depends) && !is_array($Depends)) { $Depends = array($Depends); }

    /* job "unpack" has jobqueue item "unpack" 
    $jqargs = "SELECT pfile.pfile_sha1 || '.' || pfile.pfile_md5 || '.' || pfile.pfile_size AS pfile,
	    upload_pk, pfile_fk
	    FROM upload
	    INNER JOIN pfile ON upload.pfile_fk = pfile.pfile_pk
	    WHERE upload.upload_pk = '$uploadpk';";
*/
    $jqargs = $uploadpk;
    $jobqueuepk = JobQueueAdd($jobpk,"ununpack",$jqargs,"no","",$Depends);
    if (empty($jobqueuepk)) { 
$text = _("Failed to insert item into job queue");
	return($text); }

    /* job "unpack" has jobqueue item "adj2nest" */
    $jqargs = "$uploadpk";
    $jobqueuepk = JobQueueAdd($jobpk,"adj2nest",$jqargs,"no","",array($jobqueuepk));
    if (empty($jobqueuepk)) { 
$text = _("Failed to insert adj2nest into job queue");
	return($text); }

    return(NULL);
  } // AgentAdd()

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
	/* If this is a POST, then process the request. */
	$uploadpk = GetParm('upload',PARM_INTEGER);
	if (!empty($uploadpk))
	  {
	  $rc = $this->AgentAdd($uploadpk);
	  if (empty($rc))
	    {
	    /* Need to refresh the screen */
$text = _("Unpack added to job queue");
	    $V .= displayMessage($text);
	    }
	  else
	    {
$text = _("Unpack of Upload failed:");
	    $V .= displayMessage($text.$rc);
	    }
	  }

	/* Set default values */
	if (empty($GetURL)) { $GetURL='http://'; }

	/* Get list of projects that are not scheduled for uploads */
	$SQL = "SELECT upload_pk,upload_desc,upload_filename
		FROM upload
		WHERE upload_pk NOT IN
		(
		  SELECT upload_pk FROM upload
		  INNER JOIN job ON job.job_upload_fk = upload.upload_pk
		  INNER JOIN jobqueue ON jobqueue.jq_job_fk = job.job_pk
		    AND job.job_name = 'unpack'
		    AND jobqueue.jq_type = 'ununpack'
		    ORDER BY upload_pk
		)
		ORDER BY upload_pk DESC;";
	$Results = $DB->Action($SQL);
	if (empty($Results[0]['upload_pk']))
	  {
	  $V .= _("All uploaded files are already unpacked, or scheduled to be unpacked.");
	  }
	else
	  {
	  /* Display the form */
	  $V .= "<form method='post'>\n"; // no url = this url
	  $V .= "<ol>\n";
$text = _("Select an uploaded file to unpack.\n");
	  $V .= "<li>$text";
	  $V .= _("Only uploads that are not already unpacked (and not already scheduled) can be scheduled.\n");
$text = _("Unpack:");
	  $V .= "<p />\n$text <select name='upload'>\n";
	  foreach($Results as $Row)
	    {
	    if (empty($Row['upload_pk'])) { continue; }
	    if (empty($Row['upload_desc'])) { $Name = $Row['upload_filename']; }
	    else { $Name = $Row['upload_desc'] . " (" . $Row['upload_filename'] . ")"; }
	    $V .= "<option value='" . $Row['upload_pk'] . "'>$Name</option>\n";
	    }
	  $V .= "</select><P />\n";
$text = _("Select optional analysis");
	  $V .= "<li>$text<br />\n";
	  $V .= AgentCheckboxMake(-1,$this->Name);
	  $V .= "</ol>\n";
$text = _("Unpack");
	  $V .= "<input type='submit' value='$text!'>\n";
	  $V .= "</form>\n";
	  }
	break;
      case "Text":
	break;
      default:
	break;
    }
    if (!$this->OutputToStdout) { return($V); }
    print("$V");
    return;
  }
};
$NewPlugin = new agent_unpack;
?>
