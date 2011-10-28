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
/**
 * \file ui-nomos-license.php
 * \brief browse a directory to disply all licenses in this directory
 */

define("TITLE_ui_nomos_license", _("License Browser"));

class ui_nomos_license extends FO_Plugin
{
  var $Name       = "nomoslicense";
  var $Title      = TITLE_ui_nomos_license;
  var $Version    = "1.0";
  // var $MenuList= "Jobs::License";
  var $Dependency = array("db","browse","view");
  var $DBaccess   = PLUGIN_DB_READ;
  var $LoginFlag  = 0;
  var $UpdCache   = 0;
  var $HighlightColor = '#4bfe78';

  /**
   * \brief  Only used during installation.
   * \return 0 on success, non-zero on failure.
   */
  function Install()
  {
    global $PG_CONN;

    if (!$PG_CONN) {
      return(1);
    }

    /* The license "No License Found" was changed to "No_license_found"
     * in v1.4 because one shot depends on license names that are one
    * string (no spaces).  So make sure the users db is updated.
    */
    $sql = "update license_ref set rf_shortname='No_license_found'
               where rf_shortname='No License Found'";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    pg_free_result($result);

    return(0);
  } // Install()

  /**
   * \brief Customize submenus.
   */
  function RegisterMenus()
  {
    // For all other menus, permit coming back here.
    $URI = $this->Name . Traceback_parm_keep(array("show","format","page","upload","item"));

    $Item = GetParm("item",PARM_INTEGER);
    $Upload = GetParm("upload",PARM_INTEGER);
    if (!empty($Item) && !empty($Upload))
    {
      $nomosAgentpk = LatestNomosAgentpk($Upload);
      $nomosURI = "view-license&napk=$nomosAgentpk" . Traceback_parm_keep(array("show","format","page","upload","item"));
      if (GetParm("mod",PARM_STRING) == $this->Name)
      {
        menu_insert("Browse::License Browser",100);
      }
      else
      {
        $text = _("license histogram");
        $MenuName = "License Browser";
        menu_insert("Browse::$MenuName",100,$URI,$text);
        menu_insert("View::$MenuName",100,$nomosURI,$text);
      }
    }
  } // RegisterMenus()


  /**
   * \brief This is called before the plugin is used.
   * It should assume that Install() was already run one time
   * (possibly years ago and not during this object's creation).
   *
   * \return true on success, false on failure.
   * A failed initialize is not used by the system.
   *
   * \note This function must NOT assume that other plugins are installed.
   */
  function Initialize()
  {
    global $_GET;

    if ($this->State != PLUGIN_STATE_INVALID) {
      return(1);
    } // don't re-run
    if ($this->Name !== "") // Name must be defined
    {
      global $Plugins;
      $this->State=PLUGIN_STATE_VALID;
      array_push($Plugins,$this);
    }

    /* Remove "updcache" from the GET args and set $this->UpdCache
     * This way all the url's based on the input args won't be
    * polluted with updcache
    */
    if ($_GET['updcache'])
    {
      $this->UpdCache = $_GET['updcache'];
      $_SERVER['REQUEST_URI'] = preg_replace("/&updcache=[0-9]*/","",$_SERVER['REQUEST_URI']);
      unset($_GET['updcache']);
    }
    else
    {
      $this->UpdCache = 0;
    }
    return($this->State == PLUGIN_STATE_VALID);
  } // Initialize()


