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

define("TITLE_user_edit_any", _("Edit A User"));

class user_edit_any extends FO_Plugin {
  var $Name = "user_edit_any";
  var $Title = TITLE_user_edit_any;
  var $MenuList = "Admin::Users::Edit Users";
  var $Version = "1.0";
  var $Dependency = array("db");
  var $DBaccess = PLUGIN_DB_USERADMIN;
  /*********************************************
   Edit(): Edit a user.
   Returns NULL on success, string on failure.
   *********************************************/
  function Edit() {
    global $DB;
    /* Get the parameters */
    $UserId = GetParm('userid', PARM_INTEGER);
    if (empty($UserId)) {
      $text = _("No user selected. No change.");
      return ($text);
    }
    $User = GetParm('username', PARM_TEXT);
    $Pass1 = GetParm('pass1', PARM_TEXT);
    $Pass2 = GetParm('pass2', PARM_TEXT);
    $Seed = rand() . rand();
    $Hash = sha1($Seed . $Pass1);
    $Desc = GetParm('description', PARM_TEXT);
    $Perm = GetParm('permission', PARM_INTEGER);
    $Folder = GetParm('folder', PARM_INTEGER);
    $Email = GetParm('email', PARM_TEXT);
    $Email_notify = GetParm('enote', PARM_TEXT);
    $uiChoice = GetParm('whichui', PARM_TEXT);
    $agentList = userAgents();
    $Block = GetParm("block", PARM_INTEGER);
    $Blank = GetParm("blank", PARM_INTEGER);
    $default_bucketpool_fk = GetParm("default_bucketpool_fk", PARM_INTEGER);
    if (!empty($Email_notify)) {
    }
    /* Make sure username looks valid */
    if (empty($User)) {
      $text = _("Username must be specified. No change.");
      return ($text);
    }
    /* Make sure password matches */
    if ($Pass1 != $Pass2) {
      $text = _("Passwords did not match. No change.");
      return ($text);
    }
    /* Make sure email looks valid */
    $Check = preg_replace("/[^a-zA-Z0-9@_.+-]/", "", $Email);
    if ($Check != $Email) {
      $text = _("Invalid email address.  Not edited.");
      return ($text);
    }
    
    echo "<pre>session is:{$_SESSION['UiPref']}\n</pre>";
    // Make sure the ui preference is set, default is simple ui
    /*
    if(empty($uiChoice))
    {
      
      $_SESSION['UiPref'] = 'simple';
    }
    else
    {
      echo "<pre>Seeing uipref to$uiChoice\n</pre>";
      $_SESSION['UiPref'] = $uiChoice;
    }
    */
    /* Get existing user info for updating */
    $SQL = "SELECT * FROM users WHERE user_pk = '$UserId' LIMIT 1;";
    $Results = $DB->Action($SQL);
    $R = $Results[0];
    if (empty($R['user_pk'])) {
      $text = _("User does not exist.  No change.");
      return ($text);
    }
    /* Edit the user */
    if (strcmp($User, $R['user_name'])) {
      /* See if the user already exists (better not!) */
      $Val = str_replace("'", "''", $User);
      $SQL = "SELECT * FROM users WHERE user_name = '$Val' LIMIT 1;";
      $Results = $DB->Action($SQL);
      if (!empty($Results[0]['user_name'])) {
        $text = _("User already exists.  Not edited.");
        return ($text);
      }
      $DB->Action("UPDATE users SET user_name = '$Val' WHERE user_pk = '$UserId';");
    }
    if (strcmp($Desc, $R['user_desc'])) {
      $Val = str_replace("'", "''", $Desc);
      $DB->Action("UPDATE users SET user_desc = '$Val' WHERE user_pk = '$UserId';");
    }
    if (strcmp($Email, $R['user_email'])) {
      $Val = str_replace("'", "''", $Email);
      $DB->Action("UPDATE users SET user_email = '$Val' WHERE user_pk = '$UserId';");
    }
    /* check email notification, if empty (box not checked), or if no email
     * specified for the user set to ''. (default value for field is 'y').
     */
    if ($Email_notify != $R['email_notify']) {
      if ($Email_notify == 'on') {
        $Email_notify = 'y';
      }
      $DB->Action("UPDATE users SET email_notify = '$Email_notify' WHERE user_pk = '$UserId';");
      $_SESSION['UserEnote'] = $Email_notify;
    }
    elseif (empty($Email)) {
      $DB->Action("UPDATE users SET email_notify = '' WHERE user_pk = '$UserId';");
      $_SESSION['UserEnote'] = '';
    }
    if($uiChoice != $R['ui_preference'])
    {
      $DB->Action("UPDATE users SET ui_preference='$uiChoice' WHERE user_pk = '$UserId';");
    }
    if ($Folder != $R['root_folder_fk']) {
      $DB->Action("UPDATE users SET root_folder_fk = '$Folder' WHERE user_pk = '$UserId';");
    }
    if ($Perm != $R['user_perm']) {
      $DB->Action("UPDATE users SET user_perm = '$Perm' WHERE user_pk = '$UserId';");
    }
    if ($Blank == 1) {
      $Seed = rand() . rand();
      $Hash = sha1($Seed . "");
      $DB->Action("UPDATE users SET user_seed = '$Seed', user_pass = '$Hash' WHERE user_pk = '$UserId';");
      $R['user_seed'] = $Seed;
      $R['user_pass'] = $Hash;
    }
    if (!empty($Pass1)) {
      $Seed = rand() . rand();
      $Hash = sha1($Seed . $Pass1);
      $DB->Action("UPDATE users SET user_seed = '$Seed', user_pass = '$Hash' WHERE user_pk = '$UserId';");
      $R['user_seed'] = $Seed;
      $R['user_pass'] = $Hash;
    }
    if (substr($R['user_pass'], 0, 1) == ' ') {
      $OldBlock = 1;
    }
    else {
      $OldBlock = 0;
    }
    if (empty($Block)) {
      $Block = 0;
    }
    if ($Block != $OldBlock) {
      if ($Block) {
        $DB->Action("UPDATE users SET user_pass = ' " . $R['user_pass'] . "' WHERE user_pk = '$UserId';");
      }
      else {
        $DB->Action("UPDATE users SET user_pass = '" . trim($R['user_pass']) . "' WHERE user_pk = '$UserId';");
      }
    }
    // update user_agent_list
    if (strcmp($agentList, $R['user_agent_list'])) {
      $Val = str_replace("'", "''", $agentList);
      $DB->Action("UPDATE users SET user_agent_list = '$Val' WHERE user_pk = '$UserId';");
    }
    if ($default_bucketpool_fk != $R['default_bucketpool_fk']) {
      if ($default_bucketpool_fk == 0) $default_bucketpool_fk='NULL';
      $DB->Action("UPDATE users SET default_bucketpool_fk = $default_bucketpool_fk WHERE user_pk = '$UserId'");
    }

    $Results = $DB->Action($SQL);
    return (NULL);
  } // Edit()
  /*********************************************
  Output(): Generate the text for this plugin.
  *********************************************/
  function Output() {
    if ($this->State != PLUGIN_STATE_READY) {
      return;
    }

    global $DB;
    $V = "";

    switch($this->OutputType) {
      case "XML":
        break;
      case "HTML":
        /* If this is a POST, then process the request. */
        $UserId = GetParm('userid', PARM_INTEGER);
        if (!empty($UserId)) {
          $rc = $this->Edit();
          if (empty($rc)) {
            $SQL = "SELECT user_pk, user_name FROM users WHERE user_pk=$UserId;";
            $qResults = $DB->Action($SQL);
            $userName = $qResults[0]['user_name'];
            // display status
            $V.= displayMessage("User $userName updated.");
          }
          else {
            $V.= displayMessage($rc);
          }
        }
        /* Get the list of users */
        $SQL = "SELECT user_pk,user_name,user_desc,user_pass,
                                root_folder_fk,user_perm,user_email,email_notify,
                                user_agent_list,default_bucketpool_fk,ui_preference FROM users WHERE
                                user_pk != '" . @$_SESSION['UserId'] . "' ORDER BY user_name;";
        $Results = $DB->Action($SQL);
        /* Create JavaScript for updating users */
        $V.= "\n<script language='javascript'>\n";
        $V.= "var Username = new Array();\n";
        $V.= "var Userdesc = new Array();\n";
        $V.= "var Useremail = new Array();\n";
        $V.= "var Userenote = new Array();\n";
        $V.= "var Useragents = new String(\"\");\n";
        $V.= "var Userperm = new Array();\n";
        $V.= "var Userblock = new Array();\n";
        $V.= "var Userfolder = new Array();\n";
        $V.= "var default_bucketpool_fk = new Array();\n";
        $V.= "var UiPref = new Array();\n";
        for ($i = 0;!empty($Results[$i]['user_pk']);$i++) {
          $R = & $Results[$i];
          //echo "<pre>Users are:\n";
          //print_r($R) . "\n</pre>";
          $Id = $R['user_pk'];
          $Val = str_replace('"', "\\\"", $R['user_name']);
          $V.= "Username[" . $Id . '] = "' . $Val . "\";\n";
          $Val = str_replace('"', "\\\"", $R['user_desc']);
          $V.= "Userdesc[" . $Id . '] = "' . $Val . "\";\n";
          $Val = str_replace('"', "\\\"", $R['user_email']);
          $V.= "Useremail[" . $Id . '] = "' . $Val . "\";\n";
          $V.= "Userenote[" . $Id . '] = "' . $R['email_notify'] . "\";\n";
          $V.= "UiPref[" . $Id . '] = "' . $R['ui_preference'] . "\";\n";
          $V.= "Useragents[" . $Id . '] = "' . $R['user_agent_list'] . "\";\n";
          $V.= "Userfolder[" . $Id . '] = "' . $R['root_folder_fk'] . "\";\n";
          $V.= "default_bucketpool_fk[" . $Id . '] = "' . $R['default_bucketpool_fk'] . "\";\n";
          $V.= "Userperm[" . $Id . '] = "' . $R['user_perm'] . "\";\n";
          if (substr($R['user_pass'], 0, 1) == ' ') {
            $Block = 1;
          }
          else {
            $Block = 0;
          }
          $V.= "Userblock[" . $Id . "] = '$Block';\n";
        }

        $V.= "
              function clearBoxes()
              {
                var cbList = document.getElementsByTagName('input');
                for(j=0; j<cbList.length; j++)
                {
                  if(cbList[j].getAttribute('type') == 'checkbox')
                  {
                    var aname = cbList[j].getAttribute('name');
                    if(String.search('Check_agent', aname) != -1)
                    {
                      continue;
                    }
                    else
                    {
                      cbList[j].checked=false;
                    }
                   }
                  }
                }
                                 
                function SetBoxes(id)
                {
                  if(!id) { return; }

                  var prefix='Check_';
                  var agents = Useragents[id].split(',');

                  var cbList = document.getElementsByTagName('input');
                  for(j=0; j<cbList.length; j++)
                  {
                    if(cbList[j].getAttribute('type') == 'checkbox')
                    {
                      uiName = cbList[j].getAttribute('name');
                      if(uiName.search(/Check_agent/) != -1)
                      {
                        for(i=0; i<agents.length; i++)
                        {
                          aName = prefix + agents[i];
                          // need to remove Check_ from the name
                          noCheck = uiName.replace(/Check_/, '');
                          if(agents.indexOf(noCheck) == -1)
                          {
                            cbList[j].checked=false;
                            continue;
                          }
                          else
                          {
                            cbList[j].checked=true;
                            continue;
                          }
                        }
                      }
                    }
                  }
                }
                
                function SetInfo(id)
                {
                  if(id == 0) { clearBoxes(); }
                  document.userEditAny.username.value = Username[id];
                  document.userEditAny.email.value = Useremail[id];
                  document.userEditAny.description.value = Userdesc[id];
                  document.userEditAny.permission.value = Userperm[id];
                  document.userEditAny.folder.value = Userfolder[id];
                  document.userEditAny.default_bucketpool_fk.value = default_bucketpool_fk[id];
                  if (Userblock[id] == 1) { document.userEditAny.block.checked=true; }
                  else { document.userEditAny.block.checked=false; }
                  if (Userenote[id] == \"\") { document.userEditAny.enote.checked=false; }
                  else { document.userEditAny.enote.checked=true; }
                  
                  if (UiPref[id] == \"\") {
                    document.getElementById('simple').checked=true;
                  }
                  else if (UiPref[id] == 'simple') {
                    document.getElementById('original').checked=false;
                    document.getElementById('simple').checked=true;
		              }
		              else {
		                document.getElementById('simple').checked=false;
  	                document.getElementById('original').checked=true;
		              }
                  
                  if(Useragents[id].length == 0)
                  {
                      clearBoxes();
                    }
                    else
                    {
                        SetBoxes(id);
                    }
              }
              ";

        $V.= "</script>\n";
        /* Build HTML form */
        $V.= "<form name='userEditAny' method='POST'>\n"; // no url = this url

        if (empty($UserId)) {
          $UserId = $Results[0]['user_pk'];
        }
        $Uri = Traceback_uri();
        $V.= "<P />\n";
        $text = _("To edit");
        $text1 = _("another");
        $text2 = _(" user on this system, alter any of the following information.");
        $V.= "$text <strong>$text1</strong>$text2<P />\n";

        $text = _("To edit");
        $text1 = _("your");
        $text2 = _(" account settings, use");
        $text3 = _("Account Settings.");
        $V.= "$text <strong>$text1</strong>$text2
         <a href='${Uri}?mod=user_edit_self'>$text3</a><P />\n";

        $V.= _("Select the user to edit: ");
        $V.= "<select name='userid' onClick='SetInfo(this.value);' onchange='SetInfo(this.value);'>\n";

        //$V .= "<option selected value='0'>--select user--</option>\n";
        for ($i = 0;!empty($Results[$i]['user_pk']);$i++) {
          $Selected = "";
          if ($UserId == $Results[$i]['user_pk']) {
            $Selected = "selected";
          }
          $V.= "<option $Selected value='" . $Results[$i]['user_pk'] . "'>";
          $V.= htmlentities($Results[$i]['user_name']);
          $V.= "</option>\n";
        }
        $V.= "</select>\n";
        $Style = "<tr><td colspan=3 style='background:black;'></td></tr><tr>";
        $V.= "<table style='border:1px solid black; text-align:left; background:lightyellow;' width='100%'>";
        $Val = htmlentities(GetParm('username', PARM_TEXT), ENT_QUOTES);
        $text = _("Change the username.");
        $V.= "$Style<th width='25%'>$text</th>";
        $V.= "<td><input type='text' value='$Val' name='username' size=20></td>\n";
        $V.= "</tr>\n";
        $Val = htmlentities(GetParm('description', PARM_TEXT), ENT_QUOTES);
        $text = _("Change the user's description (name, contact, or other information).  This may be blank.");
        $V.= "$Style<th>$text</th>\n";
        $V.= "<td><input type='text' name='description' value='$Val' size=60></td>\n";
        $V.= "</tr>\n";
        $Val = htmlentities(GetParm('email', PARM_TEXT), ENT_QUOTES);
        $text = _("Change the user's email address. This may be blank.");
        $V.= "$Style<th>$text</th>\n";
        $V.= "<td><input type='text' name='email' value='$Val' size=60></td>\n";
        $V.= "</tr>\n";
        $text = _("Select the user's access level.");
        $V.= "$Style<th>$text</th>";
        $V.= "<td><select name='permission'>\n";
        $text1 = _("None (very basic, no database access)");
        $text2 = _("Read-only (read, but no writes or downloads)");
        $text3 = _("Download (Read-only, but can download files)");
        $text4 = _("Read-Write (read, download, or edit information)");
        $text5 = _("Upload (read-write, and permits uploading files)");
        $text6 = _("Analyze (... and permits scheduling analysis tasks)");
        $text7 = _("Delete (... and permits deleting uploaded files and analysis)");
        $text8 = _("Debug (... and allows access to debugging functions)");
        $text9 = _("Full Administrator (all access including adding and deleting users)");

        $V.= "<option value='" . PLUGIN_DB_NONE . "'>$text1</option>\n";
        $V.= "<option selected value='" . PLUGIN_DB_READ . "'>$text2</option>\n";
        $V.= "<option value='" . PLUGIN_DB_DOWNLOAD . "'>$text3</option>\n";
        $V.= "<option value='" . PLUGIN_DB_WRITE . "'>$text4</option>\n";
        $V.= "<option value='" . PLUGIN_DB_UPLOAD . "'>$text5</option>\n";
        $V.= "<option value='" . PLUGIN_DB_ANALYZE . "'>$text6</option>\n";
        $V.= "<option value='" . PLUGIN_DB_DELETE . "'>$text7</option>\n";
        $V.= "<option value='" . PLUGIN_DB_DEBUG . "'>$text8</option>\n";
        $V.= "<option value='" . PLUGIN_DB_USERADMIN . "'>$text9</option>\n";
        $V.= "</select></td>\n";
        $V.= "</tr>\n";
        $text = _("Select the user's top-level folder. Access is restricted to this folder.");
        $V.= "$Style<th>$text";
        $V.= _(" (NOTE: This is only partially implemented right now. Current users can escape the top of tree limitation.)");
        $V.= "</th>";
        $V.= "<td><select name='folder'>";
        $V.= FolderListOption(-1, 0);
        $V.= "</select></td>\n";
        $V.= "</tr>\n";
        $text = _("Block the user's account. This will prevent logins.");
        $V.= "$Style<th>$text</th><td><input type='checkbox' name='block' value='1'></td>\n";
        $text = _("Blank the user's account. This will will set the password to a blank password.");
        $V.= "$Style<th>$text</th><td><input type='checkbox' name='blank' value='1'></td>\n";
        $text = _("Change the user's password.");
        $V.= "$Style<th>$text</th><td><input type='password' name='pass1' size=20></td>\n";
        $V.= "</tr>\n";
        $text = _("Re-enter the user's password.");
        $V.= "<tr><th>$text</th><td><input type='password' name='pass2' size=20></td>\n";
        $V.= "</tr>\n";
        $text = _("E-mail Notification");
        $V.= "$Style<th>$text</th><td><input type=checkbox name='enote'";
        $V.= "</tr>\n";
        $V.= "</tr>\n";
        $text = _("Default Agents: Select the agent(s) to automatically run when uploading data. These selections can be changed on the upload screens.");
        $V .= "$Style<th>$text\n</th><td> ";
        $V.= AgentCheckBoxMake(-1, "agent_unpack");
        $V .= "</td>\n";
        $V .= "</tr>\n";
        $Val = GetParm('default_bucketpool_fk', PARM_INTEGER);
        $text = _("Default bucket pool");
        $V.= "$Style<th>$text</th>\n";
        $V.= "<td>";
        $V.= SelectBucketPool($Val);
        $V.= "</td>\n";
        $V.= "</tr>\n";
        $text = _("User Interface Options");
        $text1 = _("Use the simplified UI (Default)");
        $text2 = _("Use the original UI");
        $V .= "$Style<th>$text</th><td><input type='radio'" .
                "name='whichui' id='simple' value='simple' checked='checked'>" .
                "$text1<br><input type='radio'" .
                "name='whichui' id='original' value='original'>" .
                "$text2</td>\n";
        $V.= "</table><P />";
        $text = _("Update Account");
        $V.= "<input type='submit' value='$text'>\n";
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
$NewPlugin = new user_edit_any;
?>
