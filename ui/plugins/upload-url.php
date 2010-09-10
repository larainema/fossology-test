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
if (!isset($GlobalReady)) {
  exit;
}

define("TITLE_upload_url", _("Upload from URL"));

class upload_url extends FO_Plugin {
  public $Name = "upload_url";
  public $Title = TITLE_upload_url;
  public $Version = "1.0";
  public $MenuList = "Upload::From URL";
  public $Dependency = array("db", "agent_unpack");
  public $DBaccess = PLUGIN_DB_UPLOAD;

  /*********************************************
   Upload(): Process the upload request.
   Returns NULL on success, string on failure.
   *********************************************/

  function Upload($Folder, $GetURL, $Desc, $Name) {
    /* See if the URL looks valid */
    if (empty($Folder)) {
$text = _("Invalid folder");
      return ($text);
    }
    if (empty($GetURL)) {
$text = _("Invalid URL");
      return ($text);
    }
    if (preg_match("@^((http)|(https)|(ftp))://([[:alnum:]]+)@i", $GetURL) != 1) {
$text = _("Invalid URL");
      return ("$text: " . htmlentities($GetURL));
    }
    if (preg_match("@[[:space:]]@", $GetURL) != 0) {
$text = _("Invalid URL (no spaces permitted)");
      return ("$text: " . htmlentities($GetURL));
    }
    if (empty($Name)) {
      $Name = basename($GetURL);
    }
    $ShortName = basename($Name);
    if (empty($ShortName)) {
      $ShortName = $Name;
    }
    /* Create an upload record. */
    $Mode = (1 << 2); // code for "it came from wget"
    $uploadpk = JobAddUpload($ShortName, $GetURL, $Desc, $Mode, $Folder);
    if (empty($uploadpk)) {
$text = _("Failed to insert upload record");
      return ($text);
    }
    /* Prepare the job: job "wget" */
    $jobpk = JobAddJob($uploadpk, "wget");
    if (empty($jobpk) || ($jobpk < 0)) {
$text = _("Failed to insert job record");
      return ($text);
    }
    /* Prepare the job: job "wget" has jobqueue item "wget" */
    /** 2nd parameter is obsolete **/
    $jobqueuepk = JobQueueAdd($jobpk, "wget", "$uploadpk - $GetURL", "no", NULL, NULL);
    if (empty($jobqueuepk)) {
$text = _("Failed to insert task 'wget' into job queue");
      return ($text);
    }
    global $Plugins;
    $Unpack = & $Plugins[plugin_find_id("agent_unpack") ];
    $Unpack->AgentAdd($uploadpk, array($jobqueuepk));
    AgentCheckBoxDo($uploadpk);

    $Url = Traceback_uri() . "?mod=showjobs&history=1&upload=$uploadpk";
$text = _("The upload");
$text1 = _("has been scheduled. It is");
    $msg = "$text $Name $text1 ";
    $keep =  "<a href='$Url'>upload #" . $uploadpk . "</a>.\n";
    print displayMessage($msg,$keep);
    return (NULL);
  } // Upload()
  /*********************************************
  Output(): Generate the text for this plugin.
  *********************************************/
  function Output() {
    if ($this->State != PLUGIN_STATE_READY) {
      return;
    }
    $V = "";
    switch ($this->OutputType) {
      case "XML":
        break;
      case "HTML":
        /* If this is a POST, then process the request. */
        $Folder = GetParm('folder', PARM_INTEGER);
        $GetURL = GetParm('geturl', PARM_TEXT);
        $Desc = GetParm('description', PARM_TEXT); // may be null
        $Name = GetParm('name', PARM_TEXT); // may be null
        if (!empty($GetURL) && !empty($Folder)) {
          $rc = $this->Upload($Folder, $GetURL, $Desc, $Name);
          if (empty($rc)) {
            /* Need to refresh the screen */
            $GetURL = NULL;
            $Desc = NULL;
            $Name = NULL;
          }
          else {
$text = _("Upload failed for");
            $V.= displayMessage("$text $GetUrl: $rc");
          }
        }
        /* Set default values */
        if (empty($GetURL)) {
          $GetURL = 'http://';
        }
        /* Display instructions */
        $V.= _("This option permits uploading a file from a remote web or FTP server to FOSSology.\n");
        $V.= _("The file to upload must be accessible via a URL and must not require human interaction ");
        $V.= _("such as login credentials.\n");
        /* Display the form */
        $V.= "<form method='post'>\n"; // no url = this url
        $V.= "<ol>\n";
$text = _("Select the folder for storing the uploaded file:");
        $V.= "<li>$text\n";
        $V.= "<select name='folder'>\n";
        $V.= FolderListOption(-1, 0);
        $V.= "</select><P />\n";
$text = _("Enter the URL to the file:");
        $V.= "<li>$text<br />\n";
        $V.= "<INPUT type='text' name='geturl' size=60 value='" . htmlentities($GetURL) . "'/><br />\n";
$text = _("NOTE");
$text1 = _(": If the URL requires authentication or navigation to access, then the upload will fail. Only provide a URL that goes directly to the file. The URL can begin with HTTP://, HTTPS://, or FTP://.");
        $V.= "<b>$text</b>$text1<P />\n";
$text = _("(Optional) Enter a description of this file:");
        $V.= "<li>$text<br />\n";
        $V.= "<INPUT type='text' name='description' size=60 value='" . htmlentities($Desc) . "'/><P />\n";
$text = _("(Optional) Enter a viewable name for this file:");
        $V.= "<li>$text<br />\n";
        $V.= "<INPUT type='text' name='name' size=60 value='" . htmlentities($Name) . "'/><br />\n";
$text = _("NOTE");
$text1 = _(": If no name is provided, then the uploaded file name will be used.");
        $V.= "<b>$text</b>$text1<P />\n";
        if (@$_SESSION['UserLevel'] >= PLUGIN_DB_ANALYZE) {
$text = _("Select optional analysis");
          $V.= "<li>$text<br />\n";
          $V.= AgentCheckBoxMake(-1, "agent_unpack");
        }
        $V.= "</ol>\n";
$text = _("Upload");
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
$NewPlugin = new upload_url;
?>
