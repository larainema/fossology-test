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

define("TITLE_admin_folder_delete", _("Delete Folder"));

class admin_folder_delete extends FO_Plugin {
  public $Name = "admin_folder_delete";
  public $Title = TITLE_admin_folder_delete;
  public $MenuList = "Organize::Folders::Delete Folder";
  public $Version = "1.0";
  public $Dependency = array();
  public $DBaccess = PLUGIN_DB_DELETE;

  /**
   * \brief Register additional menus.
   */
  function RegisterMenus() {
    if ($this->State != PLUGIN_STATE_READY) {
      return (0);
    } // don't run

  }

  /**
   * \brief Delete
   * Creates a job to detele the folder
   *
   * \param $folderpk - the folder_pk to remove
   * \return NULL on success, string on failure.
   */
  function Delete($folderpk, $Depends = NULL) {
    /* Can't remove top folder */
    if ($folderpk == FolderGetTop()) {
      $text = _("Can Not Delete Root Folder");
      return ($text);
    }
    /* Get the folder's name */
    $FolderName = FolderGetName($folderpk);
    /* Prepare the job: job "Delete" */
    $jobpk = JobAddJob(NULL, "Delete Folder: $FolderName");
    if (empty($jobpk) || ($jobpk < 0)) {
      $text = _("Failed to create job record");
      return ($text);
    }
    /* Add job: job "Delete" has jobqueue item "delagent" */
    $jqargs = "DELETE FOLDER $folderpk";
    $jobqueuepk = JobQueueAdd($jobpk, "delagent", $jqargs, "no", NULL, NULL);
    if (empty($jobqueuepk)) {
      $text = _("Failed to place delete in job queue");
      return ($text);
    }
    return (NULL);
  } // Delete()

  /**
   * \brief Generate the text for this plugin.
   */
  function Output() {
    if ($this->State != PLUGIN_STATE_READY) {
      return;
    }
    global $PG_CONN;

    $V = "";
    $R = "";

    switch ($this->OutputType) {
      case "XML":
        break;
      case "HTML":
        /* If this is a POST, then process the request. */
        $folder = GetParm('folder', PARM_INTEGER);
        if (!empty($folder)) {
          $rc = $this->Delete($folder);
          if (empty($rc)) {
            $sql = "SELECT * FROM folder where folder_pk = '$folder';";
            $result = pg_query($PG_CONN, $sql);
            DBCheckResult($result, $sql, __FILE__, __LINE__);
            $Folder = pg_fetch_assoc($result);
            pg_free_result($result);
            /* Need to refresh the screen */
            $text = _("Deletion of folder ");
            $text1 = _(" added to job queue");
            $R.= displayMessage($text . $Folder['folder_name'] . $text1);
          }
          else {
            $text = _("Deletion of ");
            $text1 = _(" failed: ");
            $R.= displayMessage($text . $Folder['folder_name'] . $text1 . $rc);
          }
        }
        $V.= "$R\n";
        $V.= "<form method='post'>\n"; // no url = this url
        $text  =  _("Select the folder to");
        $text1 = _("delete");
        $V.= "$text <em>$text1</em>.\n";
        $V.= "<ul>\n";
        $text = _("This will");
        $text1 = _("delete");
        $text2 = _("the folder, all subfolders, and all uploaded files stored within the folder!");
        $V.= "<li>$text <em>$text1</em> $text2\n";
        $text = _("Be very careful with your selection since you can delete a lot of work!");
        $V.= "<li>$text\n";
        $text = _("All analysis only associated with the deleted uploads will also be deleted.");
        $V.= "<li>$text\n";
        $text = _("THERE IS NO UNDELETE. When you select something to delete, it will be removed from the database and file repository.");
        $V.= "<li>$text\n";
        $V.= "</ul>\n";
        $text = _("Select the folder to delete:  ");
        $V.= "<P>$text\n";
        $V.= "<select name='folder'>\n";
        $text = _("select folder");
        $V.= "<option value=''>[$text]</option>\n";
        $V.= FolderListOption(-1, 0);
        $V.= "</select><P />\n";
        $text = _("Delete");
        $V.= "<input type='submit' value='$text!'>\n";
        $V.= "</form>\n";
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
$NewPlugin = new admin_folder_delete;
?>
