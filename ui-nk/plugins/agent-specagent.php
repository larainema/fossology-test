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

class agent_specagent extends Plugin
{
  public $Name       = "agent_specagent";
  public $Title      = "Schedule Spec File Analysis";
  public $MenuList   = "Tools::Agents::Spec File Analysis";
  public $Version    = "1.0";
  public $Dependency = array("db");
  public $DBaccess   = PLUGIN_DB_ANALYZE;

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
    $SQL = "SELECT jq_pk,jq_starttime,jq_endtime FROM jobqueue INNER JOIN job ON job_upload_fk = '$uploadpk' AND job_pk = jq_job_fk AND jq_type = 'specagent';";
    $Results = $DB->Action($SQL);
    if (empty($Results[0]['jq_pk'])) { return(0); }
    if (empty($Results[0]['jq_endtime'])) { return(1); }
    return(2);
  } // AgentCheck()

  /*********************************************
   AgentAdd(): Given an uploadpk, add a job.
   Returns NULL on success, string on failure.
   *********************************************/
  function AgentAdd ($uploadpk,$Depends=NULL)
  {
    global $DB;
    /* Get dependency: "specagent" require "mimetype". */
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

    /* Prepare the job: job "Default Meta Agents" */
    $jobpk = JobAddJob($uploadpk,"Default Meta Agents");
    if (empty($jobpk)) { return("Failed to insert job record"); }

    /* "specagent" needs to know the attribkey for 'Processed' */
    $SQL = "SELECT key_pk FROM key
	WHERE key_name='Processed'
	AND key_parent_fk IN
	(SELECT key_pk FROM key
	INNER JOIN agent ON agent.agent_name = 'specagent'
	AND agent.agent_pk = key.key_agent_fk
	AND key_parent_fk=0);";
    $Results = $DB->Action($SQL);
    $attribkey = $Results[0]['key_pk'];
    if (empty($attribkey)) { return("Specagent not installed."); }

    /* "specagent" needs to know the mimetype for 'application/x-rpm-spec' */
    $SQL = "SELECT mimetype_pk FROM mimetype WHERE mimetype_name = 'application/x-rpm-spec' LIMIT 1;";
    $Results = $DB->Action($SQL);
    $mimetypepk = $Results[0]['mimetype_pk'];
    if (empty($mimetypepk)) { return("Specagent not installed."); }

    /** jqargs wants EVERY pfile in this upload that does not already
        have a specagent attribute. **/
    $jqargs = "SELECT DISTINCT(pfile_pk) as Akey,
	pfile_sha1 || '.' || pfile_md5 || '.' || pfile_size AS A
	FROM mimetype
	INNER JOIN pfile ON pfile.pfile_mimetypefk = mimetype.mimetype_pk
	  AND mimetype.mimetype_pk = '$mimetypepk'
	INNER JOIN ufile ON ufile.pfile_fk = pfile.pfile_pk
	INNER JOIN uploadtree ON uploadtree.ufile_fk=ufile.ufile_pk
	  AND upload_fk = '$uploadpk'
	WHERE pfile.pfile_pk NOT IN
	  (SELECT attrib.pfile_fk FROM attrib
	  WHERE attrib_key_fk = '$attribkey')
	LIMIT 5000;";

    /* Add job: job "Default Meta Agents" has jobqueue item "specagent" */
    $jobqueuepk = JobQueueAdd($jobpk,"specagent",$jqargs,"yes","a",array($Dep));
    if (empty($jobqueuepk)) { return("Failed to insert specagent into job queue"); }
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
	$V .= "<H1>$this->Title</H1>\n";
	/* If this is a POST, then process the request. */
	$uploadpk = GetParm('upload',PARM_INTEGER);
	if (!empty($uploadpk))
	  {
	  $rc = $this->AgentAdd($uploadpk);
	  if (empty($rc))
	    {
	    /* Need to refresh the screen */
	    $V .= "<script language='javascript'>\n";
	    $V .= "alert('Analysis added to job queue')\n";
	    $V .= "</script>\n";
	    }
	  else
	    {
	    $V .= "<script language='javascript'>\n";
	    $V .= "alert('Scheduling failed: $rc')\n";
	    $V .= "</script>\n";
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
		    AND job.job_name = 'Default Meta Agents'
		    AND jobqueue.jq_type = 'specagent'
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
	  $V .= "Spec file analysis extracts meta data from RPM '.spec' files.<P />\n";
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
$NewPlugin = new agent_specagent;
$NewPlugin->Initialize();
?>
