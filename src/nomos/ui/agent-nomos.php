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
/*************************************************
 Restrict usage: Every PHP file should have this
 at the very beginning.
 This prevents hacking attempts.
*************************************************/

/**
 * \file agent-nomos.php
 * \brief run the nomos license agent
 */

define("TITLE_agent_fonomos", _("Nomos License Analysis"));

class agent_fonomos extends FO_Plugin {

  public $Name = "agent_nomos";
  public $Title = TITLE_agent_fonomos;
  // public $MenuList   = "Jobs::Agents::Nomos License Analysis";
  public $Version = "1.0";
  public $Dependency = array();
  public $DBaccess = PLUGIN_DB_ANALYZE;

  /**
   * \brief  Register additional menus.
   */
  function RegisterMenus() {
    if ($this->State != PLUGIN_STATE_READY) {
      return (0);
    } // don't run
    menu_insert("Agents::" . $this->Title, 0, $this->Name);
  }

  /**
   * \brief Check if the job is already in the queue.
   *
   * \param $uploadpk - the upload will be checked
   * \return 
   * 0 = not scheduled \n
   * 1 = scheduled but not completed \n 
   * 2 = scheduled and completed
   */
  function AgentCheck($uploadpk) {
    global $PG_CONN;
    $sql = "SELECT jq_pk,jq_starttime,jq_endtime FROM jobqueue INNER JOIN job" .
            " ON job_upload_fk = '$uploadpk'" .
            " AND job_pk = jq_job_fk AND jq_type = 'nomos';";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    if (empty($row['jq_pk'])) {
      return (0);
    }
    if (empty($row['jq_endtime'])) {
      return (1);
    }
    return (2);
  } // AgentCheck()

  /**
   * \brief Given an uploadpk, add a job.
   * \param $Depends - is for specifying other dependencies.
   * $Depends can be a jq_pk, or an array of jq_pks, or NULL.
   * \return NULL on success, string on failure.
   */
  function AgentAdd($uploadpk, $Depends = NULL, $Priority = 0) {

    global $PG_CONN;
    global $SVN_REV;

    /* Get dependency: "nomos" require "adj2nest".
     * clean this comment up, what is being checked?
    * */
    $sql = "SELECT jq_pk FROM jobqueue INNER JOIN job ON
      job.job_upload_fk = $uploadpk AND job.job_pk = jobqueue.jq_job_fk
      WHERE jobqueue.jq_type = 'adj2nest';";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    $Dep = $row['jq_pk'];
    if (empty($Dep)) {

      global $Plugins;

      /* schedule unpack agent, it will also schedule adj2nest */
      $Unpack = & $Plugins[plugin_find_id("agent_unpack") ];
      $rc = $Unpack->AgentAdd($uploadpk);
      if (!empty($rc)) {
        return ($rc);
      }
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      $row = pg_fetch_assoc($result);
      pg_free_result($result);
      $Dep = $row['jq_pk'];
      if (empty($Dep)) {
        $text = _("Unable to find dependent job: unpack");
        return ($text);
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
    $jobpk = JobAddJob($uploadpk, "Nomos License Analysis", $Priority);
    if (empty($jobpk) || ($jobpk < 0)) {
      $text = _("Failed to insert job record for nomos");
      return ($text);
    }

    /*
     Using the latest agent revision, find all the records still needing processing
    this requires knowing the agents fk.
    */
    $agent_pk = GetAgentKey("nomos", "nomos license agent");

    /* Add job: job "Fo-Nomos License Analysis" has jobqueue item "nomos" */
    $jqargs = $uploadpk;
    $jobqueuepk = JobQueueAdd($jobpk, "nomos", $jqargs, "no", "", $Dep);
    if (empty($jobqueuepk)) {
      $text = _("Failed to insert agent nomos into job queue");
      return ($text);
    }
    return (NULL);
  } // AgentAdd()

  /**
   * \brief Generate the text for this plugin.
   */
  function Output() {
    if ($this->State != PLUGIN_STATE_READY) {
      return;
    }
    global $PG_CONN;

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
            $text = _("fo_nomos analysis added to the job queue");
            $Page.= displayMessage($text);
          }
          else {
            $text = _("Scheduling of fo_nomos failed:");
            $Page.= displayMessage($text.$rc);
          }
        }
        /* Get list of projects that are not scheduled for uploads */
        $sql = "SELECT upload_pk,upload_desc,upload_filename
                FROM upload
                 WHERE upload_pk NOT IN
                (SELECT upload_pk FROM upload
                INNER JOIN job ON job.job_upload_fk = upload.upload_pk
                INNER JOIN jobqueue ON jobqueue.jq_job_fk = job.job_pk
                AND job.job_name = 'license'
                AND jobqueue.jq_type = 'filter_clean'
                ORDER BY upload_pk)
                ORDER BY upload_desc,upload_filename;";
        $result = pg_query($PG_CONN, $sql);
        DBCheckResult($result, $sql, __FILE__, __LINE__);
        $row = pg_fetch_assoc($result, 0);
        if (empty($row['upload_pk'])) {
          $Page.= _("All uploaded files are already analyzed, or scheduled to be analyzed.");
        }
        else {
          /* Display the form */
          $Page.= "<form method='post'>\n"; // no url = this url
          $Page.= _("Select an uploaded file for license analysis.\n");
          $Page.= _("Only uploads that are not already scheduled can be scheduled.\n");
          $text = _("Analyze:");
          $Page.= "<p />\n$text <select name='upload'>\n";
          while ($Row = pg_fetch_assoc($result)) {
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
          pg_free_result($result);
          $Page.= "</select><P />\n";
          $text = _("Analyze");
          $Page.= "<input type='submit' value='$text!'>\n";
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
$NewPlugin = new agent_fonomos;
?>
