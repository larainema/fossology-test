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

/**
 * Upload a file using the UI
 *
 *
 *@TODO need to make sure testing folder exists....
 *
 * @version "$Id: $"
 *
 * Created on Aug 1, 2008
 */

 /*
  * Yuk! This test is ugly! May Need A proxy for this test to work
  * inside hp.
  */

require_once ('../../../../tests/fossologyWebTestCase.php');
require_once ('../../../../tests/TestEnvironment.php');

global $URL;

class UploadFileTest extends fossologyWebTestCase
{

  function testUploadFile()
  {
    global $URL;

    print "starting UploadFileTest\n";
    $browser = & new SimpleBrowser();
    $page = $browser->get($URL, "Fail! Count not get a page from $URL\n");
    $this->assertTrue($page);
    $this->assertTrue(is_object($browser));
    $cookie = $this->repoLogin($browser);
    $host = $this->getHost($URL);
    $browser->setCookie('Login', $cookie, $host);

    $loggedIn = $browser->get($URL);
    $this->assertTrue($this->assertText($loggedIn, '/Upload/'));
    $this->assertTrue($this->assertText($loggedIn, '/From File/'));

    $page = $browser->get("$URL?mod=upload_file");
    $this->assertTrue($this->assertText($page, '/Upload a New File/'));
    $this->assertTrue($this->assertText($page, '/Select the file to upload:/'));
    /* select Testing folder, filename based on pid or session number */

    $id = $this->getFolderId('Testing', $page);
    $this->assertTrue($browser->setField('folder', $id));
    $this->assertTrue($browser->setField('getfile', './TestData/gplv2.1' ));
    $desc = 'File uploaded by test UploadFileTest';
    $this->assertTrue($browser->setField('description', "$desc" ));
    $id = getmypid();
    $upload_name = 'TestUploadFile-' . "$id";
    $this->assertTrue($browser->setField('name', $upload_name ));
    /* we won't select any agents this time' */
    $page = $browser->clickSubmit('Upload!');
    $this->assertTrue(page);
    $this->assertTrue($this->assertText($page, '/Upload added to job queue/'));
    print "*********** Page after upload **************\n$page\n";
  }
}

?>
