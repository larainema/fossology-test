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
 * Verify special fossology test archive loaded correctly
 *
 * @version "$Id$"
 *
 * Created on Aug 25, 2008
 */

require_once('../../../tests/fossologyTestCase.php');
require_once('../../../tests/commonTestFuncs.php');
require_once('../../../tests/TestEnvironment.php');
require_once('../../../tests/testClasses/parseBrowseMenu.php');
require_once('../../../tests/testClasses/parseMiniMenu.php');
require_once('../../../tests/testClasses/parseFolderPath.php');
require_once('../../../tests/testClasses/parseLicenseTbl.php');

global $URL;

class verifyFossolyTest extends fossologyTestCase
{
  public $mybrowser;
  public $host;

  function setUp()
  {
    /*
     * This test requires that the fossology test archive has been
     * loaded under the name fossarchive-T.tar.bz2 For now, the setup
     * will just verify the material is there?
     */
    global $URL;
    global $name;
    global $safeName;

    $name = 'fossI16L518.tar.bz2';
    $safeName = escapeDots($name);
    $this->host = getHost($URL);
 
    $this->Login();

    /* check for existense of archive */
    $page = $this->mybrowser->get($URL);
    $page = $this->mybrowser->clickLink('Browse');
    $this->assertTrue($this->myassertText($page, '/Browse/'),
     "verifyFossI16L518 FAILED! Could not find Browse menu\n");
    $page = $this->mybrowser->clickLink('Testing');
    $this->assertTrue($this->myassertText($page, '/Testing/'),
     "verifyFossI16L518 FAILED! Could not find Testing folder\n");
    $result = $this->myassertText($page, "/$safeName/");
    if(!($result)) { exit(FALSE); }
  }

  function testVerifyFossI16L518()
  {
    global $URL;
    global $name;
    global $safeName;

    print "starting VerifyFossI16L518 test\n";
    $page = $this->mybrowser->clickLink('Browse');
    $this->assertTrue($this->myassertText($page, '/Browse/'),
             "verifyFossI16L518 FAILED! Could not find Browse menu\n");
    /* Testing folder */
    $page = $this->mybrowser->clickLink('Testing');
    //print "************ Page after upload link *************\n$page\n";
    $this->assertTrue($this->myassertText($page, "/Browse/"),
       "verifyFossI16L518 FAILED! Browse Title not found\n");
    $this->assertTrue($this->myassertText($page, "/$safeName/"),
       "verifyFossI16L518 FAILED! did not find $name\n");
    $this->assertTrue($this->myassertText($page, "/>View</"),
       "verifyFossI16L518 FAILED! >View< not found\n");
    $this->assertTrue($this->myassertText($page, "/>Meta</"),
       "verifyFossI16L518 FAILED! >Meta< not found\n");
    $this->assertTrue($this->myassertText($page, "/>Download</"),
       "verifyFossI16L518 FAILED! >Download< not found\n");

    /* Select archive */
    $page = $this->mybrowser->clickLink($name);
    //print "************ Page after select foss archive *************\n$page\n";
    $this->assertTrue($this->myassertText($page, "/fossology\//"));

    /* Select fossology link */
    $page = $this->mybrowser->clickLink('fossology/');

    /* need to check that there are 16 items */
    /* check that all the [xxx] items add to 519 */

    $this->assertTrue($this->myassertText($page, "/Makefile/"));
    $this->assertTrue($this->myassertText($page, "/mkcheck\.sh/"),
                      "FAIL! did not find mkcheck.sh\n");
    $this->assertTrue($this->myassertText($page, "/>View</"),
                      "FAIL! >View< not found\n");
    $this->assertTrue($this->myassertText($page, "/>Meta</"),
                      "FAIL! >Meta< not found\n");
    $this->assertTrue($this->myassertText($page, "/>Download</"),
                      "FAIL! >Download< not found\n");

    /* Select the License link to View License Historgram */
    $browse = new parseBrowseMenu($page);
    $mini = new parseMiniMenu($page);
    $miniMenu = $mini->parseMiniMenu();
    $url = makeUrl($this->host, $miniMenu['Nomos License']);
    if($url === NULL) { $this->fail("verifyFossI16L518 Failed, host is not set"); }

    $page = $this->mybrowser->get($url);
    //print "page after get of $url is:\n$page\n";
    $this->assertTrue($this->myassertText($page, '/Nomos License Browser/'),
          "verifyFossI16L518 FAILED! Nomos License Browser Title not found\n");
    $this->assertTrue($this->myassertText($page, '/Total licenses: 337/'),
        "verifyFossI16L518 FAILED! Total Licenses does not equal 337\n");
    $this->assertTrue($this->myassertText($page, '/Unique licenses: 29/'),
        "verifyFossI16L518 FAILED! Unique Licenses does not equal 29\n");
    
    // get the 'Show' links and License color links
    $licTbl = new parseLicenseTbl($page);
    $licTable = $licTbl->parseLicenseTbl();

    /* FIX THIS Select show 'Public Domain, verify, select 'LGPL v2.1', verify */
    $pdURL = makeUrl($this->host, $licTable['Public-domain-claim']);
    $lgplURL = makeUrl($this->host, $licTable['LGPL_v2.1']);

    $page = $this->mybrowser->get($pdURL);
    $licFileList = new parseFolderPath($page, $URL);
    $fileCount = $licFileList->countFiles();
    print "Checking the number of files based on Public-domain-claim\n";
    $this->assertEqual($fileCount, 2,
    "verifyFossI16L518 FAILED! Should be 2 files based on Public-domain-claim got:$fileCount\n");

    $page = $this->mybrowser->get($lgplURL);
    $licFileList->setPage($page);
    $flist = $licFileList->countFiles();
    print "Checking the number of files based on LGPL_v2.1\n";
    $this->assertEqual($flist, 18,
    "verifyFossI16L518 FAILED! Should be 18 files based on LGPL_v2.1 got:$flist\n");
  }
}
?>
