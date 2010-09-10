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
if (!isset($GlobalReady)) { exit; }

define("TITLE_admin_license_file", _("License Administration"));

class admin_license_file extends FO_Plugin
{
  var $Name       = "Admin_License";
  var $Version    = "1.0";
  var $Title      = TITLE_admin_license_file;
  var $MenuList   = "Admin::License Admin";
  var $Dependency = array("db");
  var $DBaccess   = PLUGIN_DB_WRITE;

/***********************************************************
 RegisterMenus(): Customize submenus.
***********************************************************/
function RegisterMenus()
{
  if ($this->State != PLUGIN_STATE_READY) { return(0); } 

  // micro-menu
  $URL = $this->Name."&add=y";
$text = _("Add new license");
  menu_insert($this->Name."::Add License",0, $URL, $text);
  $URL = $this->Name;
$text = _("Select license family");
  menu_insert($this->Name."::Select License",0, $URL, $text);
}


/************************************************
 Inputfm(): Build the input form 

 Return: The input form as a string
 ************************************************/
function Inputfm()
{
  $V = "";

  $V.= "<FORM name='Inputfm' action='?mod=" . $this->Name . "' method='POST'>";
  $V.= _("What license family do you wish to view:<br>");

  // qualify by marydone, short name and long name
  // all are optional
  $V.= "<p>";
  $V.= _("Filter: ");
  $V.= "<SELECT name='req_marydone'>\n";
  $Selected =  ($_REQUEST['req_marydone'] == 'all') ? " SELECTED ": "";
$text = _("All");
  $V.= "<option value='all' $Selected> $text </option>";
  $Selected =  ($_REQUEST['req_marydone'] == 'done') ? " SELECTED ": "";
$text = _("Checked");
  $V.= "<option value='done' $Selected> $text </option>";
  $Selected =  ($_REQUEST['req_marydone'] == 'notdone') ? " SELECTED ": "";
$text = _("Not Checked");
  $V.= "<option value='notdone' $Selected> $text </option>";
  $V.= "</SELECT>";
  $V.= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";

  // by short name -ajax-> fullname
  $V.= _("License family name: ");
  //$Shortnamearray = DB2KeyValArray("license_ref", "rf_pk", "rf_shortname");
  $Shortnamearray = $this->FamilyNames();
  $Shortnamearray = array("All"=>"All") + $Shortnamearray;
  $Selected = $_REQUEST['req_shortname'];
  $Pulldown = Array2SingleSelect($Shortnamearray, "req_shortname", $Selected);
  $V.= $Pulldown;
  $V.= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
$text = _("Find");
  $V.= "<INPUT type='submit' value='$text'>\n";
  $V .= "</FORM>\n";
  $V.= "<hr>";

  return $V;
}


/************************************************
 LicenseList(): Build the input form 

 Parms: 
   $namestr license family name
   $filter  marydone value requested
 Return: The input form as a string
 ************************************************/
function LicenseList($namestr, $filter)
{
  global $PG_CONN;

  $ob = "";     // output buffer
  $whereextra = "";  // additional stuff for where sql where clause

  // look at all 
  if ($namestr == "All")
    $where = "";
  else
    $where = "where rf_shortname like '$namestr%' ";

  // $filter is one of these: "All", "done", "notdone"
  if ($filter != "all")
  {
    if (empty($where)) 
      $where .= "where ";
    else
      $where .= " and ";
    if ($filter == "done") $where .= " marydone=true";
    if ($filter == "notdone") $where .= " marydone=false";
  }

  $sql = "select * from license_ref $where order by rf_shortname";
  $result = pg_query($PG_CONN, $sql);
  DBCheckResult($result, $sql, __FILE__, __LINE__);

  // print simple message if we have no results
  if (pg_num_rows($result) == 0)
  {
$text = _("No licenses matching the filter");
$text1 = _("and name pattern");
$text2 = _("were found");
    $ob .= "<br>$text ($filter) $text1 ($namestr) $text2.<br>";
    return $ob;
  }

  $plural = (pg_num_rows($result) == 1) ? "" : "s";
  $ob .= pg_num_rows($result) . " license$plural found.";

  //$ob .= "<table style='border: thin dotted gray'>";
  $ob .= "<table rules='rows' cellpadding='3'>";
  $ob .= "<tr>";
$text = _("Edit");
  $ob .= "<th>$text</th>";
$text = _("Checked");
  $ob .= "<th>$text</th>";
$text = _("Shortname");
  $ob .= "<th>$text</th>";
$text = _("Fullname");
  $ob .= "<th>$text</th>";
$text = _("Text");
  $ob .= "<th>$text</th>";
$text = _("URL");
  $ob .= "<th>$text</th>";
  $ob .= "</tr>";
  $lineno = 0;
  while ($row = pg_fetch_assoc($result))
  {
    if ($lineno++ % 2)
      $style = "style='background-color:lavender'";
    else
      $style = "";
    $ob .= "<tr $style>";

    // Edit button brings up full screen edit of all license_ref fields
    $ob .= "<td align=center><a href='";
    $ob .= Traceback_uri();
    $ob .= "?mod=" . $this->Name . 
           "&rf_pk=$row[rf_pk]".
           "&req_marydone=$_REQUEST[req_marydone]&req_shortname=$_REQUEST[req_shortname]' >".
           "<img border=0 src='images/button_edit.png'></a></td>";

    $marydone = ($row['marydone'] == 't') ? "Yes" : "No";
/* to allow editing in line
    $select = Array2SingleSelect(array("Yes", "No"), "marydone", $marydone);
$text = _("$select");
    $ob .= "<td align=center>$text</td>";
*/
$text = _("$marydone");
    $ob .= "<td align=center>$text</td>";

    $ob .= "<td>$row[rf_shortname]</td>";
    $ob .= "<td>$row[rf_fullname]</td>";
    $vetext = htmlspecialchars($row[rf_text]);
    $ob .= "<td><textarea readonly=readonly rows=3 cols=40>$vetext</textarea></td> ";
    $ob .= "<td>$row[rf_url]</td>";
    $ob .= "</tr>";
  }
  $ob .= "</table>";
  return $ob;
}


/************************************************
 Updatefm(): Update forms

 Params:
  $rf_pk for the license to update, empty to add
 
 Return: The input form as a string
 ************************************************/
function Updatefm($rf_pk)
{
  global $PG_CONN;

  $ob = "";     // output buffer
  $ob .= "<FORM name='Updatefm' action='?mod=" . $this->Name . "' method='POST'>";
  $ob .= "<input type=hidden name=rf_pk value='$rf_pk'>";
  $ob .= "<input type=hidden name=req_marydone value='$_GET[req_marydone]'>";
  $ob .= "<input type=hidden name=req_shortname value='$_GET[req_shortname]'>";
  $ob .= "<table>";

  if ($rf_pk)  // true if this is an update
  {
    $sql = "select * from license_ref where rf_pk='$rf_pk'";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);

    // print simple message if we have no results
    if (pg_num_rows($result) ==0)
    {
      $ob .= "</table>";
$text = _("No licenses matching this key");
$text1 = _("was found");
      $ob .= "<br>$text ($rf_pk) $text1.<br>";
      return $ob;
    }
    $ob .= "<input type=hidden name=updateit value=true>";
    $row = pg_fetch_assoc($result);
  }
  else  // this is an add new rec
  {
    $ob .= "<input type=hidden name=addit value=true>";
    $row = array();
  }


