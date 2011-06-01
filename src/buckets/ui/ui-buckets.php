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
if (!isset($GlobalReady)) { exit; }

define("TITLE_ui_buckets", _("Bucket Browser"));

class ui_buckets extends FO_Plugin
{
  var $Name       = "bucketbrowser";
  var $Title      = TITLE_ui_buckets;
  var $Version    = "1.0";
  var $Dependency = array("db","browse","view");
  var $DBaccess   = PLUGIN_DB_READ;
  var $LoginFlag  = 0;
  var $UpdCache   = 0;

  /***********************************************************
   Install(): Create and configure database tables
   ***********************************************************/
  function Install()
  {
    global $DB;
    global $PG_CONN;

    if (empty($DB)) { return(1); } /* No DB */

    /* If there are no bucket pools defined, 
     * then create a simple demo.
     * Note: that the bucketpool and two simple bucket definitions
     * are created but no user default bucket pools are set.
     * We don't want to automatically set this to be the 
     * default bucket pool because this may not be appropiate for 
     * the installation.  The user or system administrator will 
     * have to set the default bucket pool in their account settings.
     */

    /* Check if there is already a bucket pool, if there is 
     * then return because there is nothing to do.
     */
    $sql = "SELECT bucketpool_pk  FROM bucketpool limit 1";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    if (pg_num_rows($result) > 0) return;

    /* none exist so create the demo */
    $DemoPoolName = "GPL Demo bucket pool";
    $sql = "INSERT INTO bucketpool (bucketpool_name, version, active, description) VALUES ('$DemoPoolName', 1, 'Y', 'Demonstration of a very simple GPL/non-gpl bucket pool')";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);

    /* get the bucketpool_pk of the newly inserted record */
    $sql = "select bucketpool_pk from bucketpool 
              where bucketpool_name='$DemoPoolName' limit 1";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    $bucketpool_pk = $row['bucketpool_pk'];
    pg_free_result($result);

    /* Insert the bucket_def records */
    $sql = "INSERT INTO bucketpool (bucketpool_name, version, active, description) VALUES ('$DemoPoolName', 1, 'Y', 'Demonstration of a very simple GPL/non-gpl bucket pool')";
    $Columns = "bucket_name, bucket_color, bucket_reportorder, bucket_evalorder, bucketpool_fk, bucket_type, bucket_regex, stopon, applies_to";
    $sql = "INSERT INTO bucket_def ($Columns) VALUES ('GPL Licenses (Demo)', 'orange', 50, 50, $bucketpool_pk, 3, '(affero|gpl)', 'N', 'f');
            INSERT INTO bucket_def ($Columns) VALUES ('non-gpl (Demo)', 'yellow', 50, 1000, $bucketpool_pk, 99, NULL, 'N', 'f')";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);

