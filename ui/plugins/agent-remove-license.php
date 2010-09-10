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
/**
 * @Version: "$Id$"
 *
 */

/*************************************************
 Restrict usage: Every PHP file should have this
 at the very beginning.
 This prevents hacking attempts.
 *************************************************/
global $GlobalReady;

if (!isset($GlobalReady)) { exit; }

define("TITLE_agent_remove_licenseMeta", _("Remove License Analysis"));

class agent_remove_licenseMeta extends FO_Plugin
{
  public $Name       = "agent_reset_license";
  public $Title      = TITLE_agent_remove_licenseMeta;
  public $MenuList   = "Obsolete::Remove License Analysis";
  public $Version    = "1.0";
  public $Dependency = array("db", "agent_license");
  public $DBaccess   =  PLUGIN_DB_ANALYZE;

  /**
   * @param int $upload_pk
   * @param string $restart flag to indicate the license analysis should
   *                        be rescheduled.
   * @param string $depends what depends on the jobqueuepk.
   * @Return NULL on success, string on failure.
   */
  /**
   :
   Given an upload_pk, add a job.Returns NULL on success, string on failure.
   */
  function RemoveLicenseMeta($upload_pk, $Depends=NULL, $restart=NULL)
  {
    global $Plugins;

    // find the agent-license plugin
    $agent_license_plugin = &$Plugins[plugin_find_id("agent_license")]; /* may be null */

    /* Problem: We want all jobs of time "license" to go away. */
    /* Solution: Delete them from the queue. */
    $OldJobPk = JobFindKey($upload_pk,"license");
    if ($OldJobPk >= 0)
      {
      JobChangeStatus($OldJobPk,"delete");
      }
    $OldJobPk = JobFindKey($upload_pk,"license-delete");
    if ($OldJobPk >= 0)
      {
      JobChangeStatus($OldJobPk,"delete");
      }

    /* Prepare the job: job "Delete" */
    $jobpk = JobAddJob($upload_pk,"license-delete");
    if (empty($jobpk) || ($jobpk < 0)) { 
$text = _("Failed to create job record");
	return($text); }

    /* Add job: job "Delete" has jobqueue item "delagent" */
    $jqargs = "DELETE LICENSE $upload_pk";
    $jobqueue_pk = JobQueueAdd($jobpk,"delagent",$jqargs,"no",NULL,NULL);
    if (empty($jobqueue_pk)) {
$text = _("Failed to place delete in job queue");
      return($text);
    }
    if (!empty($restart)){
      // schedule the agent using the plugin found at the start of the routine.
      $agent_added = $agent_license_plugin->AgentAdd($upload_pk, $jobqueue_pk);
      if(!empty($agent_added)){
$text = _("Could not reschedule License Analysis");
        return ($text);
      }
    }
    return(NULL);
  } // RemoveLicenseMeta()

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
        $upload_pk = GetParm('upload',PARM_INTEGER);
        $Restart = GetParm('ReDoLic',PARM_STRING);
        if (!empty($upload_pk)){
          $rc = $this->RemoveLicenseMeta($upload_pk, $depends, $Restart);
          if (empty($rc))

          {
            // Need to refresh the screen
	    if ($Restart)
	      {
$text = _("License data re-analysis added to job queue");
              $V .= displayMessage($text);
	      }
	    else
	      {
$text = _("License data removal added to job queue");
              $V .= displayMessage($text);
	      }
          }
          else
          {
            $V .= displayMessage($rc);
          }
        }


        /* Create the AJAX (Active HTTP) javascript for doing the reply
         and showing the response. */
        $V .= ActiveHTTPscript("ResetLicense");
        $V .= "<script language='javascript'>\n";
        $V .= "function ResetLicense_Reply()\n";
        $V .= "  {\n";
        $V .= "  if ((ResetLicense.readyState==4) && (ResetLicense.status==200))\n";
        $V .= "    {\n";
        /* Remove all options */
        $V .= "    document.formR.upload.innerHTML = ResetLicense.responseText;\n";
        /* Add new options */
        $V .= "    }\n";
        $V .= "  }\n";
        $V .= "</script>\n";

        /* Build HTML form */
        $V .= "<form name='formR' method='post'>\n"; // no url = this url
$text = _("Remove");
$text1 = _("the license meta data from the selected upload.\n");
        $V .= "<em>$text</em> $text1";
        $V .= "<ul>\n";
$text = _("This will");
$text1 = _("remove");
$text2 = _("the license meta data associated with the selected upload file!\n");
        $V .= "<li>$text <em>$text1</em> $text2";
$text = _("Be very careful with your selection since you can delete a lot of work!\n");
        $V .= "<li>$text";
$text = _("THERE IS NO UNREMOVE. The license meta data can be recreated by re-running the license analysis\n");
        $V .= "<li>$text";
        $V .= "</ul>\n";
$text = _("Select the uploaded file to remove license data:");
        $V .= "<P>$text<P>\n";
        $V .= "<ol>\n";
$text = _("Select the folder containing the upload file to use: ");
        $V .= "<li>$text";
        $V .= _("<select name='folder' ");
        $V .= "onLoad='ResetLicense_Get((\"" . Traceback_uri() . "?mod=upload_options&folder=-1' ";
        $V .= "onChange='ResetLicense_Get(\"" . Traceback_uri() . "?mod=upload_options&folder=\" + document.formR.folder.value)'>\n";
        $V .= FolderListOption(-1,0);
        $V .= "</select><P />\n";

$text = _("Select the uploaded project to use:");
        $V .= "<li>$text";
        $V .= "<BR><select name='upload' size='10'>\n";
        $List = FolderListUploads(-1);
        foreach($List as $L)
        {
          $V .= "<option value='" . $L['upload_pk'] . "'>";
          $V .= htmlentities($L['name']);
          if (!empty($L['upload_desc']))
          {
            $V .= " (" . htmlentities($L['upload_desc']) . ")";
          }
          if (!empty($L['upload_ts']))
          {
            $V .= " :: " . substr($L['upload_ts'],0,19);
          }
          $V .= "</option>\n";
        }
        $V .= "</select><P />\n";
$text = _("After the license data is removed you can reschedule the License Analysis by checking the box below");
        $V .= "<li>$text<br />";
        $V .= "<input type='checkbox' name='ReDoLic' value='Y' />";
$text = _("Reschedule License Analysis?");
        $V .= "$text<br /><br />\n";
        $V .= "</ol>\n";
$text = _("Commit");
        $V .= "<input type='submit' value='$text!'>\n";
        $V .= "</form>\n";
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
$NewPlugin = new agent_remove_licenseMeta;
?>
