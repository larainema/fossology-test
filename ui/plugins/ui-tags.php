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

define("TITLE_ui_tag", _("Tag"));

class ui_tag extends FO_Plugin
  {
  var $Name       = "tag";
  var $Title      = TITLE_ui_tag;
  var $Version    = "1.0";

  /***********************************************************
   RegisterMenus(): Customize submenus.
   ***********************************************************/
  function RegisterMenus()
    {
/****** Permission comments: if user don't have read or high permission, can't see tag menu. ******/
    if (!empty($_SESSION['UserId'])) {
      $perm = GetTaggingPerms($_SESSION['UserId'],NULL);
      //print ($perm);
      if ($perm > 0){
        $text = _("Tag files or containers");
        menu_insert("Browse-Pfile::Tag",0,$this->Name,$text);
      } else {
        return(0);
      }
    }
    } // RegisterMenus()

  /***********************************************************
   CreateTag(): Add a new Tag.
   ***********************************************************/
  function CreateTag()
  {
    global $PG_CONN; 

    $Upload = GetParm("upload",PARM_INTEGER);
    $Item = GetParm("item",PARM_INTEGER);

    if (empty($Item) || empty($Upload))
        { return; }

    $tag_ns_pk = GetParm('tag_ns_pk', PARM_INTEGER);
    $tag_name = GetParm('tag_name', PARM_TEXT);
    $tag_notes = GetParm('tag_notes', PARM_TEXT);
    $tag_file = GetParm('tag_file', PARM_TEXT);
    $tag_package = GetParm('tag_package', PARM_TEXT);
    $tag_container = GetParm('tag_container', PARM_TEXT);
    $tag_desc = GetParm('tag_desc', PARM_TEXT);

    /* Debug
    print "<pre>";
    print "Create Tag: TagNameSpace is:$tag_ns_pk\n";
    print "Create Tag: TagName is:$tag_name\n";
    print "Create Tag: TagNotes is:$tag_notes\n";
    print "Create Tag: TagFile is:$tag_file\n";
    print "Create Tag: TagPackage is:$tag_package\n";
    print "Create Tag: TagContainer is:$tag_container\n";
    print "Upload: $Upload\n";
    print "Item: $Item\n";
    print "</pre>";
    */

    if (empty($tag_name))
    {
      $text = _("TagName must be specified. Tag Not created.");
      return ($text);
    }
    /* Need select tag file/package/container */
    if (empty($tag_file) && empty($tag_package) && empty($tag_container))
    {
      $text = _("Need to select one option (file/package/container) to create tag.");
      return ($text);
    }
    
    pg_exec("BEGIN;");

    /* See if the tag already exists */
    $sql = "SELECT * FROM tag WHERE tag = '$tag_name' AND tag_ns_fk = '$tag_ns_pk';";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    if (pg_num_rows($result) < 1)
    {
      pg_free_result($result);

      $Val = str_replace("'", "''", $tag_name);
      $Val1 = str_replace("'", "''", $tag_desc);
      $sql = "INSERT INTO tag (tag,tag_ns_fk,tag_desc) VALUES ('$Val', $tag_ns_pk, '$Val1');";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      pg_free_result($result);
    }else{
      $text = _("TagName already exists. Tag Not created.");
      return ($text);
    }

    /* Make sure it was added */
    $sql = "SELECT * FROM tag WHERE tag = '$tag_name' AND tag_ns_fk = $tag_ns_pk LIMIT 1;";
    $result = pg_query($PG_CONN, $sql);
    if (pg_num_rows($result) < 1) 
    {
      pg_free_result($result);
      $text = _("Failed to create tag.");
      return ($text);
    }
    
    $row = pg_fetch_assoc($result);
    $tag_pk = $row["tag_pk"];
    pg_free_result($result);

    $pfileArray = array();
    $i = 0;

    if (!empty($tag_file))
    {
      /* Get pfile_fk from uploadtree_pk */
      $sql = "SELECT pfile_fk FROM uploadtree
              WHERE uploadtree_pk = $Item LIMIT 1";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      while ($row = pg_fetch_assoc($result))
      {
        $pfileArray[$i] = $row['pfile_fk'];
        $i++;
      }
      pg_free_result($result);
    } 

    if (!empty($tag_package))
    {
      /* GetPkgMimetypes */
      $MimetypeArray = GetPkgMimetypes();
      $sql = "SELECT distinct pfile.pfile_pk FROM uploadtree, pfile WHERE uploadtree.pfile_fk = pfile.pfile_pk AND (pfile.pfile_mimetypefk = $MimetypeArray[0] OR pfile.pfile_mimetypefk = $MimetypeArray[1] OR pfile.pfile_mimetypefk = $MimetypeArray[2]) AND uploadtree.upload_fk = $Upload AND uploadtree.lft >= (SELECT lft FROM uploadtree WHERE uploadtree_pk = $Item) AND uploadtree.rgt <= (SELECT rgt FROM uploadtree WHERE uploadtree_pk = $Item);";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);      
      while ($row = pg_fetch_assoc($result))
      {
        $pfileArray[$i] = $row['pfile_pk'];
        $i++;
      }
      pg_free_result($result); 
    }
    if (!empty($tag_container))
    {
      $sql = "SELECT distinct pfile_fk FROM uploadtree WHERE upload_fk = $Upload AND lft >= (SELECT lft FROM uploadtree WHERE uploadtree_pk = $Item) AND rgt <= (SELECT rgt FROM uploadtree WHERE uploadtree_pk = $Item) AND ((ufile_mode & (1<<28))=0) AND pfile_fk!=0;";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      while ($row = pg_fetch_assoc($result))
      {
        $pfileArray[$i] = $row['pfile_fk'];
        $i++;
      }
      pg_free_result($result);
    }
    
    //echo sizeof($pfileArray);

    foreach($pfileArray as $pfile)
    {
      $sql = "SELECT tag_file_pk FROM tag_file WHERE tag_fk = $tag_pk AND pfile_fk = $pfile;";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      if (pg_num_rows($result) < 1)
      {
        pg_free_result($result);
        /* Add record to tag_file table */
        $Val = str_replace("'", "''", $tag_notes);
        $sql = "INSERT INTO tag_file (tag_fk,pfile_fk,tag_file_date,tag_file_text) VALUES ($tag_pk, $pfile, now(), '$Val');";
        $result = pg_query($PG_CONN, $sql);
        DBCheckResult($result, $sql, __FILE__, __LINE__);
        pg_free_result($result);
      }
    }
    pg_exec("COMMIT;");
    return (NULL);
  }
  
  /***********************************************************
   EditTag(): Edit exsit Tag.
   ***********************************************************/
  function EditTag()
  {
    global $PG_CONN;

    $Upload = GetParm("upload",PARM_INTEGER);
    $Item = GetParm("item",PARM_INTEGER);

    if (empty($Item) || empty($Upload))
        { return; }

    $tag_pk = GetParm('tag_pk', PARM_INTEGER);
    $tag_file_pk = GetParm('tag_file_pk', PARM_INTEGER);
    $tag_ns_pk = GetParm('tag_ns_pk', PARM_INTEGER);
    $tag_name = GetParm('tag_name', PARM_TEXT);
    $tag_notes = GetParm('tag_notes', PARM_TEXT);
    $tag_file = GetParm('tag_file', PARM_TEXT);
    $tag_package = GetParm('tag_package', PARM_TEXT);
    $tag_container = GetParm('tag_container', PARM_TEXT);
    $tag_desc = GetParm('tag_desc', PARM_TEXT);

    /* Debug
    print "<pre>";
    print "Edit Tag: TagNameSpace is:$tag_ns_pk\n";
    print "Edit Tag: TagName is:$tag_name\n";
    print "Edit Tag: TagNotes is:$tag_notes\n";
    print "Edit Tag: TagFile is:$tag_file\n";
    print "Edit Tag: TagPackage is:$tag_package\n";
    print "Edit Tag: TagContainer is:$tag_container\n";
    print "Upload: $Upload\n";
    print "Item: $Item\n";
    print "</pre>";*/

    if (empty($tag_name))
    {
      $text = _("TagName must be specified. Tag Not Updated.");
      return ($text);
    }

    pg_exec("BEGIN;");
    /* Update the tag table */
    $Val = str_replace("'", "''", $tag_name);
    $Val1 = str_replace("'", "''", $tag_desc);
    $sql = "UPDATE tag SET tag = '$Val', tag_ns_fk = $tag_ns_pk, tag_desc = '$Val1' WHERE tag_pk = $tag_pk;";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    pg_free_result($result);

    $Val = str_replace("'", "''", $tag_notes);
    $sql = "UPDATE tag_file SET tag_file_date = now(), tag_file_text = '$Val' WHERE tag_file_pk = $tag_file_pk;";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    pg_free_result($result);

    pg_exec("COMMIT;");
    return (NULL);
  }

  /***********************************************************
   DeleteTag(): Delete exsit Tag.
   ***********************************************************/
  function DeleteTag()
  {
    global $PG_CONN;

    $tag_file_pk = GetParm('tag_file_pk', PARM_INTEGER);

    $sql = "SELECT tag_fk FROM tag_file WHERE tag_file_pk=$tag_file_pk;";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    if (pg_num_rows($result) < 1)
    {
      pg_free_result($result);
      $text = _("Can't find this tag, Tag Not deleted.");
      return ($text);
    }
    $row = pg_fetch_assoc($result);
    $tag_pk = $row['tag_fk'];
    pg_free_result($result);

    pg_exec("BEGIN;");
    $sql = "DELETE FROM tag_file WHERE tag_file_pk = $tag_file_pk;";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    pg_free_result($result);

    $sql = "DELETE FROM tag WHERE tag_pk = $tag_pk;";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    pg_free_result($result);
    pg_exec("COMMIT;");

    return (NULL);
  }
  /***********************************************************
   ShowExistTags($Uploadtree_pk): Show all tags about
   $Uploadtree_pk
   ***********************************************************/
  function ShowExistTags($Upload,$Uploadtree_pk)
  {
    global $PG_CONN;
    $VE = "";
    $VE = _("<h3>Current Tags:</h3>\n");
    $sql = "SELECT tag_pk, tag, tag_desc, tag_ns_pk, tag_ns_name, tag_file_pk, tag_file_date, tag_file_text FROM tag, tag_ns, tag_file, uploadtree WHERE tag.tag_pk = tag_file.tag_fk AND tag.tag_ns_fk = tag_ns.tag_ns_pk AND tag_file.pfile_fk = uploadtree.pfile_fk AND uploadtree.uploadtree_pk = $Uploadtree_pk;";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    if (pg_num_rows($result) > 0)
    {
      $VE .= "<table border=1>\n";
      $text = _("Tag Namespace");
      $text1 = _("Tag");
      $text2 = _("Tag Description");
      $text3 = _("Tag Date");
      $VE .= "<tr><th>$text</th><th>$text1</th><th>$text2</th><th>$text3</th><th></th></tr>\n";
      while ($row = pg_fetch_assoc($result))
      {
        $VE .= "<tr><td align='center'>" . $row['tag_ns_name'] . "</td><td align='center'>" . $row['tag'] . "</td><td align='center'>" . $row['tag_desc'] . "</td><td align='center'>" . substr($row['tag_file_date'],0,19) . "</td>";
        $perm = GetTaggingPerms($_SESSION['UserId'],$row['tag_ns_pk']);
        if ($perm > 1){
          $VE .= "<td align='center'><a href='" . Traceback_uri() . "?mod=tag&action=edit&upload=$Upload&item=$Uploadtree_pk&tag_file_pk=" . $row['tag_file_pk'] . "'>Edit</a>|<a href='" . Traceback_uri() . "?mod=tag&action=delete&upload=$Upload&item=$Uploadtree_pk&tag_file_pk=" . $row['tag_file_pk'] . "'>Delete</a></td></tr>\n";
        }else{ 
          $VE .= "<td align='center'></td></tr>\n";
        }
      }
      $VE .= "</table><p>\n";
    }
    pg_free_result($result);
  
    return $VE;
  }

  /***********************************************************
   ShowAjaxPage(): Display the ajax page.
   ***********************************************************/
  function ShowAjaxPage()
  {
    $VA = "";
    /* Create AJAX javascript */
    $VA .= ActiveHTTPscript("Tags");
    $VA .= "<script language='javascript'>\n";
    $VA .= "var swtemp=0,objtemp;\n";
    $VA .= "function mouseout(o){\n";
    $VA .= "     o.style.display = \"none\";\n";
    $VA .= "     swtemp = 0;\n";
    $VA .= "}\n";
    $VA .= "function removediv(inputid){\n";
    $VA .= "     getobj(inputid+\"mydiv\").style.display=\"none\";\n";
    $VA .= "}\n";
    $VA .= "function creatediv(_parent,_element,_id,_css){\n";
    $VA .= "     var newObj = document.createElement(_element);\n";
    $VA .= "     if(_id && _id!=\"\")newObj.id=_id;\n";
    $VA .= "     if(_css && _css!=\"\"){\n";
    $VA .= "             newObj.setAttribute(\"style\",_css);\n";
    $VA .= "             newObj.style.cssText = _css;\n";
    $VA .= "     }\n";
    $VA .= "     if(_parent && _parent!=\"\"){\n";
    $VA .= "             var theObj=getobj(_parent);\n";
    $VA .= "             var parent = theObj.parentNode;\n";
    $VA .= "             if(parent.lastChild == theObj){\n";
    $VA .= "                     theObj.appendChild(newObj);\n";
    $VA .= "             }\n";
    $VA .= "             else{\n";
    $VA .= "                     theObj.insertBefore(newObj, theObj.nextSibling);\n";
    $VA .= "             }\n";
    $VA .= "     }\n";
    $VA .= "     else        document.body.appendChild(newObj);\n";
    $VA .= "}\n";
    $VA .= "function getobj(o){\n";
    $VA .= "     return document.getElementById(o);\n";
    $VA .= "}\n";
    $VA .= "function Tags_Reply()\n";
    $VA .= "{\n";
    $VA .= "  if ((Tags.readyState==4) && (Tags.status==200))\n";
    $VA .= "  {\n";
    $VA .= "    var list = Tags.responseText;\n";
    $VA .= "    var text_list = list.split(\",\")\n";
    $VA .= "    var inputid = getobj(\"tag_name\");\n";
    $VA .= "    if (swtemp==1){getobj(objtemp+\"mydiv\").style.display=\"none\";}\n";
    $VA .= "    if (!getobj(inputid+\"mydiv\") && list!=\"\"){\n";
    $VA .= "        var divcss=\"width:240px;font-size:12px;position:absolute;left:\"+(inputid.offsetLeft+0)+\"px;top:\"+(inputid.offsetTop+23)+\"px;border:1px solid\";\n";
    $VA .= "        creatediv(\"\",\"div\",inputid+\"mydiv\",divcss);\n";
    $VA .= "        for (var i=0;i<text_list.length-1;i++){\n";
    $VA .= "            creatediv(inputid+\"mydiv\",\"li\",inputid+\"li\"+i,\"color:#000;background:#fff;float:left;list-style-type:none;padding:9px;margin:0;CURSOR:pointer\");\n";
    $VA .= "            getobj(inputid+\"li\"+i).innerHTML=text_list[i];\n";
    $VA .= "            getobj(inputid+\"li\"+i).onmouseover=function(){this.style.background=\"#eee\";}\n";
    $VA .= "            getobj(inputid+\"li\"+i).onmouseout=function(){this.style.background=\"#fff\"}\n";
    $VA .= "            getobj(inputid+\"li\"+i).onclick=function(){\n";
    $VA .= "                                                        inputid.value=this.innerHTML;\n";
    $VA .= "                                                        removediv(inputid);\n";
    $VA .= "                                                       }\n";
    $VA .= "        }\n";
    $VA .= "    }\n";
    $VA .= "    var newdiv=getobj(inputid+\"mydiv\");\n";
    //$VA .= "    newdiv.onclick=function(){removediv(inputid);}\n";
    $VA .= "    document.body.onclick = function(){removediv(inputid);}\n";
    $VA .= "    newdiv.onblur=function(){mouseout(this);}\n";
    $VA .= "    newdiv.style.display=\"block\";\n";
    $VA .= "    swtemp=1;\n";
    $VA .= "    objtemp=inputid;\n";
    $VA .= "    newdiv.focus();\n";
    $VA .= "  }\n";
    $VA .= "}\n;";
    $VA .= "</script>\n";

    return $VA;
  }

  /***********************************************************
   ShowCreateTagPage(): Display the create tag page.
   ***********************************************************/
  function ShowCreateTagPage($Upload,$Item)
  {
    global $PG_CONN;
    $VC = "";
    $VC .= _("<h3>Create Tag:</h3>\n");

    /* Get ufile_name from uploadtree_pk */
    $sql = "SELECT ufile_name, ufile_mode FROM uploadtree
              WHERE uploadtree_pk = $Item";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    $ufile_name = $row["ufile_name"];
    $ufile_mode = $row["ufile_mode"];
    pg_free_result($result);

    $VC.= "<form name='form' method='POST' action='" . Traceback_uri() ."?mod=tag&upload=$Upload&item=$Item'>\n";
    /* Get TagName Space Name */
    $tag_ns = DB2KeyValArray("tag_ns", "tag_ns_pk", "tag_ns_name","");

    /*
    $text = _("Tag");
    $VC .= "<p><font color='blue'>$text: $ufile_name</font></p>";
    */

    $select = Array2SingleSelect($tag_ns, "tag_ns_pk", "");
    $text = _("Namespace");
    $VC .= "<p>$text:$select</p>";
    $VC .= "<p>";
    $text = _("Tag");
    $VC .= "$text: <input type='text' id='tag_name' name='tag_name' autocomplete='off' onclick='Tags_Get(\"". Traceback_uri() . "?mod=tag_get&uploadtree_pk=$Item\")'/> ";

    /****** Permission comments: if user don't have add or high permission, can't see this check box ******/
    //$VC .= "<input type='checkbox' name='tag_add' value='1'/>";
    //$VC .= _("Check to confirm this is a new tag.");
    $VC .= "</p>";
    $text = _("Tag description:");
    $VC .= "<p>$text <input type='text' name='tag_desc'/></p>";
    $VC .= _("<p>Notes:</p>");
    $VC .= "<p><textarea rows='10' cols='80' name='tag_notes'></textarea></p>";
    if (Iscontainer($ufile_mode))
    {
      /* Recursively tagging UI part comment out */
      /*
      $text = _("Tag this files only.");
      $VC .= "<p><input type='checkbox' name='tag_file' value='1' checked/>$text</p>";
      $text = _("Tag all packages (source and binary) in this container tree.");
      $VC .= "<p><input type='checkbox' name='tag_package' value='1'/> $text</p>";
      $text = _("Tag every file in this container tree.");
      $VC .= "<p><input type='checkbox' name='tag_container' value='1'/> $text</p>";
      */
      $VC .= "<p><input type='hidden' name='tag_file' value='1'/></p>"; 
    } else {
      $VC .= "<p><input type='hidden' name='tag_file' value='1'/></p>";
    }
    $text = _("Create");
    $VC .= "<input type='hidden' name='action' value='add'/>\n";
    $VC .= "<input type='submit' value='$text'>\n";
    $VC .= "</form>\n";

    return $VC;
  }

  /***********************************************************
   ShowEditTagPage(): Display the edit tag page.
   ***********************************************************/
  function ShowEditTagPage($Upload,$Item)
  {
    global $PG_CONN;
    $VEd = "";
    $text = _("Create New Tag");
    $VEd .= "<h4><a href='" . Traceback_uri() . "?mod=tag&upload=$Upload&item=$Item'>$text</a><h4>";

    $VEd .= _("<h3>Edit Tag:</h3>\n");
    $tag_file_pk = GetParm("tag_file_pk",PARM_INTEGER); 

    /* Get ufile_name from uploadtree_pk */
    $sql = "SELECT ufile_name, ufile_mode FROM uploadtree
              WHERE uploadtree_pk = $Item";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    $ufile_name = $row["ufile_name"];
    $ufile_mode = $row["ufile_mode"];
    pg_free_result($result);

    /* Get all information about $tag_file_pk */
    $sql = "SELECT tag_pk, tag_file_text, tag, tag_ns_pk, tag_ns_name, tag_desc FROM tag_file, tag, tag_ns WHERE tag_file_pk=$tag_file_pk AND tag_file.tag_fk = tag.tag_pk AND tag.tag_ns_fk = tag_ns.tag_ns_pk;";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    $tag_pk = $row['tag_pk'];
    $tag = $row['tag'];
    $tag_notes = $row['tag_file_text'];
    $tag_ns_pk = $row['tag_ns_pk'];
    $tag_desc = $row['tag_desc'];
    pg_free_result($result); 

    $VEd.= "<form name='form' method='POST' action='" . Traceback_uri() ."?mod=tag&upload=$Upload&item=$Item'>\n";
    /* Get TagName Space Name */
    $tag_ns = DB2KeyValArray("tag_ns", "tag_ns_pk", "tag_ns_name","");

    $select = Array2SingleSelect($tag_ns, "tag_ns_pk",$tag_ns_pk,false,false);
    $text = _("Namespace");
    $VEd .= "<p>$text:$select</p>";
    $VEd .= "<p>";
    $text = _("Tag");
    $VEd .= "$text: <input type='text' id='tag_name' name='tag_name' autocomplete='off' onclick='Tags_Get(\"". Traceback_uri() . "?mod=tag_get&uploadtree_pk=$Item\")' value=\"$tag\"/> ";
    $text = _("Tag description:");
    $VEd .= "<p>$text <input type='text' name='tag_desc' value=\"$tag_desc\"/></p>";
    $VEd .= _("<p>Notes:</p>");
    $VEd .= "<p><textarea rows='10' cols='80' name='tag_notes'>$tag_notes</textarea></p>";
    if (Iscontainer($ufile_mode))
    {
      /* 
      $text = _("Tag this files only.");
      $VEd .= "<p><input type='checkbox' name='tag_file' value='1' checked/>$text</p>";
      $text = _("Tag all packages (source and binary) in this container tree.");
      $VEd .= "<p><input type='checkbox' name='tag_package' value='1'/> $text</p>";
      $text = _("Tag every file in this container tree.");
      $VEd .= "<p><input type='checkbox' name='tag_container' value='1'/> $text</p>";
      */
      $VEd .= "<p><input type='hidden' name='tag_file' value='1'/></p>";
    } else {
      $VEd .= "<p><input type='hidden' name='tag_file' value='1'/></p>";
    }
    $text = _("Edit");
    $VEd .= "<input type='hidden' name='action' value='update'/>\n";
    $VEd .= "<input type='hidden' name='tag_pk' value='$tag_pk'/>\n";
    $VEd .= "<input type='hidden' name='tag_file_pk' value='$tag_file_pk'/>\n";
    $VEd .= "<input type='submit' value='$text'>\n";
    $VEd .= "</form>\n";

    return $VEd;
  }

  /***********************************************************
   ShowDeleteTagPage(): Display the delete tag page.
   ***********************************************************/
  function ShowDeleteTagPage($Upload,$Item)
  {
    global $PG_CONN;
    $VD = "";
    $VD .= _("<h3>Delete Tag:</h3>\n");
    
    /* Get ufile_name from uploadtree_pk */
    $sql = "SELECT ufile_name, ufile_mode FROM uploadtree
              WHERE uploadtree_pk = $Item";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    $ufile_name = $row["ufile_name"];
    $ufile_mode = $row["ufile_mode"];
    pg_free_result($result);

    $sql = "SELECT tag_pk, tag, tag_ns_pk, tag_ns_name, tag_file_pk, tag_file_date, tag_file_text FROM tag, tag_ns, tag_file, uploadtree WHERE tag.tag_pk = tag_file.tag_fk AND tag.tag_ns_fk = tag_ns.tag_ns_pk AND tag_file.pfile_fk = uploadtree.pfile_fk AND uploadtree.uploadtree_pk = $Item;";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    if (pg_num_rows($result) > 0)
    {
      $VD .= "<form name='form' method='POST' action='" . Traceback_uri() ."?mod=tag&upload=$Upload&item=$Item'>\n";
      $VD .= "<select multiple size='10' name='tag_file_pk[]'>\n";
      while ($row = pg_fetch_assoc($result))
      {
        $VD .= "<option value='" . $row['tag_file_pk'] . "'>" . $row['tag_ns_name'] . "-" . $row['tag'] . "</option>\n";
      }
      $VD .= "</select>\n";
      if (Iscontainer($ufile_mode))
      {
        $text = _("Delete Tag only for this file.");
        $VD .= "<p><input type='checkbox' name='tag_file' value='1' checked/>$text</p>";
        $text = _("Delete Tag for all packages (source and binary) in this container tree.");
        $VD .= "<p><input type='checkbox' name='tag_package' value='1'/>$text</p>";
        //$text = _("Delete Tag for every file in this container tree.");
        //$VD .= "<p><input type='checkbox' name='tag_container' value='1'/> $text</p>";
      } else {
        $VD .= "<p><input type='hidden' name='tag_file' value='1'/></p>";
      }
      $text = _("Delete");
      $VD .= "<input type='hidden' name='action' value='delete'/>\n";
      $VD .= "<input type='submit' value='$text'>\n";
      $VD .= "</form>\n";
    }
    pg_free_result($result);

    return ($VD);
  }

  /***********************************************************
   ShowTaggingPage(): Display the tagging page.
   ***********************************************************/
  function ShowTaggingPage($ShowMenu=0,$ShowHeader=0,$action)
  {
    global $PG_CONN;
    $V = "";
    $Upload = GetParm("upload",PARM_INTEGER);
    $Item = GetParm("item",PARM_INTEGER);

    if (empty($Item) || empty($Upload))
        { return; }

    /**********************************
     Display micro header
     **********************************/
    if ($ShowHeader)
      {
      $V .= Dir2Browse("browse",$Item,NULL,1,"Browse");
      } // if ShowHeader
   
    /* Display exist tags for this file */
    $V .=  $this->ShowExistTags($Upload,$Item);
 
    /* Add AJAX script */
    $V .= $this->ShowAjaxPage();

    if ($action == 'edit') 
    {
      $V .= $this->ShowEditTagPage($Upload,$Item);
    } else {
      /* Show create tag page */
      $perm = GetTaggingPerms($_SESSION['UserId'],NULL);
      if ($perm > 1) {      
        $V .= $this->ShowCreateTagPage($Upload,$Item);
      }
      /* Show delete tag page removing
      $V .= $this->ShowDeleteTagPage($Upload,$Item);
      */
    }
    return($V);
  }
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
    if ($this->State != PLUGIN_STATE_READY) { return; }
    $V="";
    $action = GetParm('action', PARM_TEXT);
    switch($this->OutputType)
      {
      case "XML":
        break;
      case "HTML":
        if ($action == 'add')
        {
          $rc = $this->CreateTag();
          if (!empty($rc))
          {
            $text = _("Create Tag Failed");
            $V .= displayMessage("$text: $rc");
          } else {
            $text = _("Create Tag Successful!");
            $V .= displayMessage($text);
          }
        }
        if ($action == 'update')
        {
          $rc = $this->EditTag();
          if (!empty($rc))
          {
            $text = _("Edit Tag Failed");
            $V .= displayMessage("$text: $rc");
          }else{
            $text = _("Edit Tag Successful!");
            $V .= displayMessage($text);
          }
        }
        if ($action == 'delete')
        {
          $rc = $this->DeleteTag();
          if (!empty($rc))
          {
            $text = _("Delete Tag Failed");
            $V .= displayMessage("$text: $rc");
          }else{
            $text = _("Delete Tag Successful!");
            $V .= displayMessage($text);
          }
        }
        $V .= $this->ShowTaggingPage(1,1,$action);
        break;
      case "Text":
        break;
      default:
	break;
      }
    if (!$this->OutputToStdout) { return($V); }
    print("$V");
    return;
    } // Output()

  };
$NewPlugin = new ui_tag;
$NewPlugin->Initialize();
?>
