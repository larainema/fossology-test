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
/**
 * uploadSrvFileTest
 *
 * Upload a File
 *
 * @version "$Id$"
 *
 * Created on April 7, 2009
 */

$where = dirname(__FILE__);
if(preg_match('!/home/jenkins.*?tests.*!', $where, $matches))
{
  //echo "running from jenkins....fossology/tests\n";
  require_once('../../tests/fossologyTestCase.php');
  require_once ('../../tests/TestEnvironment.php');
}
else
{
  //echo "using requires for running outside of jenkins\n";
  require_once('../../../tests/fossologyTestCase.php');
  require_once ('../../../tests/TestEnvironment.php');
}

global $URL;

class uploadSrvFileTest extends fossologyTestCase {

  public $mybrowser;

  public function setUp() {

    global $URL;
    $this->Login();
    $this->CreateFolder(1, 'SrvUploads', 'Folder for upload from server tests');
  }

  public function testUploadSrvFile() {

    global $URL;

    $page = $this->mybrowser->get($URL);

    $File = '/home/fosstester/licenses/ApacheLicense-v2.0';
    $Filedescription = "File uploaded from Server";

    $this->uploadServer('SrvUploads', $File, $Filedescription, null, 'all');
  }
};
?>