    $ob .= "<tr>";
    $active = ($row['rf_active'] == 't') ? "Yes" : "No";
    $select = Array2SingleSelect(array("true"=>"Yes", "false"=>"No"), "rf_active", $active);
$text = _("Active");
    $ob .= "<td align=right>$text</td>";
    $ob .= "<td align=left>$select</td>";
    $ob .= "</tr>";

    $ob .= "<tr>";
    $marydone = ($row['marydone'] == 't') ? "Yes" : "No";
    $select = Array2SingleSelect(array("true"=>"Yes", "false"=>"No"), "marydone", $marydone);
$text = _("Checked");
    $ob .= "<td align=right>$text</td>";
    $ob .= "<td align=left>$select</td>";
    $ob .= "</tr>";

    $ob .= "<tr>";
//    $ob .= "<td align=right>Short name<br>(read only)</td>";
//    $ob .= "<td><input readonly='readonly' type='text' name='rf_shortname' value='$row[rf_shortname]' size=80></td>";
$text = _("Short name");
    $ob .= "<td align=right>$text</td>";
    $ob .= "<td><input type='text' name='rf_shortname' value='$row[rf_shortname]' size=80></td>";
    $ob .= "</tr>";

    $ob .= "<tr>";
$text = _("Full name");
    $ob .= "<td align=right>$text</td>";
    $ob .= "<td><input type='text' name='rf_fullname' value='$row[rf_fullname]' size=80></td>";
    $ob .= "</tr>";

