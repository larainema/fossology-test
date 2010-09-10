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
/*************************************************************
Code for creating a menu list (2D linked list) from a set of plugins.
*************************************************************/
class menu {
  var $Name = ""; // name of the menu item
  var $URI = NULL; // URI for the plugin (everything after the "?mod=")
  var $HTML = NULL; // HTML to include (if provided, used in place of all else)
  var $Order = 0; // Used for ordering menu items
  var $Target = NULL; // recommended name of window for showing results
  var $MaxDepth = 0; // How deep is SubMenu?
  var $SubMenu = NULL; // list to submenu list
  
};
/*********************************
Global array: don't touch!
*********************************/
$MenuList = array();
$MenuMaxDepth = 0; // how deep is the tree (for UI display)
/***********************************************************
MenuPage(): Create a "next" page menu.
This function assumes the end number is known.
Returns string containing menu.
***********************************************************/
function MenuPage($Page, $TotalPage, $Uri = '') {
  $V = "<font class='text'><center>";
  if (empty($Uri)) {
    $Uri = Traceback();
  }
  $Uri = preg_replace("/&page=[^&]*/", "", $Uri);
  /* Create first page */
  if ($Page > 0) {
$text = _("First");
$text1 = _("Prev");
    $V.= "<a href='$Uri&page=0'>[$text]</a> ";
    $V.= "<a href='$Uri&page=" . ($Page - 1) . "'>[$text1]</a> ";
    if ($Page > 9) {
      $V.= " ... ";
    }
  }
  /* Create previous list page */
  for ($i = $Page - 9;$i < $Page;$i++) {
    if ($i >= 0) {
      $V.= "<a href='$Uri&page=$i'>" . ($i + 1) . "</a> ";
    }
  }
  /* Show current page number */
  $V.= "<b>" . ($Page + 1) . "</b>";
  /* Create next page */
  for ($i = $Page + 1;($i <= $TotalPage) && ($i < $Page + 9);$i++) {
    $V.= " <a href='$Uri&page=$i'>" . ($i + 1) . "</a>";
  }
  if ($Page < $TotalPage) {
    if ($Page < $TotalPage - 9) {
      $V.= " ...";
    }
$text = _("Next");
$text1 = _("Last");
    $V.= " <a href='$Uri&page=" . ($Page + 1) . "'>[$text]</a>";
    $V.= " <a href='$Uri&page=" . ($TotalPage) . "'>[$text1]</a>";
  }
  $V.= "</center></font>";
  return ($V);
} // MenuPage
/***********************************************************
MenuEndlessPage(): Create a "next" page menu.
This function assumes the end number is unknown.  (Hence, "endless".)
Returns string containing menu.
***********************************************************/
function MenuEndlessPage($Page, $Next = 1, $Uri = '') {
  $V = "<font class='text'><center>";
  if (empty($Uri)) {
    $Uri = Traceback();
  }
  $Uri = preg_replace("/&page=[^&]*/", "", $Uri);
  /* Create first page */
  if ($Page > 0) {
$text = _("First");
$text1 = _("Prev");
    $V.= "<a href='$Uri&page=0'>[$text]</a> ";
    $V.= "<a href='$Uri&page=" . ($Page - 1) . "'>[$text1]</a> ";
    if ($Page > 9) {
      $V.= " ... ";
    }
  }
  /* Create previous list page */
  for ($i = $Page - 9;$i < $Page;$i++) {
    if ($i >= 0) {
      $V.= "<a href='$Uri&page=$i'>" . ($i + 1) . "</a> ";
    }
  }
  /* Show current page number */
  $V.= "<b>" . ($Page + 1) . "</b>";
  /* Create next page */
  if ($Next) {
$text = _("Next");
    $i = $Page + 1;
    $V.= " <a href='$Uri&page=$i'>" . ($i + 1) . "</a>";
    $V.= " ... <a href='$Uri&page=$i'>[$text]</a>";
  }
  $V.= "</center></font>";
  return ($V);
} // MenuEndlessPage()
/*****************************************
menu_cmp(): Compare two menu items for sorting.
*****************************************/
function menu_cmp(&$a, &$b) {
  if ($a->Order > $b->Order) {
    return (-1);
  }
  if ($a->Order < $b->Order) {
    return (1);
  }
  $rc = strcmp($a->Name, $b->Name);
  return (strcmp($a->Name, $b->Name));
} // menu_cmp()
/***********************************************
menu_insert_r(): Given a Path, order level for the last
item, and a plugin name, insert the menu item.
This is VERY recursive and returns the new menu.
If $URI is blank, nothing is added.
$LastOrder is used for grouping items in order.
***********************************************/
function menu_insert_r(&$Menu, $Path, $LastOrder, $Target, $URI, $HTML, $Depth, &$FullName, &$Title) {
  $AddNew = 0;
  $NeedSort = 0;
  $PathParts = explode("::", $Path, 2);
  if (!isset($PathParts[0]) || !strcmp($PathParts[0], "")) {
    return (0);
  } // nothing to do
  if (!isset($PathParts[1])) {
    $LastPart = 1;
  }
  else {
    $LastPart = 0;
  }
  /*****
  $Menu is the top of the list.
  $M is an object in the list.
  *****/
  /* Check if the name exists in the array */
  $M = NULL;
  if (is_array($Menu)) {
    foreach($Menu as $Key => $Val) {
      if (!strcmp($Val->Name, $PathParts[0]) && strcmp($Val->Name, "[BREAK]")) {
        $M = & $Menu[$Key];
        break;
      }
      else if (!strcmp($Val->Name, "[BREAK]") && ($Val->Order == $LastOrder)) {
        $M = & $Menu[$Key];
        break;
      }
    }
  }
  /* if it does not exist in the array, then add it */
  if (empty($M)) {
    $AddNew = 1;
    $NeedSort = 1;
    $M = new menu;
    $M->Name = $PathParts[0];
    $M->FullName = $FullName;
  }
  /* $M is set! See if we need to traverse submenus */
  if ($LastPart != 1) {
    $Depth = menu_insert_r($M->SubMenu, $PathParts[1], $LastOrder, $Target, $URI, $HTML, $Depth + 1, $FullName, $Title);
    $NewDepth = $Depth + 1;
    if ($M->MaxDepth < $NewDepth) {
      $M->MaxDepth = $NewDepth;
    }
  }
  else {
    /* No traversal -- save the final values */
    /** If the menu order is already set, don't reset it to the default **/
    if ($LastOrder != 0) {
      if ($M->Order != $LastOrder) {
        $NeedSort = 1;
      }
      $M->Order = $LastOrder;
    }
    $M->Target = $Target;
    $M->URI = $URI;
    $M->HTML = $HTML;
    $M->FullName = $FullName;
    $M->Title = $Title;
  }
  if ($AddNew == 1) {
    if (isset($Menu)) {
      array_push($Menu, $M);
    }
    else {
      $Menu = array(
        $M
      );
    }
  }
  if ($NeedSort == 1) {
    usort($Menu, 'menu_cmp');
  }
  global $MenuMaxDepth;
  if ($Depth + 1 > $MenuMaxDepth) {
    $MenuMaxDepth = $Depth + 1;
  }
  return ($M->MaxDepth);
} // menu_insert_r()
/***********************************************
menu_insert(): Given a Path, order level for the last
item, and optional plugin name, insert the menu item.
***********************************************/
function menu_insert($Path, $LastOrder = 0, $URI = NULL, $Title = NULL, $Target = NULL, $HTML = NULL) {
  global $MenuList;
  $FullName = $Path;
  menu_insert_r(&$MenuList, $Path, $LastOrder, $Target, $URI, $HTML, 0, $FullName, $Title);
} // menu_insert()
/***********************************************
menu_find(): Given a top-level menu name, return
the list of sub-menus below it and max depth of menu.
$Name may be a "::" separated list.
***********************************************/
function menu_find($Name, &$MaxDepth, $Menu = NULL) {
  global $MenuList;
  if (empty($Menu)) {
    $Menu = $MenuList;
  }
  if (empty($Name)) {
    return ($Menu);
  }
  $PathParts = explode("::", $Name, 2);
  foreach($Menu as $Key => $Val) {
    if ($Val->Name == $PathParts[0]) {
      if (empty($PathParts[1])) {
        $MaxDepth = $Val->MaxDepth;
        return ($Val->SubMenu);
      }
      else {
        return (menu_find($Val->SubMenu, $PathParts[1], $MaxDepth));
      }
    }
  }
  return (NULL);
} // menu_find()
/***********************************************
menu_to_1html(): Take a menu and render it as
one HTML line.  This ignores submenus!
If $ShowAll==0, then items without hyperlinks are hidden.
This is commonly called the "micro-menu".
***********************************************/
$menu_to_1html_counter = 0;
function menu_to_1html($Menu, $ShowRefresh = 1, $ShowTraceback = 0, $ShowAll = 1) {
  $V = "";
  $Std = "";
  global $menu_to_1html_counter;
  if ($ShowTraceback) {
    global $Plugins;
    $Refresh = & $Plugins[plugin_find_id("refresh") ];
    if (!empty($Refresh)) {
$text = _("Traceback");
      $URL = Traceback_dir() . "?" . $Refresh->GetRefresh();
      $Std.= "<a href='$URL' target='_top'>$text</a>";
    }
  }
  if ($ShowRefresh) {
    if (!empty($Std)) {
      $Std.= " | ";
    }
$text = _("Refresh");
    $Std.= "<a href='" . Traceback() . "'>$text</a>";
  }
  $First = 1;
  if (!empty($Menu)) {
    foreach($Menu as $Val) {
      if ($Val->Name == "[BREAK]") {
        if (!$First) {
          $V.= " &nbsp;&nbsp;&bull;&nbsp;&nbsp; ";
        }
        $First = 1;
      }
      else if (!empty($Val->HTML)) {
        $V.= $Val->HTML;
        $First = 0;
      }
      else if (!empty($Val->URI)) {
        if (!$First) {
          $V.= " | ";
        }
        $V.= "<a href='" . Traceback_uri() . "?mod=" . $Val->URI . "'";
        if (!empty($Val->Title)) {
          $V.= " title='" . htmlentities($Val->Title, ENT_QUOTES) . "'";
        }
        $V.= ">";
        if (@$_SESSION['fullmenudebug'] == 1) {
          $V.= $Val->FullName . "(" . $Val->Order . ")";
        }
        else {
          $V.= $Val->Name;
        }
        $V.= "</a>";
        $First = 0;
      }
      else if ($ShowAll) {
        if (!$First) {
          $V.= " | ";
        }
        if (@$_SESSION['fullmenudebug'] == 1) {
          $V.= $Val->FullName . "(" . $Val->Order . ")";
        }
        else {
          $V.= $Val->Name;
        }
        $First = 0;
      }
    }
  }
  if (!empty($Std)) {
    if (!$First) {
      $V.= " &nbsp;&nbsp;&bull;&nbsp;&nbsp; ";
    }
    $V.= $Std;
    $Std = NULL;
  }
  $menu_to_1html_counter++;
  return ("<div id='menu1html-$menu_to_1html_counter' align='right' style='padding:0px 5px 0px 5px'><small>$V</small></div>");
} // menu_to_1html()
/***********************************************
menu_to_1list(): Take a menu and render it as
one HTML line with items in a "[name]" list.
This ignores submenus!
$Parm is a list of parameters to add to the URL.
If $ShowAll==0, then items without hyperlinks are hidden.
***********************************************/
function menu_to_1list($Menu, &$Parm, $Pre = "", $Post = "", $ShowAll = 1) {
  $V = "";
  $Std = "";
  if (!empty($Menu)) {
    foreach($Menu as $Val) {
      if (!empty($Val->HTML)) {
        $V.= $Pre;
        $V.= $Val->HTML;
        $V.= $Post;
      }
      else if (!empty($Val->URI)) {
        $V.= $Pre;
        $V.= "[<a href='" . Traceback_uri() . "?mod=" . $Val->URI . "&" . $Parm . "'";
        if (!empty($Val->Title)) {
          $V.= " title='" . htmlentities($Val->Title, ENT_QUOTES) . "'";
        }
        $V.= ">";
        if (@$_SESSION['fullmenudebug'] == 1) {
          $V.= $Val->FullName . "(" . $Val->Order . ")";
        }
        else {
          $V.= $Val->Name;
        }
        $V.= "</a>]";
        $V.= $Post;
      }
      else if ($ShowAll) {
        $V.= $Pre;
        $V.= "[";
        if (@$_SESSION['fullmenudebug'] == 1) {
          $V.= $Val->FullName . "(" . $Val->Order . ")";
        }
        else {
          $V.= $Val->Name;
        }
        $V.= "]";
        $V.= $Post;
      }
    }
  }
  return ($V);
} // menu_to_1list()
/***********************************************
menu_print(): Debugging code for printing the menu.
This is recursive.
***********************************************/
function menu_print(&$Menu, $Indent) {
  if (!isset($Menu)) {
    return;
  }
  foreach($Menu as $Key => $Val) {
    for ($i = 0;$i < $Indent;$i++) {
      print " ";
    }
    print "$Val->Name ($Val->Order,$Val->URI)\n";
    menu_print($Val->SubMenu, $Indent + 1);
  }
} // menu_print()
// DEBUG CODE
/**********
if (0)
{
menu_insert("abc::def::",0,"");
menu_insert("Applications::Accessories::Dictionary",0,"");
menu_insert("Applications::Accessories::Ark",0,"");
menu_insert("Places::Computer",3,"");
menu_insert("Places::CD/DVD Creator",3,"");
menu_insert("Places::Home Folder",4,"");
menu_insert("Places::Network Servers",2,"");
menu_insert("Places::Search for Files...",0,"");
menu_insert("Places::Desktop",4,"");
menu_insert("Places::Recent Documents",0,"");
menu_insert("Places::Connect to Server...",2,"");
menu_insert("Applications::Accessories::Calculator",0,"");
menu_print($MenuList,0);
print "Max depth: $MenuMaxDepth\n";
}
**********/
?>