    return(0);
  } // Install()

  /***********************************************************
   RegisterMenus(): Customize submenus.
   ***********************************************************/
  function RegisterMenus()
  {
    // For all other menus, permit coming back here.
    $URI = $this->Name . Traceback_parm_keep(array("show","format","page","upload","item","bp"));
    $Item = GetParm("item",PARM_INTEGER);
    $Upload = GetParm("upload",PARM_INTEGER);
    $bucketpool_pk = GetParm("bp",PARM_INTEGER);
    if (!empty($Item) && !empty($Upload))
    {
      if (GetParm("mod",PARM_STRING) == $this->Name)
      {
       menu_insert("Browse::Bucket Browser",1);
       //menu_insert("Browse::[BREAK]",100);
$text = _("Clear");
       //menu_insert("Browse::Clear",101,NULL,NULL,NULL,"<a href='javascript:LicColor(\"\",\"\",\"\",\"\");'>$text</a>");
      }
      else
      {
$text = _("Browse by buckets (categories)");
       menu_insert("Browse::Bucket Browser",10,$URI,$text);
      }
    }
  } // RegisterMenus()


  /***********************************************************
   Initialize(): This is called before the plugin is used.
   It should assume that Install() was already run one time
   (possibly years ago and not during this object's creation).
   Returns true on success, false on failure.
   A failed initialize is not used by the system.
   NOTE: This function must NOT assume that other plugins are installed.
   ***********************************************************/
  function Initialize()
  {
    global $_GET;

    if ($this->State != PLUGIN_STATE_INVALID) { return(1); } // don't re-run
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


  /***********************************************************
   ShowUploadHist(): Given an $Uploadtree_pk, display:
   (1) The histogram for the directory BY bucket.
   (2) The file listing for the directory.
   ***********************************************************/
  function ShowUploadHist($Uploadtree_pk,$Uri)
  {
    global $PG_CONN;

    $VF=""; // return values for file listing
    $VLic=""; // return values for output
    $V=""; // total return value
    global $Plugins;
    global $DB;

    $ModLicView = &$Plugins[plugin_find_id("view-license")];

    /*******  Get Bucket names and counts  ******/
    /* Find lft and rgt bounds for this $Uploadtree_pk  */
    $sql = "SELECT lft,rgt,upload_fk FROM uploadtree 
              WHERE uploadtree_pk = $Uploadtree_pk";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    if (pg_num_rows($result) < 1)
    {
      pg_free_result($result);
$text = _("Invalid URL, nonexistant item");
      return "<h2>$text $Uploadtree_pk</h2>";
    }
    $row = pg_fetch_assoc($result);
    $lft = $row["lft"];
    $rgt = $row["rgt"];
    $upload_pk = $row["upload_fk"];
    pg_free_result($result);

    /* Get the ars_pk of the scan to display, also the select list  */
    $ars_pk = GetArrayVal("ars", $_GET);
    $BucketSelect = SelectBucketDataset($upload_pk, $ars_pk, "selectbdata", 
                                        "onchange=\"addArsGo('newds','selectbdata');\"");
    if ($ars_pk == 0) 
    {
      /* No bucket data for this upload */
      return $BucketSelect;
    }
    
    /* Get scan keys */
    $sql = "select agent_fk, nomosagent_fk, bucketpool_fk from bucket_ars where ars_pk=$ars_pk";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    $bucketagent_pk = $row["agent_fk"];
    $nomosagent_pk = $row["nomosagent_fk"];
    $bucketpool_pk = $row["bucketpool_fk"];
    pg_free_result($result);

    /* Create bucketDefArray as individual query this is MUCH faster
       than incorporating it with a join in the following queries.
     */
    $bucketDefArray = initBucketDefArray($bucketpool_pk);

    /*select all the buckets for entire tree for this bucketpool */
    $sql = "SELECT distinct(bucket_fk) as bucket_pk, 
                   count(bucket_fk) as bucketcount, bucket_reportorder
              from bucket_file, bucket_def,
                  (SELECT distinct(pfile_fk) as PF from uploadtree 
                     where upload_fk=$upload_pk 
                       and ((ufile_mode & (1<<28))=0)
                       and uploadtree.lft BETWEEN $lft and $rgt) as SS
              where PF=pfile_fk and agent_fk=$bucketagent_pk 
                    and bucket_file.nomosagent_fk=$nomosagent_pk
                    and bucket_pk=bucket_fk
                    and bucketpool_fk=$bucketpool_pk
              group by bucket_fk,bucket_reportorder
              order by bucket_reportorder asc"; 
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      $historows = pg_fetch_all($result);
      pg_free_result($result);

    /* Show dataset list */
    if (!empty($BucketSelect))
    {
      $action = Traceback_uri() . "?mod=bucketbrowser&upload=$upload_pk&item=$Uploadtree_pk";

      $VLic .= "<script type='text/javascript'>
function addArsGo(formid, selectid ) 
{
var selectobj = document.getElementById(selectid);
var ars_pk = selectobj.options[selectobj.selectedIndex].value;
document.getElementById(formid).action='$action'+'&ars='+ars_pk;
document.getElementById(formid).submit();
return;
}
</script>";

      /* form to select new dataset (ars_pk) */
      $VLic .= "<form action='$action' id='newds' method='POST'>\n";
      $VLic .= $BucketSelect;
      $VLic .= "</form>";
    }

    $sql = "select bucketpool_name from bucketpool where bucketpool_pk=$bucketpool_pk";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    $bucketpool_name = $row['bucketpool_name'];
    pg_free_result($result);

    /* Write bucket histogram to $VLic  */
    $bucketcount = 0;
    $Uniquebucketcount = 0;
    $NoLicFound = 0;
    if (is_array($historows))
    {
$text = _("Bucket Pool");
      $VLic .= "$text: $bucketpool_name<br>";
      $VLic .= "<table border=1 width='100%'>\n";
$text = _("Count");
      $VLic .= "<tr><th width='10%'>$text</th>";
$text = _("Files");
      $VLic .= "<th width='10%'>$text</th>";
$text = _("Bucket");
      $VLic .= "<th align='left'>$text</th></tr>\n";
  
      foreach($historows as $bucketrow)
      {
        $Uniquebucketcount++;
        $bucket_pk = $bucketrow['bucket_pk'];
        $bucketcount = $bucketrow['bucketcount'];
        $bucket_name = $bucketDefArray[$bucket_pk]['bucket_name'];
        $bucket_color = $bucketDefArray[$bucket_pk]['bucket_color'];
  
        /*  Count  */
        $VLic .= "<tr><td align='right' style='background-color:$bucket_color'>$bucketcount</td>";

        /*  Show  */
        $VLic .= "<td align='center'><a href='";
        $VLic .= Traceback_uri();
$text = _("Show");
        $VLic .= "?mod=list_bucket_files&bapk=$bucketagent_pk&item=$Uploadtree_pk&bpk=$bucket_pk&bp=$bucketpool_pk&napk=$nomosagent_pk" . "'>$text</a></td>";

        /*  Bucket name  */
        $VLic .= "<td align='left'>";
        $VLic .= "<a id='$bucket_pk' onclick='FileColor_Get(\"" . Traceback_uri() . "?mod=ajax_filebucket&bapk=$bucketagent_pk&item=$Uploadtree_pk&bucket_pk=$bucket_pk\")'";
        $VLic .= ">$bucket_name </a>";
        $VLic .= "</td>";
        $VLic .= "</tr>\n";
//      if ($row['bucket_name'] == "No Buckets Found") $NoLicFound =  $row['bucketcount'];
      }
      $VLic .= "</table>\n";
      $VLic .= "<p>\n";
$text = _("Unique buckets");
      $VLic .= "$text: $Uniquebucketcount<br>\n";
    }


    /*******    File Listing     ************/
    /* Get ALL the items under this Uploadtree_pk */
    $Children = GetNonArtifactChildren($Uploadtree_pk);

    if (count($Children) == 0)
    {
      $Results = $DB->Action("SELECT * FROM uploadtree WHERE uploadtree_pk = '$Uploadtree_pk'");
      if (empty($Results) || (IsDir($Results[0]['ufile_mode']))) { return; }
      $ModLicView = &$Plugins[plugin_find_id("view-license")];
      return($ModLicView->Output() );
    }
    $ChildCount=0;
    $Childbucketcount=0;

/* Countd disabled until we know we need them
    $NumSrcPackages = 0;
    $NumBinPackages = 0;
    $NumBinNoSrcPackages = 0;
*/

    /* get mimetypes for packages */
    $MimetypeArray = GetPkgMimetypes(); 

    $VF .= "<table border=0>";
    foreach($Children as $C)
    {
      if (empty($C)) { continue; }

      /* update package counts */
/* This is an expensive count.  Comment out until we know we really need it
      IncrSrcBinCounts($C, $MimetypeArray, $NumSrcPackages, $NumBinPackages, $NumBinNoSrcPackages);
*/

      $IsDir = Isdir($C['ufile_mode']);
      $IsContainer = Iscontainer($C['ufile_mode']);

      /* Determine the hyperlink for non-containers to view-license  */
      if (!empty($C['pfile_fk']) && !empty($ModLicView))
      {
        $LinkUri = Traceback_uri();
        $LinkUri .= "?mod=view-license&napk=$nomosagent_pk&bapk=$bucketagent_pk&upload=$upload_pk&item=$C[uploadtree_pk]";
      }
      else
      {
        $LinkUri = NULL;
      }

      /* Determine link for containers */
      if (Iscontainer($C['ufile_mode']))
      {
        $uploadtree_pk = DirGetNonArtifact($C['uploadtree_pk']);
        $tmpuri = "?mod=" . $this->Name . Traceback_parm_keep(array("upload","folder","ars"));
        $LicUri = "$tmpuri&item=" . $uploadtree_pk;
      }
      else
      {
        $LicUri = NULL;
      }

      /* Populate the output ($VF) - file list */
      /* id of each element is its uploadtree_pk */
      $VF .= "<tr><td id='$C[uploadtree_pk]' align='left'>";
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
      $VF .= $C['ufile_name'];
      if ($IsDir) { $VF .= "/"; };
      if ($HasBold) { $VF .= "</b>"; }
      if ($HasHref) { $VF .= "</a>"; }

      /* print buckets */
      $VF .= "<br>";
      $VF .= "<span style='position:relative;left:1em'>";
      /* get color coded string of bucket names */
      $VF .= GetFileBuckets_string($nomosagent_pk, $bucketagent_pk, $C['uploadtree_pk'],
                 $bucketDefArray, ",", True);
      $VF .= "</span>";
      $VF .= "</td><td valign='top'>";

      /* display file links if this is really a file */
      if (!empty($C['pfile_fk']))
        $VF .= FileListLinks($C['upload_fk'], $C['uploadtree_pk'], $nomosagent_pk);
      $VF .= "</td>";
      $VF .= "</tr>\n";

      $ChildCount++;
    }
    $VF .= "</table>\n";

    $V .= ActiveHTTPscript("FileColor");

    /* Add javascript for color highlighting 
       This is the response script needed by ActiveHTTPscript 
       responseText is bucket_pk',' followed by a comma seperated list of uploadtree_pk's */
    $script = "
      <script type=\"text/javascript\" charset=\"utf-8\">
        var Lastutpks='';   /* save last list of uploadtree_pk's */
        var Lastbupk='';   /* save last bucket_pk */
        var color = '#4bfe78';
        function FileColor_Reply()
        {
          if ((FileColor.readyState==4) && (FileColor.status==200))
          {
            /* remove previous highlighting */
            var numpks = Lastutpks.length;
            if (numpks > 0) document.getElementById(Lastbupk).style.backgroundColor='white';
            while (numpks)
            {
              document.getElementById(Lastutpks[--numpks]).style.backgroundColor='white';
            }

            utpklist = FileColor.responseText.split(',');
            Lastbupk = utpklist.shift();
            numpks = utpklist.length;
            Lastutpks = utpklist;

            /* apply new highlighting */
            elt = document.getElementById(Lastbupk);
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

    /* Display source, binary, and binary missing source package counts */
/* Counts disabled above until we know we need these
    $VLic .= "<ul>";
$text = _("source packages");
    $VLic .= "<li> $NumSrcPackages $text";
$text = _("binary packages");
    $VLic .= "<li> $NumBinPackages $text";
$text = _("binary packages with no source package");
    $VLic .= "<li> $NumBinNoSrcPackages $text";
    $VLic .= "</ul>";
*/

    /* Combine VF and VLic */
    $V .= "<table border=0 width='100%'>\n";
    $V .= "<tr><td valign='top' width='50%'>$VLic</td><td valign='top'>$VF</td></tr>\n";
    $V .= "</table>\n";
    $V .= "<hr />\n";

    return($V);
  } // ShowUploadHist()

  /***********************************************************
   Output(): This function returns the scheduler status.
   ***********************************************************/
  function Output()
  {
    $uTime = microtime(true);
    if ($this->State != PLUGIN_STATE_READY) { return(0); }
    $V="";
    $Folder = GetParm("folder",PARM_INTEGER);
    $Upload = GetParm("upload",PARM_INTEGER);
    $Item = GetParm("item",PARM_INTEGER);
    $updcache = GetParm("updcache",PARM_INTEGER);
    if ($updcache)
    {
      $this->UpdCache = $_GET['updcache'];
    }
    else
    {
      $this->UpdCache = 0;
    }

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
    $CacheKey = "?mod=" . $this->Name . Traceback_parm_keep(array("upload","item","folder","ars")) . "&show=$Show";
    if ($this->UpdCache != 0)
    {
      $V = "";
      $Err = ReportCachePurgeByKey($CacheKey);
    }
    else
    {
      $V = ReportCacheGet($CacheKey);
    }

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
          $V .= $this->ShowUploadHist($Item,$Uri);
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

    if (!$this->OutputToStdout) { return($V); }
    print "$V";
    $Time = microtime(true) - $uTime;  // convert usecs to secs
$text = _("Elapsed time: %.2f seconds");
    printf( "<p><small>$text</small>", $Time);

    if ($Cached){ 
$text = _("cached");
$text1 = _("Update");
      echo " <i>$text</i>   <a href=\"$_SERVER[REQUEST_URI]&updcache=1\"> $text1 </a>";
    }else
    {
      /*  Cache Report if this took longer than 1/2 second*/
      if ($Time > 0.5) ReportCachePut($CacheKey, $V);
    }

    return;
  }

};

$NewPlugin = new ui_buckets;
$NewPlugin->Initialize();

?>