  /**
   * \brief Given an $Uploadtree_pk, display: 
   *   - The histogram for the directory BY LICENSE.
   *   - The file listing for the directory.
   */
  function ShowUploadHist($Uploadtree_pk,$Uri, $tag_pk)
  {
    global $PG_CONN;

    $VF=""; // return values for file listing
    $VLic=""; // return values for license histogram
    $V=""; // total return value
    $UniqueTagArray = array();
    global $Plugins;

    $ModLicView = &$Plugins[plugin_find_id("view-license")];

    /*******  Get license names and counts  ******/
    /* Find lft and rgt bounds for this $Uploadtree_pk  */
    $sql = "SELECT lft,rgt,upload_fk FROM uploadtree
              WHERE uploadtree_pk = $Uploadtree_pk";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    $lft = $row["lft"];
    $rgt = $row["rgt"];
    $upload_pk = $row["upload_fk"];
    pg_free_result($result);

    /* Find total number of files for this $Uploadtree_pk
     * Exclude artifacts and directories.
    */
    $sql = "SELECT count(*) as count FROM uploadtree
              WHERE upload_fk = $upload_pk 
                    and uploadtree.lft BETWEEN $lft and $rgt
                    and ((ufile_mode & (1<<28))=0) 
                    and ((ufile_mode & (1<<29))=0) and pfile_fk!=0";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    $FileCount = $row["count"];
    pg_free_result($result);

    $Agent_pk = LatestNomosAgentpk($upload_pk);
    if ($Agent_pk == 0)
    {
      $text = _("No data available.  Use Jobs > Agents to schedule a license scan.");
      $VLic = "<b>$text</b><p>";
      return $VLic;
    }

    /*  Get the counts for each license under this UploadtreePk*/
    if (empty($tag_pk))
    {
      $TagTable = "";
      $TagClause = "";
    }
    else
    {
      $TagTable = "tag_file,";
      $TagClause = "and PF=tag_file.pfile_fk and tag_fk=$tag_pk";
    }
    $sql = "SELECT distinct(rf_shortname) as licname,
                   count(rf_shortname) as liccount, rf_shortname
              from license_ref,license_file, $TagTable
                  (SELECT distinct(pfile_fk) as PF from uploadtree 
                     where upload_fk=$upload_pk 
                       and uploadtree.lft BETWEEN $lft and $rgt) as SS
              where PF=license_file.pfile_fk and agent_fk=$Agent_pk and rf_fk=rf_pk
                   $TagClause
              group by rf_shortname 
              order by liccount desc";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);

    /* Get agent list */
    $VLic .= "<form action='" . Traceback_uri()."?" . $_SERVER["QUERY_STRING"] . "' method='POST'>\n";

    /*
     FUTURE advanced interface allowing user to select dataset (agent version)
    $AgentSelect = AgentSelect($Agent_name, $upload_pk, "license_file", true, "agent_pk", $Agent_pk);
    $VLic .= $AgentSelect;
    $VLic .= "<input type='submit' value='Go'>";
    */

    /* Write license histogram to $VLic  */
    $LicCount = 0;
    $UniqueLicCount = 0;
    $NoLicFound = 0;
    $VLic .= "<table border=1 width='100%' id='lichistogram'>\n";
    $text = _("Count");
    $VLic .= "<tr><th width='10%'>$text</th>";
    $text = _("Files");
    $VLic .= "<th width='10%'>$text</th>";
    $text = _("License Name");
    $VLic .= "<th align=left>$text</th></tr>\n";

    while ($row = pg_fetch_assoc($result))
    {
      $UniqueLicCount++;
      $LicCount += $row['liccount'];

      /*  Count  */
      $VLic .= "<tr><td align='right'>$row[liccount]</td>";

      /*  Show  */
      $VLic .= "<td align='center'><a href='";
      $VLic .= Traceback_uri();
      $text = _("Show");
      $VLic .= "?mod=list_lic_files&napk=$Agent_pk&item=$Uploadtree_pk&lic=" . urlencode($row['rf_shortname']) . "'>$text</a></td>";

      /*  License name  */
      $VLic .= "<td align='left'>";
      $rf_shortname = rawurlencode($row['rf_shortname']);
      $VLic .= "<a id='$rf_shortname' onclick='FileColor_Get(\"" . Traceback_uri() . "?mod=ajax_filelic&napk=$Agent_pk&item=$Uploadtree_pk&lic=$rf_shortname\")'";
      $VLic .= ">$row[licname] </a>";
      $VLic .= "</td>";
      $VLic .= "</tr>\n";
      if ($row['licname'] == "No_license_found") $NoLicFound =  $row['liccount'];
    }
    $VLic .= "</table>\n";
    $VLic .= "<p>\n";
    $VLic .= _("Hint: Click on the license name to ");
    $text = _("highlight");
    $VLic .= "<span style='background-color:$this->HighlightColor'>$text </span>";
    $VLic .= _("where the license is found in the file listing.<br>\n");
    $VLic .= "<table border=0 id='licsummary'>";
    $text = _("Unique licenses");
    $VLic .= "<tr><td align=right>$UniqueLicCount</td><td>$text</td></tr>";
    $NetLic = $LicCount - $NoLicFound;
    $text = _("Licenses found");
    $VLic .= "<tr><td align=right>$NetLic</td><td>$text</td></tr>";
    $text = _("Files with no licenses");
    $VLic .= "<tr><td align=right>$NoLicFound</td><td>$text</td></tr>";
    $text = _("Files");
    $VLic .= "<tr><td align=right>$FileCount</td><td>$text</td></tr>";
    $VLic .= "</table>";
    pg_free_result($result);


    /*******    File Listing     ************/
    /* Get ALL the items under this Uploadtree_pk */
    $Children = GetNonArtifactChildren($Uploadtree_pk);

    /* Filter out Children that don't have tag */
    if (!empty($tag_pk)) TagFilter($Children, $tag_pk);

    $ChildCount=0;
    $ChildLicCount=0;

    if (!empty($Children))
    {
      /* For alternating row background colors */
      $RowStyle1 = "style='background-color:#ecfaff'";  // pale blue
      $RowStyle2 = "style='background-color:#ffffe3'";  // pale yellow
      $ColorSpanRows = 1;  // Alternate background color every $ColorSpanRows
      $RowNum = 0;

      $VF .= "<table border=0 id='dirlist'>";
      foreach($Children as $C)
      {
        if (empty($C)) {
          continue;
        }

        $IsDir = Isdir($C['ufile_mode']);
        $IsContainer = Iscontainer($C['ufile_mode']);

        /* Determine the hyperlink for non-containers to view-license  */
        if (!empty($C['pfile_fk']) && !empty($ModLicView))
        {
          $LinkUri = Traceback_uri();
          $LinkUri .= "?mod=view-license&napk=$Agent_pk&upload=$upload_pk&item=$C[uploadtree_pk]";
        }
        else
        {
          $LinkUri = NULL;
        }

        /* Determine link for containers */
        if (Iscontainer($C['ufile_mode']))
        {
          $uploadtree_pk = DirGetNonArtifact($C['uploadtree_pk']);
          $LicUri = "$Uri&item=" . $uploadtree_pk;
        }
        else
        {
          $LicUri = NULL;
        }

        /* Populate the output ($VF) - file list */
        /* id of each element is its uploadtree_pk */

        /* Set alternating row background color - repeats every $ColorSpanRows rows */
        $RowStyle = (($RowNum++ % (2*$ColorSpanRows))<$ColorSpanRows) ? $RowStyle1 : $RowStyle2;
        $VF .= "<tr $RowStyle>";

        $VF .= "<td id='$C[uploadtree_pk]' align='left'>";
        $HasHref=0;
        $HasBold=0;
        if ($IsContainer)
        {
          $VF .= "<a href='$LicUri'>"; $HasHref=1;
          $VF .= "<b>"; $HasBold=1;
        }
        else if (!empty($LinkUri))
        {
          $VF .= "<a href='$LinkUri'>"; $HasHref=1;
        }
        $VF .= "<b>" . $C['ufile_name'] . "</b>";
        if ($IsDir) {
          $VF .= "/";
        };
        if ($HasBold) {
          $VF .= "</b>";
        }
        if ($HasHref) {
          $VF .= "</a>";
        }

        /* show licenses under file name */
        $VF .= "<br>";
        $VF .= GetFileLicenses_string($Agent_pk, $C['pfile_fk'], $C['uploadtree_pk']);
        $VF .= "</td><td valign='top'>";

        /* display file links */
        $VF .= FileListLinks($C['upload_fk'], $C['uploadtree_pk'], $Agent_pk, $C['pfile_fk'], true, $UniqueTagArray);
        $VF .= "</td>";
        $VF .= "</tr>\n";

        $ChildCount++;
      }
      $VF .= "</table>\n";
    }

    /***************************************
     Problem: $ChildCount can be zero!
    This happens if you have a container that does not
    unpack to a directory.  For example:
    file.gz extracts to archive.txt that contains a license.
    Same problem seen with .pdf and .Z files.
    Solution: if $ChildCount == 0, then just view the license!

    $ChildCount can also be zero if the directory is empty.
    ***************************************/
    if ($ChildCount == 0)
    {
      $sql = "SELECT * FROM uploadtree WHERE uploadtree_pk = '$Uploadtree_pk';";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      $row = pg_fetch_assoc($result);
      pg_free_result($result);
      if (IsDir($row['ufile_mode'])) {
        return;
      }
      $ModLicView = &$Plugins[plugin_find_id("view-license")];
      return($ModLicView->Output() );
    }

    $V .= ActiveHTTPscript("FileColor");

    /* Add javascript for color highlighting
     This is the response script needed by ActiveHTTPscript
    responseText is license name',' followed by a comma seperated list of uploadtree_pk's */
    $script = "
      <script type=\"text/javascript\" charset=\"utf-8\">
        var Lastutpks='';   /* save last list of uploadtree_pk's */
        var LastLic='';   /* save last License (short) name */
        var color = '$this->HighlightColor';
        function FileColor_Reply()
        {
          if ((FileColor.readyState==4) && (FileColor.status==200))
          {
            /* remove previous highlighting */
            var numpks = Lastutpks.length;
            if (numpks > 0) document.getElementById(LastLic).style.backgroundColor='white';
            while (numpks)
            {
              document.getElementById(Lastutpks[--numpks]).style.backgroundColor='white';
            }

            utpklist = FileColor.responseText.split(',');
            LastLic = utpklist.shift();
            numpks = utpklist.length;
            Lastutpks = utpklist;

            /* apply new highlighting */
            elt = document.getElementById(LastLic);
            if (elt != null) elt.style.backgroundColor=color;
            while (numpks)
            {
              document.getElementById(utpklist[--numpks]).style.backgroundColor=color;
            }
          }
          return;
        }
      </script>
    ";
    $V .= $script;

    /******  Filters  *******/
    /* Only display the filter pulldown if there are filters available 
     * Currently, this is only tags.
     */
    /** @todo qualify with tag namespace to avoid tag name collisions.  **/
    /* turn $UniqueTagArray into key value pairs ($SelectData) for select list */
    $SelectData = array();  
    if (count($UniqueTagArray))
    {
      foreach ($UniqueTagArray as $UTA_row) $SelectData[$UTA_row['tag_pk']] = $UTA_row['tag_name'];
      $V .= "Tag filter";
      $myurl = "?mod=" . $this->Name . Traceback_parm_keep(array("upload","item","folder"));
      $Options = " id='filterselect' onchange=\"js_url(this.value, '$myurl&tag=')\"";
      $V .= Array2SingleSelectTag($SelectData, "tag_ns_pk",$tag_pk,true,false, $Options);
    }


    /****** Combine VF and VLic ********/
    $V .= "<table border=0 width='100%'>\n";
    $V .= "<tr><td valign='top' >$VLic</td><td valign='top'>$VF</td></tr>\n";
    $V .= "</table>\n";
    $V .= "<hr />\n";

    return($V);
  } // ShowUploadHist()

