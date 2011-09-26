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

define("TITLE_folder_create", _("Create a new Fossology folder"));

class folder_create extends FO_Plugin
{
  var $Name = "folder_create";
  var $Title = TITLE_folder_create;
  var $Version = "1.0";
  var $MenuList = "Organize::Folders::Create";
  var $Dependency = array (
    "db"
    );
  var $DBaccess = PLUGIN_DB_WRITE;

  /**
   * \brief Given a parent folder ID, a name and description,
   * create the named folder under the parent.
   *
   * Includes idiot checking since the input comes from stdin.
   *
   * \param $ParentId - parent folder id
   * \param $NewFolder - new folder name
   * \param $Desc - new folder discription
   *
   * \return 1 if created, 0 if failed
   */
  function Create($ParentId, $NewFolder, $Desc)
  {
    global $Plugins;
    global $PG_CONN;

    /* Check the name */
    $NewFolder = trim($NewFolder);
    if (empty ($NewFolder))
    {
      return (0);
    }

    /* Make sure the parent folder exists */
    $sql = "SELECT * FROM folder WHERE folder_pk = '$ParentId';";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    if ($row['folder_pk'] != $ParentId)
    {
      return (0);
    }

    // folder name exists under the parent?
    $sql = "SELECT * FROM folderlist WHERE name = '$NewFolder' AND
                parent = '$ParentId' AND foldercontents_mode = '1';";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    if (!empty ($result))
    {
      if ($row['name'] == $NewFolder)
      {
        return (4);
      }
    }

    /* Create the folder
     * Block SQL injection by protecting single quotes
     *
     * Protect the folder name with htmlentities.
     */
    $NewFolder = str_replace("'", "''", $NewFolder); // PostgreSQL quoting
    $Desc = str_replace("'", "''", $Desc); // PostgreSQL quoting
    $sql = "INSERT INTO folder (folder_name,folder_desc) VALUES ('$NewFolder','$Desc');";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    pg_free_result($result);
    $sql = "SELECT folder_pk FROM folder WHERE folder_name='$NewFolder' AND folder_desc = '$Desc';";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    $FolderPk = $row['folder_pk'];
    if (empty ($FolderPk))
    {
      return (0);
    }

    $sql = "INSERT INTO foldercontents (parent_fk,foldercontents_mode,child_id) VALUES ('$ParentId','1','$FolderPk');";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    pg_free_result($result);
    return (1);
  } // Create()

  /**
   * \brief Generate the text for this plugin.
   */
  function Output()
  {
    if ($this->State != PLUGIN_STATE_READY)
    {
      return;
    }
    $V = "";
    $R = "";
    switch ($this->OutputType)
    {
      case "XML" :
        break;
      case "HTML" :
        /* If this is a POST, then process the request. */
        $ParentId = GetParm('parentid', PARM_INTEGER);
        $NewFolder = GetParm('newname', PARM_TEXT);
        $Desc = GetParm('description', PARM_TEXT);
        if (!empty ($ParentId) && !empty ($NewFolder))
        {

          $rc = $this->Create($ParentId, $NewFolder, $Desc);

          $Uri = Traceback_uri() . "?mod=refresh&remod=" . $this->Name;
          if ($rc == 1)
          {
            /* Need to refresh the screen */
            $text = _("Folder");
            $text1 = _("Created");
            $R .= displayMessage("$text $NewFolder $text1");
          } else
          if ($rc == 4)
          {
            $text = _("Folder");
            $text1 = _("Exists");
            $R .= displayMessage("$text $NewFolder $text1");
          }
        }
        /* Display the form */
        $V .= "$R\n";
        $V .= "<form method='POST'>\n"; // no url = this url
        $V .= "<ol>\n";
        $text = _("Select the parent folder:  \n");
        $V .= "<li>$text";
        $V .= "<select name='parentid'>\n";
        $V .= FolderListOption(-1, 0);
        $V .= "</select><P />\n";
        $text = _("Enter the new folder name:  \n");
        $V .= "<li>$text";
        $V .= "<INPUT type='text' name='newname' size=40 />\n<br>";
        $text = _("Enter a meaningful description:  \n");
        $V .= "<br><li>$text";
        $V .= "<INPUT type='text' name='description' size=80 />\n";
        $V .= "</ol>\n";
        $text = _("Create");
        $V .= "<input type='submit' value='$text!'>\n";
        $V .= "</form>\n";
        break;
      case "Text" :
        break;
      default :
        break;
    }
    if (!$this->OutputToStdout)
    {
      return ($V);
    }
    print ("$V");
    return;
  }
};
$NewPlugin = new folder_create;
$NewPlugin->Initialize();
?>