    $ob .= "<tr>";
    $updatable = ($row['rf_text_updatable'] == 't') ? true : false;
    if (empty($rf_pk) || $updatable)
    {
      $rotext = '';
      $rooption = '';
    }
    else
    {
$text = _("(read only)");
      $rotext = "<br>$text";
      $rooption = "readonly='readonly'";
    }
$text = _("License Text");
    $ob .= "<td align=right>$text $rotext</td>";
    $ob .= "<td><textarea name='rf_text' rows=10 cols=80 $rooption>".$row[rf_text]. "</textarea></td> ";
    $ob .= "</tr>";

    $ob .= "<tr>";
    $tupable = ($row['rf_text_updatable'] == 't') ? "Yes" : "No";
    $select = Array2SingleSelect(array("true"=>"Yes", "false"=>"No"), "rf_text_updatable", $tupable);
$text = _("Text Updatable");
    $ob .= "<td align=right>$text</td>";
    $ob .= "<td align=left>$select</td>";
    $ob .= "</tr>";

    $ob .= "<tr>";
    $dettype = ($row['rf_detector_type'] == '2') ? "Nomos" : "Reference License";
    $select = Array2SingleSelect(array("1"=>"Reference License", "2"=>"Nomos"), "rf_detector_type", $dettype);
$text = _("Detector Type");
    $ob .= "<td align=right>$text</td>";
    $ob .= "<td align=left>$select</td>";
    $ob .= "</tr>";

    $ob .= "<tr>";
$text = _("URL");
    $ob .= "<td align=right>$text";
    $ob .= "<a href='$row[rf_url]'><image border=0 src=images/right-point-bullet.gif></a></td>";
    $ob .= "<td><input type='text' name='rf_url' value='$row[rf_url]' size=80></td>";
    $ob .= "</tr>";

    $ob .= "<tr>";
$text = _("Public Notes");
    $ob .= "<td align=right>$text</td>";
    $ob .= "<td><textarea name='rf_notes' rows=5 cols=80>" .$row[rf_notes]. "</textarea></td> ";
    $ob .= "</tr>";
  
  $ob .= "</table>";
  if ($rf_pk){
$text = _("Update");
    $ob .= "<INPUT type='submit' value='$text'>\n";
  }else{
$text = _("Add License");
    $ob .= "<INPUT type='submit' value='$text'>\n";
  }
  $ob .= "</FORM>\n";
  return $ob;
}


/************************************************
 Updatedb(): Update the database

 Return: An update status string
 ************************************************/
function Updatedb()
{
  global $PG_CONN;

  $ob = "";     // output buffer

  $notes = pg_escape_string($_POST['rf_notes']);
  $text = pg_escape_string($_POST['rf_text']);
  $licmd5 = md5($text);
  $sql = "UPDATE license_ref set 
                 rf_active='$_POST[rf_active]', 
                 marydone='$_POST[marydone]',
                 rf_shortname='$_POST[rf_shortname]',
                 rf_fullname='$_POST[rf_fullname]',
                 rf_url='$_POST[rf_url]',
                 rf_notes='$notes',
                 rf_text_updatable='$_POST[rf_text_updatable]',
                 rf_detector_type='$_POST[rf_detector_type]',
                 rf_text='$_POST[rf_text]',
                 rf_md5='$licmd5'
            WHERE rf_pk='$_POST[rf_pk]'";
  $result = pg_query($PG_CONN, $sql);
  DBCheckResult($result, $sql, __FILE__, __LINE__);
  
  $ob = "License $_POST[rf_shortname] updated.<p>";
  return $ob;
}


/************************************************
 Adddb(): Add a new license_ref to the database

 Return: An add status string
 ************************************************/
