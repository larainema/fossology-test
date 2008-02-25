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

/************************************************************
 These are common functions used by agents.
 Agents should register themselves in the menu structure under the
 top-level "Agents" menu.

 Every agent should have a function called "AgentAdd()" that takes
 an Upload_pk and an optional array of dependent agents ids.

 Every agent should also have a function called "AgentCheck($uploadpk)"
 that determines if the agent has already been scheduled.
 This function should return:
   0 = not scheduled
   1 = scheduled
   2 = completed
 ************************************************************/

/************************************************************
 AgentCheckBoxMake(): Generate a checkbox list of available agents.
 Only agents that are not already scheduled are added.
 If $upload_pk == -1, then list all.
 Returns string containing HTML-formatted checkbox list.
 ************************************************************/
function AgentCheckBoxMake($upload_pk,$SkipAgent=NULL)
{
  global $Plugins;
  $AgentList = menu_find("Agents",$Depth);
  $V = "";
  if (!empty($AgentList))
    {
    foreach($AgentList as $AgentItem)
      {
      $Agent = &$Plugins[plugin_find_id($AgentItem->URI)];
      if (empty($Agent)) { continue; }
      if ($Agent->Name == $SkipAgent) { continue; }
      if ($upload_pk != -1) { $rc = $Agent->AgentCheck($upload_pk); }
      else { $rc = 0; }
      if ($rc == 0)
	{
	$Name = htmlentities($Agent->Name);
	$Desc = htmlentities($AgentItem->Name);
	$V .= "<input type='checkbox' name='Check_$Name' value='1' />$Desc<br />\n";
	}
      }
    }
  return($V);
} // AgentCheckBoxMake()

/************************************************************
 AgentCheckBoxDo(): Assume someone called AgentCheckBoxMake() and
 submitted the HTML form.  Run AgentAdd() for each of the checked agents.
 Because input comes from the user, validate that everything is
 legitimate.
 ************************************************************/
function AgentCheckBoxDo($upload_pk)
{
  $AgentList = menu_find("Agents",$Depth);
  $V = "";
  if (!empty($AgentList))
    {
    foreach($AgentList as $Agent)
      {
      $Agent = &$Plugins[plugin_find_id($AgentItem->URI)];
      if (empty($Agent)) { continue; }
      if ($Agent->Name == $SkipAgent) { continue; }
      $rc = $Agent->AgentCheck($upload_pk);
      $Name = htmlentities($Agent->Name);
      $Parm = GetParm("Check_" . $Name,PARM_INTEGER);
      if (($rc == 0) && ($Parm == 1))
	{
	$Agent->AgentAdd($upload_pk);
	}
      }
    }
  return($V);
} // AgentCheckBoxDo()

?>
