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
 * \file ajax_upload_agents.php
 * \brief  This plugin is used to list all agents that can
 * be scheduled for a given upload.
 * This is NOT intended to be a user-UI plugin.
 * This is intended as an active plugin to provide support
 * data to the UI.
 */

define("TITLE_ajax_upload_agents", _("List Agents for an Upload as Options"));

/**
 * \class ajax_upload_agents extends from FO_Plugin
 * \brief list all agents that can be scheduled for a given upload.
 */
class ajax_upload_agents extends FO_Plugin
{
  var $Name       = "upload_agent_options";
  var $Title      = TITLE_ajax_upload_agents;
  var $Version    = "1.0";
  var $Dependency = array();
  var $DBaccess   = PLUGIN_DB_READ;
  var $NoHTML     = 1; /* This plugin needs no HTML content help */

  /**
   * \brief Display the loaded menu and plugins.
   */
  function Output()
  {
    if ($this->State != PLUGIN_STATE_READY) {
      return;
    }
    $V="";
    global $Plugins;
    switch($this->OutputType)
    {
      case "XML":
        break;
      case "HTML":
        $UploadPk = GetParm("upload",PARM_INTEGER);
        if (empty($UploadPk)) {
          return;
        }
        $Depth=0;
        $agent_list = menu_find("Agents", $depth);
        $Skip = array("agent_unpack", "agent_adj2nest", "wget_agent");
        for($ac=0; !empty($agent_list[$ac]->URI); $ac++)
        {
          if (array_search($agent_list[$ac]->URI, $Skip) !== false) continue;
          $P = &$Plugins[plugin_find_id($agent_list[$ac]->URI)];
          if ($P->AgentHasResults($UploadPk) != 1)
          {
            $V .= "<option value='" . $agent_list[$ac]->URI . "'>";
            $V .= htmlentities($agent_list[$ac]->Name);
            $V .= "</option>\n";
          }
        }
        break;
      case "Text":
        break;
      default:
        break;
    }
    if (!$this->OutputToStdout) {
      return($V);
    }
    print($V);
    return;
  } // Output()

};
$NewPlugin = new ajax_upload_agents;
$NewPlugin->Initialize();

?>
