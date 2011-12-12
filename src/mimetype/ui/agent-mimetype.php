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
 * \brief mimetype agent ui
 * \class agent_mimetype
 */

define("TITLE_agent_mimetype", _("MIME-type Analysis (Determine mimetype of every file.  Not needed for licenses or buckets)"));

class agent_mimetype extends FO_Plugin {
  public $Name = "agent_mimetype";
  public $Title = TITLE_agent_mimetype;
  // public $MenuList   = "Jobs::Agents::MIME-type Analysis";
  public $Version = "1.0";
  public $Dependency = array();
  public $DBaccess = PLUGIN_DB_ANALYZE;
  /**
   * \brief Register additional menus.
   */
  function RegisterMenus() {
    if ($this->State != PLUGIN_STATE_READY) {
      return (0);
    } // don't run
    menu_insert("Agents::" . $this->Title, 0, $this->Name);
  }

  /**
   * \brief check if the job is already in the
   *  queue.  Returns:
   * 0 = not scheduled
   * 1 = scheduled but not completed
   * 2 = scheduled and completed
   * 
   * \param $uploadpk - upload id
   */
  function AgentCheck($uploadpk) {
    global $PG_CONN;
    $sql = "SELECT jq_pk,jq_starttime,jq_endtime FROM jobqueue INNER JOIN job ON job_upload_fk = '$uploadpk' AND job_pk = jq_job_fk AND jq_type = 'mimetype';";
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
   * \brief given an uploadpk, add a job.
   **
   * \param $uploadpk - upload id
   * \param $Depends - for specifying other dependencies.
   *  $Depends can be a jq_pk, or an array of jq_pks, or NULL.
   * \param $Priority - Priority number
   * 
   * \return NULL on success, string on failure.
   */
  function AgentAdd($uploadpk, $Depends = NULL, $Priority = 0) {
    global $PG_CONN;
    /* Get dependency: "mimetype" require "adj2nest". */
    $sql = "SELECT jq_pk FROM jobqueue
            INNER JOIN job ON job.job_upload_fk = '$uploadpk'
            AND job.job_pk = jobqueue.jq_job_fk
            WHERE jobqueue.jq_type = 'adj2nest';";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    $Dep = $row['jq_pk'];
    if (empty($Dep)) {
      global $Plugins;
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
    $Dep = array(
      $Dep
    );
    if (is_array($Depends)) {
      $Dep = array_merge($Dep, $Depends);
    }
    else if (!empty($Depends)) {
      $Dep[1] = $Depends;
    }
    /* Prepare the job: job "Default Meta Agents" */
    $jobpk = JobAddJob($uploadpk, "Default Meta Agents", $Priority);
    if (empty($jobpk) || ($jobpk < 0)) {
$text = _("Failed to insert job record");
      return ($text);
    }
    /* Add job: job "Default Meta Agents" has jobqueue item "mimetype" */
    $jqargs = $uploadpk;
    $jobqueuepk = JobQueueAdd($jobpk, "mimetype", $jqargs, "yes", NULL, $Dep);
    if (empty($jobqueuepk)) {
$text = _("Failed to insert mimetype into job queue");
      return ($text);
    }
    return (NULL);
  } // AgentAdd()

  /**
   * \brief generate the text for this plugin.
   */
  function Output() {
    if ($this->State != PLUGIN_STATE_READY) {
      return;
    }
    global $PG_CONN;
    $V = "";
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
$text = _("Analysis added to job queue");
            $V.= displayMessage($text);
          }
          else {
$text = _("Scheduling of Analysis failed: ");
            $V.= displayMessage($text.$rc);
          }
        }
        /* Get list of projects that are not scheduled for uploads */
        $sql = "SELECT upload_pk,upload_desc,upload_filename
                FROM upload
                WHERE upload_pk NOT IN
                (
                  SELECT upload_pk FROM upload
                  INNER JOIN job ON job.job_upload_fk = upload.upload_pk
                  INNER JOIN jobqueue ON jobqueue.jq_job_fk = job.job_pk
                  AND job.job_name = 'Default Meta Agents'
                  AND jobqueue.jq_type = 'mimetype'
                  ORDER BY upload_pk
                )
                ORDER BY upload_desc,upload_filename;";
        $result = pg_query($PG_CONN, $sql);
        DBCheckResult($result, $sql, __FILE__, __LINE__);
        $row = pg_fetch_assoc($result);
        if (empty($row['upload_pk'])) {
          $V.= _("All uploaded files are already analyzed, or scheduled to be analyzed.");
        }
        else {
          /* Display the form */
          $V.= _("MIME-type analysis identifies files based on their MIME type.<P />\n");
          $V.= "<form method='post'>\n"; // no url = this url
          $V.= _("Select an uploaded file for MIME-type analysis.\n");
          $V.= _("Only uploads that are not already scheduled can be scheduled.\n");
$text = _("Analyze:");
          $V.= "<p />\n$text <select name='upload'>\n";
          $Results = pg_fetch_all($result);
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
            $V.= "<option value='" . $Row['upload_pk'] . "'>$Name</option>\n";
          }
          pg_free_result($result);
          $V.= "</select><P />\n";
$text = _("Analyze");
          $V.= "<input type='submit' value='$text!'>\n";
          $V.= "</form>\n";
        }
      break;
      case "Text":
      break;
      default:
      break;
    }
    if (!$this->OutputToStdout) {
      return ($V);
    }
    print ("$V");
    return;
  }
};
$NewPlugin = new agent_mimetype;
?>