function Adddb()
{
  global $PG_CONN;

  $ob = "";     // output buffer

  $notes = pg_escape_string($_POST['rf_notes']);
  $text = pg_escape_string($_POST['rf_text']);
  $licmd5 = md5($text);
  $sql = "INSERT into license_ref (
                 rf_active, marydone, rf_shortname, rf_fullname,
                 rf_url, rf_notes, rf_md5, rf_text, rf_text_updatable,
                 rf_detector_type) 
                 VALUES (
                 '$_POST[rf_active]',
                 '$_POST[marydone]',
                 '$_POST[rf_shortname]',
                 '$_POST[rf_fullname]',
                 '$_POST[rf_url]',
                 '$notes', '$licmd5', '$text',
                 '$_POST[rf_text_updatable]',
                 '$_POST[rf_detector_type]')";
  $result = pg_query($PG_CONN, $sql);
  DBCheckResult($result, $sql, __FILE__, __LINE__);
  
  $ob = "License $_POST[rf_shortname] added.<p>";
  return $ob;
}

/*
// tmp fcn to initally load md5s into ref table
function popmd5()
{
  global $PG_CONN;
  $sql = "select rf_pk, rf_text from license_ref where rf_md5 is null";
  $result = pg_query($PG_CONN, $sql);
  DBCheckResult($result, $sql, __FILE__, __LINE__);
  while ($row = pg_fetch_assoc($result))
  {
    $licmd5 = md5($row['rf_text']);
    $sql = "UPDATE license_ref set rf_md5='$licmd5' where rf_pk='$row[rf_pk]'";
    $updresult = pg_query($PG_CONN, $sql);
    DBCheckResult($updresult, $sql, __FILE__, __LINE__);
  }
}
*/


/************************************************
 Output(): Generate output.
 ************************************************/
function Output() 
{
  global $DB, $PG_CONN;
  global $Plugins;
  
  // make sure there is a db connection since I've pierced the core-db abstraction
  if (!$PG_CONN) { $dbok = $DB->db_init(); if (!$dbok) echo "NO DB connection"; }
    
  if ($this->State != PLUGIN_STATE_READY) { return; }
  $V="";
    
// tmp  $this->popmd5();
  switch($this->OutputType)
  {
    case "XML":
	  break;
    case "Text":
	  break;
    case "HTML":
      // micro menus
      $V .= menu_to_1html(menu_find($this->Name, $MenuDepth),0);

//debugprint($_REQUEST, "_REQUEST");

      // update the db
      if ($_POST["updateit"])
      {
        $V .= $this->Updatedb($_POST);
        $V .= $this->Inputfm();
        if ($_POST["req_shortname"]) 
          $V .= $this->LicenseList($_POST["req_shortname"], $_POST["req_marydone"]);
        break;
      }
    
      if ($_REQUEST['add'] == 'y')
      {
        $V .= $this->Updatefm(0);
        break;
      }

      // Add new rec to db
      if ($_POST["addit"])
      {
        $V .= $this->Adddb($_POST);
        $V .= $this->Inputfm();
        if ($_POST["req_shortname"]) 
          $V .= $this->LicenseList($_POST["req_shortname"], $_POST["req_marydone"]);
        break;
      }

      // bring up the update form
      $rf_pk = $_REQUEST['rf_pk'];
      if ($rf_pk)
      {
        $V .= $this->Updatefm($rf_pk);
        break;
      }

      $V .= $this->Inputfm();
      if ($_POST["req_shortname"]) 
        $V .= $this->LicenseList($_POST["req_shortname"], $_POST["req_marydone"]);
	  break;
    default:
	  break;
  }

    if (!$this->OutputToStdout) { return($V); }
    print($V);
    return;
} // Output()


/************************************************
 FamilyNames()
  Return an array of family names based on the
  license_ref.shortname.
  A family name is the name before most punctuation.
  For example, the family name of "GPL V2" is "GPL"
 ************************************************/
function FamilyNames()
{
  $familynamearray = array();
  $Shortnamearray = DB2KeyValArray("license_ref", "rf_pk", "rf_shortname", " order by rf_shortname");
  
  // truncate each name to the family name
  foreach ($Shortnamearray as $shortname)
  {
    // start with exceptions
    if (($shortname == "No License Found")
        || ($shortname == "Unknown license"))
    {
      $familynamearray[$shortname] = $shortname;
    }
    else
    {
      $tok = strtok($shortname, " _-([/");
      $familynamearray[$tok] = $tok;
    }
  }

  return ($familynamearray);
}


};

$NewPlugin = new admin_license_file;
?>
