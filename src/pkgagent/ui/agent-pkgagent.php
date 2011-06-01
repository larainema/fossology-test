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

define("TITLE_agent_pkgagent", _("Package Analysis (Parse package headers)"));

class agent_pkgagent extends FO_Plugin
{
  public $Name       = "agent_pkgagent";
  public $Title      = TITLE_agent_pkgagent;
  //public $MenuList   = "Jobs::Agents::Package Analysis";
  public $Version    = "1.0";
  public $Dependency = array("db");
  public $DBaccess   = PLUGIN_DB_ANALYZE;

  /***********************************************************
   RegisterMenus(): Register additional menus.
   ***********************************************************/
  function RegisterMenus()
    {
    if ($this->State != PLUGIN_STATE_READY) { return(0); } // don't run
    global $DB;
    $SQL = "SELECT agent_enabled FROM agent WHERE agent_name ='pkgagent' order by agent_ts LIMIT 1;";
    $Results = $DB->Action($SQL);
    if (isset($Results[0]) && ($Results[0]['agent_enabled']== 'f')){return(0);}
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
    $SQL = "SELECT jq_pk,jq_starttime,jq_endtime FROM jobqueue INNER JOIN job ON job_upload_fk = '$uploadpk' AND job_pk = jq_job_fk AND jq_type = 'pkgagent';";
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
    global $DB;
    /* Get dependency: "pkgagent" don't require "mimetype" and "nomos", require "unpack". */
    $SQL = "SELECT jq_pk FROM jobqueue
	    INNER JOIN job ON job.job_upload_fk = '$uploadpk'
	    AND job.job_pk = jobqueue.jq_job_fk
	    WHERE jobqueue.jq_type = 'adj2nest';";
    $Results = $DB->Action($SQL);
    $Dep = $Results[0]['jq_pk'];
    if (empty($Dep))
	{
	global $Plugins;
	$Unpack = &$Plugins[plugin_find_id("agent_unpack")];
	$rc = $Unpack->AgentAdd($uploadpk);
	if (!empty($rc)) { return($rc); }
	$Results = $DB->Action($SQL);
	$Dep = $Results[0]['jq_pk'];
	if (empty($Dep)) { 
$text = _("Unable to find dependent job: unpack");
	   return($text); }
	}
    $Dep = array($Dep);
    if (is_array($Depends)) { $Dep = array_merge($Dep,$Depends); }
    else if (!empty($Depends)) { $Dep[1] = $Depends; }

    /* Prepare the job: job "Package Agent" */
    $jobpk = JobAddJob($uploadpk,"Package Scan",$Priority=0);
    if (empty($jobpk) || ($jobpk < 0)) { 
$text = _("Failed to insert job record");
	return($text); }

    /** jqargs wants EVERY RPM and DEBIAN pfile in this upload **/
    $jqargs = $uploadpk;

    /* Add job: job "Package Scan" has jobqueue item "pkgagent" */
    $jobqueuepk = JobQueueAdd($jobpk,"pkgagent",$jqargs,"no","",$Dep);
    if (empty($jobqueuepk)) { 
$text = _("Failed to insert pkgagent into job queue");
	return($text); }
    
    if (CheckEnotification()) {
      $sched = scheduleEmailNotification($uploadpk,$_SERVER['SERVER_NAME'],
      				 NULL,NULL,array($jobqueuepk));
      if ($sched !== NULL) {
        return($sched);
      }
    }
    
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
$text = _("Analysis added to job queue");
	    $V .= displayMessage($text);
	    }
	  else
	    {
$text = _("Scheduling of Analysis failed:");
	    $V .= displayMessage($text.$rc);
	    }
	  }

	/* Get list of projects that are not scheduled for uploads */
	$SQL = "SELECT upload_pk,upload_desc,upload_filename
		FROM upload
		WHERE upload_pk NOT IN
		(
		  SELECT upload_pk FROM upload
		  INNER JOIN job ON job.job_upload_fk = upload.upload_pk
		  INNER JOIN jobqueue ON jobqueue.jq_job_fk = job.job_pk
		    AND job.job_name = 'Package Scan'
		    AND jobqueue.jq_type = 'pkgagent'
		    ORDER BY upload_pk
		)
		ORDER BY upload_desc,upload_filename;";
	$Results = $DB->Action($SQL);
	if (empty($Results[0]['upload_pk']))
	  {
	  $V .= _("All uploaded files are already analyzed, or scheduled to be analyzed.");
	  }
	else
	  {
	  /* Display the form */
	  $V .= "Package analysis extract meta data from RPM files.<P />\n";
	  $V .= "<form method='post'>\n"; // no url = this url
	  $V .= _("Select an uploaded file for analysis.\n");
	  $V .= _("Only uploads that are not already scheduled can be scheduled.\n");
$text = _("Analyze:");
	  $V .= "<p />\n$text <select name='upload'>\n";
	  foreach($Results as $Row)
	    {
	    if (empty($Row['upload_pk'])) { continue; }
	    if (empty($Row['upload_desc'])) { $Name = $Row['upload_filename']; }
	    else { $Name = $Row['upload_desc'] . " (" . $Row['upload_filename'] . ")"; }
	    $V .= "<option value='" . $Row['upload_pk'] . "'>$Name</option>\n";
	    }
	  $V .= "</select><P />\n";
$text = _("Analyze");
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
$NewPlugin = new agent_pkgagent;
?>
