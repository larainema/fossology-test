<?php
/***********************************************************
Copyright (C) 2010 Hewlett-Packard Development Company, L.P.

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
if (!isset($GlobalReady)) {
  exit;
}
class agent_copyright extends FO_Plugin {

  public $Name = "agent_copyright";
  public $Title = "Schedule Copyright/Email/URL Analysis";
  public $Version = "1.0";
  public $Dependency = array("db");
  public $DBaccess = PLUGIN_DB_ANALYZE;

  /***********************************************************
  RegisterMenus(): Register additional menus.
  ***********************************************************/
  function RegisterMenus() {
    if ($this->State != PLUGIN_STATE_READY) {
      return (0);
    } // don't run
    menu_insert("Agents::" . $this->Title, 0, $this->Name);
  }
  /*********************************************
  AgentCheck(): Check if the job is already in the
  queue.  Returns:
  0 = not scheduled
  1 = scheduled but not completed
  2 = scheduled and completed
  *********************************************/
  function AgentCheck($uploadpk) {
    global $DB;
    $SQL = "SELECT jq_pk,jq_starttime,jq_endtime FROM jobqueue INNER JOIN job" .
            " ON job_upload_fk = '$uploadpk'" .
            " AND job_pk = jq_job_fk AND jq_type = 'copyright';";
    $Results = $DB->Action($SQL);
    if (empty($Results[0]['jq_pk'])) {
      return (0);
    }
    if (empty($Results[0]['jq_endtime'])) {
      return (1);
    }
    return (2);
  } // AgentCheck()

  /*********************************************
  AgentAdd(): Given an uploadpk, add a job.
  $Depends is for specifying other dependencies.
  $Depends can be a jq_pk, or an array of jq_pks, or NULL.
  Returns NULL on success, string on failure.
  *********************************************/
  function AgentAdd($uploadpk, $Depends = NULL, $Priority = 0) {

    global $DB;
    global $SVN_REV;

    /* Get dependency: "nomos" require "adj2nest".
     * clean this comment up, what is being checked?
     * */
    $SQL = "SELECT jq_pk FROM jobqueue INNER JOIN job ON
      job.job_upload_fk = $uploadpk AND job.job_pk = jobqueue.jq_job_fk
	    WHERE jobqueue.jq_type = 'adj2nest';";
    $Results = $DB->Action($SQL);
    $Dep = $Results[0]['jq_pk'];
    if (empty($Dep)) {

      global $Plugins;

      /* schedule unpack agent, it will also schedule adj2nest */
      $Unpack = & $Plugins[plugin_find_id("agent_unpack") ];
      $rc = $Unpack->AgentAdd($uploadpk);
      if (!empty($rc)) {
        return ($rc);
      }
      $Results = $DB->Action($SQL);
      $Dep = $Results[0]['jq_pk'];
      if (empty($Dep)) {
        return ("Unable to find dependent job: unpack");
      }
    }

    $Dep = array($Dep);

    if (is_array($Depends)) {
      $Dep = array_merge($Dep, $Depends);
    }
    else if (!empty($Depends)) {
      $Dep[1] = $Depends;
    }
    /* Prepare the job: job "nomos" */
    $jobpk = JobAddJob($uploadpk, "Copyright Analysis", $Priority);
    if (empty($jobpk) || ($jobpk < 0)) {
      return ("Failed to insert job record for copyright agent.");
    }

    /*
       Using the latest agent revision, find all the records still needing processing
       this requires knowing the agents fk.
     */
    $agent_pk = GetAgentKey("copyright", "copyright agent");
    $jqargs = $uploadpk;
    /*
      Hand of the SQL  to the scheduler
     */

  /* Add job: job "Copyright Analysis" has jobqueue item "copyright" */
    $jobqueuepk = JobQueueAdd($jobpk, "copyright", $jqargs, "no", "", $Dep);
    if (empty($jobqueuepk)) {
      return ("Failed to insert agent copyright into job queue.");
    }
    
    if (CheckEnotification()) {
      $sched = scheduleEmailNotification($uploadpk,$_SERVER['SERVER_NAME'],
      				 NULL,NULL,array($jobqueuepk));
      if ($sched !== NULL) {
        return($sched);
      }
    }
    return (NULL);
  } // AgentAdd()

  /*********************************************
  Output(): Generate the text for this plugin.
  *********************************************/
  function Output() {
      if ($this->State != PLUGIN_STATE_READY) {
      return;
    }

    global $DB;

    $Page = "";
    switch ($this->OutputType) {
      case "XML":
      break;
      case "HTML":
        /* If this is a POST, then process the request. */
        $uploadpk = GetParm('upload', PARM_INTEGER);
        if (!empty($uploadpk)) {
          $rc = $this->AgentAdd($uploadpk);
          if (empty($rc)) {
            /* Need to refresh the screen */
            $Page.= displayMessage('copyright/email/url analysis added to the job queue');
          }
          else {
            $Page.= displayMessage("Scheduling of copyright/email/url analysis failed: $rc");
          }
        }
        /* Get list of projects that are not scheduled for uploads */
        $SQL = "SELECT upload_pk,upload_desc,upload_filename
                FROM upload
                 WHERE upload_pk NOT IN
                (SELECT upload_pk FROM upload
                INNER JOIN job ON job.job_upload_fk = upload.upload_pk
                INNER JOIN jobqueue ON jobqueue.jq_job_fk = job.job_pk
                AND job.job_name = 'Copyright Analysis'
                AND jobqueue.jq_type = 'copyright'
                ORDER BY upload_pk)
                ORDER BY upload_desc,upload_filename;";
        $Results = $DB->Action($SQL);
        if (empty($Results[0]['upload_pk'])) {
          $Page.= "All uploaded files are already analyzed, or scheduled to be analyzed.";
        }
        else {
          /* Display the form */
          $Page.= "<form method='post'>\n"; // no url = this url
          $Page.= "Select an uploaded file for copyright analysis.\n";
          $Page.= "Only uploads that are not already scheduled can be scheduled.\n";
          $Page.= "<p />\nAnalyze: <select name='upload'>\n";
          foreach($Results as $Row) {
            if (empty($Row['upload_pk'])) {
              continue;
            }
            if (empty($Row['upload_desc'])) {
              $Name = $Row['upload_filename'];
            }
            else {
              $Name = $Row['upload_desc'] . " (" . $Row['upload_filename'] . ")";
            }
            $Page.= "<option value='" . $Row['upload_pk'] . "'>$Name</option>\n";
          }
          $Page.= "</select><P />\n";
          $Page.= "<input type='submit' value='Analyze!'>\n";
          $Page.= "</form>\n";
        }
      break;
      case "Text":
      break;
      default:
      break;
    }
    if (!$this->OutputToStdout) {
      return ($Page);
    }
    print ("$Page");
    return;
  }
};
$NewPlugin = new agent_copyright;
?>
