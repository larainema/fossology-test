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
class agent_copyright_once extends FO_Plugin {
    public $Name = "agent_copyright_once";
    public $Title = "One-Shot Copyright/Email/URL Analysis";
    // Note: menuList is not needed for this plugin, it inserts into the menu
    // in the code below.
    //public $MenuList = "Upload::One-Shot Bsam";
    public $Version = "1.0";
    public $Dependency = array(
        "view",
        "copyrightview"
    );
    public $NoHTML = 0;
    /** For anyone to access, without login, use: **/
    // public $DBaccess   = PLUGIN_DB_NONE;
    // public $LoginFlag  = 0;

    /** To require login access, use: **/
    public $DBaccess = PLUGIN_DB_ANALYZE;
    public $LoginFlag = 1;
  /*********************************************
  AnalyzeOne(): Analyze one uploaded file.
  *********************************************/
    function AnalyzeOne($Highlight) {
        global $Plugins;
        global $AGENTDIR;
        global $DATADIR;
        $ModBack = GetParm("modback",PARM_STRING);

        $V = "";
        $View = & $Plugins[plugin_find_id("view") ];
        $TempFile = $_FILES['licfile']['tmp_name'];
        $Sys = $AGENTDIR."/copyright/run.py --model ".$DATADIR."/model.dat --analyze-from-command-line $TempFile";
        $Fin = popen($Sys, "r");
        $colors = Array();
        $colors['statement'] = 0;
        $colors['email'] = 1;
        $colors['url'] = 2;
        $stuff = Array();
        $stuff['statement'] = Array();
        $stuff['email'] = Array();
        $stuff['url'] = Array();
        while (!feof($Fin)) {
            $Line = fgets($Fin);
            if (strlen($Line) > 0) {
                //print $Line;
                $match = array();
                preg_match_all("/\t\[(?P<start>\d+)\:(?P<end>\d+)\:(?P<type>[A-Za-z]+)\] \'(?P<content>.+)\'/", $Line, $match);
                //print_r($match);
                if (!empty($match['start'])) {
                    $stuff[$match['type'][0]][] = $match['content'][0];
                    $View->AddHighlight($match['start'][0], $match['end'][0], $colors[$match['type'][0]], '', $match['content'][0],-1);
                }
            }
        }
        pclose($Fin);
        if ($Highlight) {
            $Fin = fopen($TempFile, "r");
            if ($Fin) {
                $View->SortHighlightMenu();
echo "Fin1: $Fin<br>";
                $View->ShowView($Fin,$ModBack, 1,1,NULL,True);
echo "Fin2: $Fin<br>";

                fclose($Fin);
            }
        }
        else {
            print "<table width=100%>\n";
            print "<tr><td>Copyright Statments:</td></tr>\n";
            print "<tr><td><hr></td></tr>\n";
            if (count($stuff['statement']) > 0) {
                foreach ($stuff['statement'] as $i) {
                    print "<tr><td>$i</td></tr>\n";
                }
                print "<tr><td><hr></td></tr>\n";
            }
            print "<tr><td>Total: ".count($stuff['statement'])."</td></tr>\n";
            print "</table>\n";
            
            print "<br><br>\n";
            
            print "<table width=100%>\n";
            print "<tr><td>Emails:</td></tr>\n";
            print "<tr><td><hr></td></tr>\n";
            if (count($stuff['email']) > 0) {
                foreach ($stuff['email'] as $i) {
                    print "<tr><td>$i</td></tr>\n";
                }
                print "<tr><td><hr></td></tr>\n";
            }
            print "<tr><td>Total: ".count($stuff['email'])."</td></tr>\n";
            print "</table>\n";
            
            print "<br><br>\n";
            
            print "<table width=100%>\n";
            print "<tr><td>URLs:</td></tr>\n";
            print "<tr><td><hr></td></tr>\n";
            if (count($stuff['url']) > 0) {
                foreach ($stuff['url'] as $i) {
                    print "<tr><td>$i</td></tr>\n";
                }
                print "<tr><td><hr></td></tr>\n";
            }
            print "<tr><td>Total: ".count($stuff['url'])."</td></tr>\n";
            print "</table>\n";
        }
        /* Clean up */
        return ($V);
    } // AnalyzeOne()
  /*********************************************
  RegisterMenus(): Change the type of output
  based on user-supplied parameters.
  Returns 1 on success.
  *********************************************/
    function RegisterMenus() {
        if ($this->State != PLUGIN_STATE_READY) {
            return (0);
        } // don't run
        $Highlight = GetParm('highlight', PARM_INTEGER);
        if (empty($Hightlight)) {
            $Highlight = 0;
        }
        $ShowHeader = GetParm('showheader', PARM_INTEGER);
        if (empty($ShowHeader)) {
            $ShowHeader = 0;
        }
        if (GetParm("mod", PARM_STRING) == $this->Name) {
            $ThisMod = 1;
        }
        else {
            $ThisMod = 0;
        }
        /* Check for a wget post (wget cannot post to a variable name) */
        if ($ThisMod && empty($_POST['licfile'])) {
            $Fin = fopen("php://input", "r");
            $Ftmp = tempnam(NULL, "fosslic-alo-");
            $Fout = fopen($Ftmp, "w");
            while (!feof($Fin)) {
                $Line = fgets($Fin);
                fwrite($Fout, $Line);
            }
            fclose($Fout);
            if (filesize($Ftmp) > 0) {
                $_FILES['licfile']['tmp_name'] = $Ftmp;
                $_FILES['licfile']['size'] = filesize($Ftmp);
                $_FILES['licfile']['unlink_flag'] = 1;
            }
            else {
                unlink($Ftmp);
            }
            fclose($Fin);
        }
        if ($ThisMod && file_exists(@$_FILES['licfile']['tmp_name']) && ($Highlight != 1) && ($ShowHeader != 1)) {
            $this->NoHTML = 1;
            /* default header is plain text */
        }
        /* Only register with the menu system if the user is logged in. */
        if (!empty($_SESSION['User'])) {
            // Debugging changes to license analysis NOTE: this comment doesn't make sense.
            if (@$_SESSION['UserLevel'] >= PLUGIN_DB_ANALYZE) {
                menu_insert("Main::Upload::One-Shot Copyright/Email/URL", $this->MenuOrder, $this->Name, $this->MenuTarget);
            }
            // Debugging changes to license analysis
            if (@$_SESSION['UserLevel'] >= PLUGIN_DB_DEBUG) {
                $URI = $this->Name . Traceback_parm_keep(array(
                    "format",
                    "upload",
                    "item"
                ));
                menu_insert("View::[BREAK]", 100);
                menu_insert("View::One-Shot Copyright/Email/URL", 101, $URI, "Copyright/Email/URL One-shot, real-time analysis");
                menu_insert("View-Meta::[BREAK]", 100);
                menu_insert("View-Meta::One-Shot Copyright/Email/URL", 101, $URI, "Copyright/Email/URL One-shot, real-time analysis");
            }
        }
    } // RegisterMenus()
  /*********************************************
  Output(): Generate the text for this plugin.
  *********************************************/
    function Output() {
        if ($this->State != PLUGIN_STATE_READY) {
            return;
        }
        global $DB;
        global $DATADIR;
        global $PROJECTSTATEDIR;
        $V = "";
        switch ($this->OutputType) {
        case "XML":
            break;
        case "HTML":
            /* If this is a POST, then process the request. */
            $Highlight = GetParm('highlight', PARM_INTEGER); // may be null
            /* You can also specify the file by uploadtree_pk as 'item' */
            $Item = GetParm('item', PARM_INTEGER); // may be null
            if (file_exists(@$_FILES['licfile']['tmp_name'])) {
                if ($_FILES['licfile']['size'] <= 1024 * 1024 * 10) {
                    /* Size is not too big.  */
                    print $this->AnalyzeOne($Highlight) . "\n";
                }
                if (!empty($_FILES['licfile']['unlink_flag'])) {
                    unlink($_FILES['licfile']['tmp_name']);
                }
                return;
            }
            else if (!empty($Item) && !empty($DB)) {
                /* Get the pfile info */
                $Results = $DB->Action("SELECT * FROM pfile
                    INNER JOIN uploadtree ON uploadtree_pk = $Item
                    AND pfile_pk = pfile_fk;");
                if (!empty($Results[0]['pfile_pk'])) {
                    global $LIBEXECDIR;
                    $Highlight = 1; /* processing a pfile? Always highlight. */
                    $Repo = $Results[0]['pfile_sha1'] . "." . $Results[0]['pfile_md5'] . "." . $Results[0]['pfile_size'];
                    $Repo = trim(shell_exec("$LIBEXECDIR/reppath files '$Repo'"));
                    $_FILES['licfile']['tmp_name'] = $Repo;
                    $_FILES['licfile']['size'] = $Results[0]['pfile_size'];
                    if ($_FILES['licfile']['size'] <= 1024 * 1024 * 10) {
                        /* Size is not too big.  */
                        print $this->AnalyzeOne($Highlight) . "\n";
                    }
                    /* Do not unlink the or it will delete the repo file! */
                    if (!empty($_FILES['licfile']['unlink_flag'])) {
                        unlink($_FILES['licfile']['tmp_name']);
                    }
                    return;
                }
            }
            /* Display instructions */
            $V.= "This analyzer allows you to upload a single file for copyright/email/url analysis.\n";
            $V.= "The limitations:\n";
            $V.= "<ul>\n";
            $V.= "<li>The analysis is done in real-time. Large files may take a while. This method is not recommended for files larger than a few hundred kilobytes.\n";
            $V.= "<li>Files that contain files are <b>not</b> unpacked. If you upload a 'zip' or 'deb' file, then the binary file will be scanned for copyright/email/urls and nothing will likely be found.\n";
            $V.= "<li>Results are <b>not</b> stored. As soon as you get your results, your uploaded file is removed from the system.\n";
            $V.= "</ul>\n";
            /* Display the form */
            $V.= "<form enctype='multipart/form-data' method='post'>\n";
            $V.= "<ol>\n";
            $V.= "<li>Select the file to upload:<br />\n";
            $V.= "<input name='licfile' size='60' type='file' /><br />\n";
            $V.= "<b>NOTE</b>: Files larger than 100K will be discarded and not analyzed.<P />\n";
            $V.= "<li><input type='checkbox' name='highlight' value='1'>Check if you want to see the highlighted text.\n";
            $V.= "Unchecked returns a simple list that summarizes the identified types.";
            $V.= "<P />\n";
            $V.= "</ol>\n";
            $V.= "<input type='hidden' name='showheader' value='1'>";
            $V.= "<input type='submit' value='Analyze!'>\n";
            $V.= "</form>\n";
            break;
        case "Text":
            break;
        default:
            break;
        }
        if (!empty($_FILES['licfile']['unlink_flag'])) {
            unlink($_FILES['licfile']['tmp_name']);
        }
        if (!$this->OutputToStdout) {
            return ($V);
        }
        print ($V);
        return;
    }
};
$NewPlugin = new agent_copyright_once;
?>
