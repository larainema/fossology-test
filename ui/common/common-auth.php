<?php
/***********************************************************
 Copyright (C) 2011 Hewlett-Packard Development Company, L.P.

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

/*****************************************
 siteminder_check(): Check if Site Minder enabled.
 if enabled, return $sea
 return -1 if it is not enabled.
 *****************************************/
function siteminder_check() {
  if (isset($_SERVER['HTTP_SMUNIVERSALID'])){
    $SEA = $_SERVER['HTTP_SMUNIVERSALID'];
    return $SEA;
  }
  return(-1);
} // siteminder_check()