  /**
   * \brief This function returns the scheduler status.
   */
  function Output()
  {
    $uTime = microtime(true);
    if ($this->State != PLUGIN_STATE_READY) {
      return(0);
    }
    $V="";
    $Folder = GetParm("folder",PARM_INTEGER);
    $Upload = GetParm("upload",PARM_INTEGER);
    $Item = GetParm("item",PARM_INTEGER);
    $tag_pk = GetParm("tag",PARM_INTEGER);
    if (!empty($updcache))
    $this->UpdCache = $_GET['updcache'];
    else
    $this->UpdCache = 0;

    switch(GetParm("show",PARM_STRING))
    {
      case 'detail':
        $Show='detail';
        break;
      case 'summary':
      default:
        $Show='summary';
    }

    /* Use Traceback_parm_keep to ensure that all parameters are in order */
    $CacheKey = "?mod=" . $this->Name . Traceback_parm_keep(array("upload","item","folder","tag")) . "&show=$Show";
    if ($this->UpdCache != 0)
    {
      $V = "";
      $Err = ReportCachePurgeByKey($CacheKey);
    }
    else
    $V = ReportCacheGet($CacheKey);

    if (empty($V) )  // no cache exists
    {
      switch($this->OutputType)
      {
        case "XML":
          break;
        case "HTML":
          $V .= "<font class='text'>\n";

          /************************/
          /* Show the folder path */
          /************************/
          $V .= Dir2Browse($this->Name,$Item,NULL,1,"Browse") . "<P />\n";

          if (!empty($Upload))
          {
            $Uri = preg_replace("/&item=([0-9]*)/","",Traceback());
            $V .= js_url();
            $V .= $this->ShowUploadHist($Item,$Uri, $tag_pk);
          }
          $V .= "</font>\n";
          $text = _("Loading...");
          /*$V .= "<div id='ajax_waiting'><img src='images/ajax-loader.gif'>$text</div>"; */
          break;
        case "Text":
          break;
        default:
      }

      $Cached = false;
    }
    else
    $Cached = true;

    if (!$this->OutputToStdout) {
      return($V);
    }
    print "$V";
    $Time = microtime(true) - $uTime;  // convert usecs to secs
    $text = _("Elapsed time: %.2f seconds");
    printf( "<small>$text</small>", $Time);

    if ($Cached)
    {
      $text = _("cached");
      $text1 = _("Update");
      echo " <i>$text</i>   <a href=\"$_SERVER[REQUEST_URI]&updcache=1\"> $text1 </a>";
    }
    else
    {
      /*  Cache Report if this took longer than 1/2 second*/
      if ($Time > 0.5) ReportCachePut($CacheKey, $V);
    }
    return;
  }

};

$NewPlugin = new ui_nomos_license;
$NewPlugin->Initialize();

?>
