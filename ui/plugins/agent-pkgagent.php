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

class agent_pkgagent extends FO_Plugin
{
  public $Name       = "agent_pkgagent";
  public $Title      = "Schedule Package Analysis";
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
    /* Get dependency: "pkgagent" require "mimetype" and "nomos". */
    $SQL = "SELECT jq_pk FROM jobqueue
	    INNER JOIN job ON job.job_upload_fk = '$uploadpk'
	    AND job.job_pk = jobqueue.jq_job_fk
	    WHERE jobqueue.jq_type = 'mimetype';";
    $Results = $DB->Action($SQL);
    $Dep = $Results[0]['jq_pk'];
    if (empty($Dep))
	{
	global $Plugins;
	$Unpack = &$Plugins[plugin_find_id("agent_mimetype")];
	$rc = $Unpack->AgentAdd($uploadpk);
	if (!empty($rc)) { return($rc); }
	$Results = $DB->Action($SQL);
	$Dep = $Results[0]['jq_pk'];
	if (empty($Dep)) { return("Unable to find dependent job: mimetype"); }
	}
    $Dep = array($Dep);
    if (is_array($Depends)) { $Dep = array_merge($Dep,$Depends); }
    else if (!empty($Depends)) { $Dep[1] = $Depends; }

    /* Prepare the job: job "Package Agents" */
    $jobpk = JobAddJob($uploadpk,"Package Agents",$Priority=0);
    if (empty($jobpk) || ($jobpk < 0)) { return("Failed to insert job record"); }

    /* "pkgagent" needs to know what? */
    
    /* "pkgagent" needs to know the mimetype for 'application/x-rpm' and 'application/x-debian-package' and 'application/x-debian-source'*/
    $SQL = "SELECT mimetype_pk FROM mimetype WHERE mimetype_name = 'application/x-rpm' LIMIT 1;";
    $Results = $DB->Action($SQL);
    $mimetypepk = $Results[0]['mimetype_pk'];
    if (empty($mimetypepk))
      {
      $SQL = "INSERT INTO mimetype (mimetype_name) VALUES ('application/x-rpm');";
      $Results = $DB->Action($SQL);
      $SQL = "SELECT mimetype_pk FROM mimetype WHERE mimetype_name = 'application/x-rpm' LIMIT 1;";
      $Results = $DB->Action($SQL);
      $mimetypepk = $Results[0]['mimetype_pk'];
      }
    if (empty($mimetypepk)) { return("pkgagent rpm mimetype not installed."); }

    $SQL = "SELECT mimetype_pk FROM mimetype WHERE mimetype_name = 'application/x-debian-package' LIMIT 1;";
    $Results = $DB->Action($SQL);
    $debmimetypepk = $Results[0]['mimetype_pk'];
    if (empty($debmimetypepk))
      {
      $SQL = "INSERT INTO mimetype (mimetype_name) VALUES ('application/x-debian-package');";
      $Results = $DB->Action($SQL);
      $SQL = "SELECT mimetype_pk FROM mimetype WHERE mimetype_name = 'application/x-debian-package' LIMIT 1;";
      $Results = $DB->Action($SQL);
      $debmimetypepk = $Results[0]['mimetype_pk'];
      }
    if (empty($debmimetypepk)) { return("pkgagent deb mimetype not installed."); }

    $SQL = "SELECT mimetype_pk FROM mimetype WHERE mimetype_name = 'application/x-debian-source' LIMIT 1;";
    $Results = $DB->Action($SQL);
    $debsrcmimetypepk = $Results[0]['mimetype_pk'];
    if (empty($debsrcmimetypepk))
      {
      $SQL = "INSERT INTO mimetype (mimetype_name) VALUES ('application/x-debian-source');";
      $Results = $DB->Action($SQL);
      $SQL = "SELECT mimetype_pk FROM mimetype WHERE mimetype_name = 'application/x-debian-source' LIMIT 1;";
      $Results = $DB->Action($SQL);
      $debsrcmimetypepk = $Results[0]['mimetype_pk'];
      }
    if (empty($debsrcmimetypepk)) { return("pkgagent deb source mimetype not installed."); }

    /** jqargs wants EVERY RPM and DEBIAN pfile in this upload **/
    $jqargs = "SELECT pfile_pk as pfile_pk, pfile_sha1 || '.' || pfile_md5 || '.' || pfile_size AS pfilename, mimetype_name AS mimetype
	FROM uploadtree
	INNER JOIN pfile ON upload_fk = '$uploadpk'
	INNER JOIN mimetype ON (mimetype_pk = '$mimetypepk' OR mimetype_pk = '$debmimetypepk' OR mimetype_pk = '$debsrcmimetypepk')
	AND uploadtree.pfile_fk = pfile_pk
	AND pfile.pfile_mimetypefk = mimetype.mimetype_pk
	AND pfile_pk NOT IN (SELECT pkg_rpm.pfile_fk FROM pkg_rpm)
        AND pfile_pk NOT IN (SELECT pkg_deb.pfile_fk FROM pkg_deb)
	LIMIT 5000;";

    /* Add job: job "Package Agents" has jobqueue item "pkgagent" */
    $jobqueuepk = JobQueueAdd($jobpk,"pkgagent",$jqargs,"yes","pfile_pk",$Dep);
    if (empty($jobqueuepk)) { return("Failed to insert pkgagent into job queue"); }
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
	    $V .= displayMessage('Analysis added to job queue');
	    }
	  else
	    {
	    $V .= displayMessage("Scheduling of Analysis failed: $rc");
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
		    AND job.job_name = 'Package Agents'
		    AND jobqueue.jq_type = 'pkgagent'
		    ORDER BY upload_pk
		)
		ORDER BY upload_desc,upload_filename;";
	$Results = $DB->Action($SQL);
	if (empty($Results[0]['upload_pk']))
	  {
	  $V .= "All uploaded files are already analyzed, or scheduled to be analyzed.";
	  }
	else
	  {
	  /* Display the form */
	  $V .= "Package analysis extract meta data from RPM files.<P />\n";
	  $V .= "<form method='post'>\n"; // no url = this url
	  $V .= "Select an uploaded file for analysis.\n";
	  $V .= "Only uploads that are not already scheduled can be scheduled.\n";
	  $V .= "<p />\nAnalyze: <select name='upload'>\n";
	  foreach($Results as $Row)
	    {
	    if (empty($Row['upload_pk'])) { continue; }
	    if (empty($Row['upload_desc'])) { $Name = $Row['upload_filename']; }
	    else { $Name = $Row['upload_desc'] . " (" . $Row['upload_filename'] . ")"; }
	    $V .= "<option value='" . $Row['upload_pk'] . "'>$Name</option>\n";
	    }
	  $V .= "</select><P />\n";
	  $V .= "<input type='submit' value='Analyze!'>\n";
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
