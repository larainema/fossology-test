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

 -----------------------------------------------------

 The Javascript code to move values between tables is based
 on: http://www.mredkj.com/tutorials/tutorial_mixed2b.html
 The page, on 28-Apr-2008, says the code is "public domain".
 His terms and conditions (http://www.mredkj.com/legal.html)
 says "Code marked as public domain is without copyright, and
 can be used without restriction."
 This segment of code is noted in this program with "mredkj.com".
 ***********************************************************/

/*************************************************
 Restrict usage: Every PHP file should have this
 at the very beginning.
 This prevents hacking attempts.
 *************************************************/
global $GlobalReady;
if (!isset($GlobalReady)) { exit; }

/************************************************
 Plugin for creating License Groups
 *************************************************/
class licterm_default extends FO_Plugin
  {
  var $Name       = "license_terms_default";
  var $Title      = "Reset Default License Terms";
  var $Version    = "1.0";
  var $MenuList   = "Obsolete::License::Default Terms";
  var $Dependency = array("db","licterm_manage");
  var $DBaccess   = PLUGIN_DB_USERADMIN;
  var $LoginFlag  = 1; /* must be logged in to use this */

  /***********************************************************
   Install(): Create and configure database tables
   ***********************************************************/
  function Install()
  {
    global $DB;
    if (empty($DB)) { return(1); } /* No DB */

    /****************
     Terms needed tables:
     Table #1: List of term groups (name, description) ("licterm")
     Table #2: List of terms ("licterm_words")
     Table #3: Associated matrix of terms to term groups ("licterm_map")
     ****************/

    /* check if the table needs population */
    $SQL = "SELECT * FROM licterm LIMIT 1;";
    $Results = $DB->Action($SQL);
    if (count($Results) == 0)
      {
      $this->DefaultTerms();
      }
    return(0);
  } // Install()

  /***********************************************************
   ExportTerms(): Display the entire term table system as a big array.
   This array should be pasted into the Default() function for
   use as the default values.
   NOTE: This only exports canonical names that contain terms.
   ***********************************************************/
  function ExportTerms	()
    {
    global $DB;
    $Names = $DB->Action("SELECT * FROM licterm ORDER BY licterm_name;");
    print "<H3>Export Data</H3>\n";

    global $WEBDIR;
    $Filename = "plugins/ui-licterm-default.dat";
    $Fout = fopen($Filename,"w");
    if (!$Fout)
      {
      return("Failed to write to $Filename\n");
      }

    fwrite($Fout,"<?php\n");
    fwrite($Fout,"/* This file is generated by " . $this->Name . " */\n");
    fwrite($Fout,"/* Do not manually edit this file */\n\n");
    fwrite($Fout,"  global \$GlobalReady;\n");
    fwrite($Fout,"  if (!isset(\$GlobalReady)) { exit; }\n\n");

    fwrite($Fout,'  $Term=array();' . "\n");
    for($n=0; !empty($Names[$n]['licterm_name']); $n++)
      {
      $Name = $Names[$n]['licterm_name'];
      $Name = str_replace('"','\\"',$Name);
      $Desc = $Names[$n]['licterm_desc'];
      $Desc = str_replace('"','\\"',$Desc);
      $Pk = $Names[$n]['licterm_pk'];

      /* Get terms associated with the canonical name */
      $SQL = "SELECT DISTINCT licterm_words_text FROM licterm_words INNER JOIN licterm_map ON licterm_fk='$Pk' AND licterm_words_fk = licterm_words_pk ORDER BY licterm_words_text;";
      $Terms = $DB->Action($SQL);

      /* Get Licenses associated with the canonical name */
      $SQL = "SELECT DISTINCT lic_name FROM agent_lic_raw INNER JOIN licterm_maplic ON licterm_fk='$Pk' AND lic_fk = lic_pk ORDER BY lic_name;";
      $Lics = $DB->Action($SQL);

      /* Check if we need to write it */
      if ((count($Terms) <= 0) && (count($Lics) <= 0)) { continue; }

      /* Create the canonical name record */
      fwrite($Fout,"  /* Canonical name: $Pk */\n");
      fwrite($Fout,'  $Term["' . $Name . '"]["Desc"]="' . $Desc . '";' . "\n");

      /* Write the terms */
      $T = array();
      for($t=0; !empty($Terms[$t]['licterm_words_text']); $t++)
        {
	$Term = $Terms[$t]['licterm_words_text'];
        $Term = str_replace('"','\\"',$Term);
        $Term = str_replace('licenc','licens',$Term);
	if (empty($T[$Term]))
	  {
	  if ($t == 0)
	    {
	    fwrite($Fout,'  $Term["' . $Name . '"]["Term"][' . $t . ']="' . $Term . '";' . "\n");
	    }
	  else
	    {
	    fwrite($Fout,'  $Term["' . $Name . '"]["Term"][]="' . $Term . '";' . "\n");
	    }
	  $T[$Term]=1;
	  }
	}

      /* Write the Licenses */
      for($l=0; !empty($Lics[$l]['lic_name']); $l++)
        {
	$Lic = $Lics[$l]['lic_name'];
        $Lic = str_replace('"','\\"',$Lic);
	fwrite($Fout,'  $Term["' . $Name . '"]["License"][' . $l . ']="' . $Lic . '";' . "\n");
	}
      }
    fwrite($Fout,"?>\n");
    fclose($Fout);
    print "Data written to $Filename\n";
    print "<hr>\n";
    } // ExportTerms()

  /***********************************************************
   DefaultTerms(): Create a default terms, canonical names, and
   associations.
   The huge array list was created by the Export() call.
   ***********************************************************/
  function DefaultTerms()
    {
    global $DB;
    global $Plugins;

    /**************************************/
    /** BEGIN: Term list from ExportTerms() **/
    /**************************************/
    require_once("ui-licterm-default.dat");
    /**************************************/
    /** END: Term list from ExportTerms() **/
    /**************************************/

    $LT = &$Plugins[plugin_find_id("licterm_manage")-100];
    /* During install, $LT may be empty but we can still use it */
    if (empty($LT)) { $LT = &$Plugins[plugin_find_any_id("licterm_manage")]; }
    print "<ol>\n";
    foreach($Term as $Key => $Val)
      {
      print "<li>Creating default canonical name: " . htmlentities($Key) . "\n";

      /* Get the list of licenses */
      $SQL = "SELECT DISTINCT lic_id FROM agent_lic_raw WHERE lic_pk=lic_id AND (";
      $First=0;
      for($L=0; !empty($Val['License'][$L]); $L++)
        {
	if ($First) { $SQL .= " OR"; }
	$First=1;
	$Name = $Val['License'][$L];
	$Name = str_replace("'","''",$Name);
	$SQL .= " lic_name='$Name'";
	}
      $SQL .= ");";
      if ($First)
        {
	$LicListDB = $DB->Action($SQL);
	$LicList = array();
	for($L=0; !empty($LicListDB[$L]['lic_id']); $L++)
	  {
	  $LicList[] = $LicListDB[$L]['lic_id'];
	  }
	}
      else { $LicList = NULL; }
      /* Get the list of terms */
      $TermList = $Val['Term'];
      /* Create! */
      /** Delete terms and license mappings, but not the canonical names **/
      $LT->LicTermInsert('',$Key,$Val['Desc'],$TermList,$LicList,0);
      }
    print "</ol><hr>\n";
    } // DefaultTerms()

  /***********************************************************
   Output(): This function is called when user output is
   requested.  This function is responsible for content.
   (OutputOpen and Output are separated so one plugin
   can call another plugin's Output.)
   This uses $OutputType.
   The $ToStdout flag is "1" if output should go to stdout, and
   0 if it should be returned as a string.  (Strings may be parsed
   and used by other plugins.)
   ***********************************************************/
  function Output()
    {
    global $Plugins;

    if ($this->State != PLUGIN_STATE_READY) { return; }
    $V="";
    switch($this->OutputType)
      {
      case "XML":
	break;
      case "HTML":
	$Init = GetParm('Default',PARM_INTEGER);
	if ($Init == 1)
	  {
	  $rc = $this->DefaultTerms();
	  if (!empty($rc))
	    {
	    $V .= displayMessage($rc);
	    }
	  }

	/* Undocumented parameter: Used for exporting the current terms. */
	$Init = GetParm('Export',PARM_INTEGER);
	if ($Init == 1)
	  {
	  $rc = $this->ExportTerms();
	  if (!empty($rc))
	    {
	    $V .= displayMessage($rc);
	    }
	  }

	$V .= "<form method='post'>\n";
	$V .= "License terms associate common license names with canonical license names.\n";
	$V .= "For example, the terms 'GPL' and 'Gnu Public License' are both commonly used to describe the Free Software Foundation's GNU General Public License. These terms are all commonly referred to be the canonical name 'GPL'.\n";
	$V .= "<P />\n";
    $V .= "You shouldn't need to do this unless you have modified the default terms and want to reset them back to the way they were when you installed this software.  ";
	$V .= "This will reset the default license terms, canonical names, and associations between terms, license templates, and canonical names.";
	$V .= "<ul>\n";
	$V .= "<li>The default license settings are <b>NOT</b> a recommendation or legal interpretation.\n";
	$V .= "In particular, related terms, templates, and canonical names may have very different legal meanings.\n";
	$V .= "<li>Resetting the default terms will not impact any new terms you have created or their associations.\n";
	$V .= "</ul>\n";
	$V .= "You can modify, edit, or delete the default groups with the ";
	$P = &$Plugins[plugin_find_id("licterm_manage")];
	$V .= "<a href='" . Traceback_uri() . "?mod=" . $P->Name . "'>" . $P->Title . "</a>";
	$V .= " menu option.\n";
	$V .= "You can also use the ";
	$V .= "<a href='" . Traceback_uri() . "?mod=" . $P->Name . "'>" . $P->Title . "</a>";
	$V .= " to create new terms, canonical names, and associations.<P/>\n";

	$V .= "<P/>\n";
	// $V .= "<input type='checkbox' value='1' name='Export'>Check to export term-related information.<br>\n";
	$V .= "<input type='checkbox' value='1' name='Default'>Check to revert back to the original default terms, canonical names, and license template associations.\n";
	$V .= "<P/>\n";
	$V .= "<input type='submit' value='RESET!'>";
	$V .= "</form>\n";
	break;
      case "Text":
	break;
      default:
	break;
      }
    if (!$this->OutputToStdout) { return($V); }
    print($V);
    return;
    }

  };
$NewPlugin = new licterm_default;
?>
