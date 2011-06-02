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
 * Browse an uploaded file test
 *
 * @version "$Id$"
 *
 * Created on Aug 13, 2008
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

class browseUPloadedTest extends fossologyTestCase
{
  public $mybrowser;
  public $host;

  function setUp()
  {
    /*
     * This test needs to have file(s) uploaded to browse.  The issue is
     * that uploads can take an indeterminate amount of time.  These
     * jobs should be started before the tests are run?  This is an
     * ongoing issue for testing this product.
     *
     * For now, the setup will just verify the material is there?
     */
    global $URL;

    $this->Login();
  }

  function testBrowseUploaded()
  {
    global $URL;

    print "starting BrowseUploadedtest\n";
    $page = $this->mybrowser->get("$URL?mod=browse");
    $this->assertTrue($this->myassertText($page, '/Browse/'),
                      "BrowseUploadedTest FAILED! Could not find Browse menu\n");
    $this->assertTrue($this->myassertText($page, "/Browse/"),
                      "BrowseUploadedTest FAILED! Browse Title not found\n");
    $this->assertTrue($this->myassertText($page, "|simpletest_1\.0\.1\.tar\.gz|"),
                      "BrowseUploadedTest FAILED did not find string simpletest_1.0.1.tar.gz\n");
    $this->assertTrue($this->myassertText($page, "/>View</"),
                      "BrowseUploadedTest FAILED! Do not see  >View< link\n");
    $this->assertTrue($this->myassertText($page, "/>Info</"),
                      "BrowseUploadedTest FAILED!FAIL!Do not see  >Info< not found\n");
    $this->assertTrue($this->myassertText($page, "/>Download</"),
                      "BrowseUploadedTest FAILED!FAIL! Do not see >Download< not found\n");

    /* Select 'simpletest_1.0.1.tar.gz' */
    $page = $this->mybrowser->clickLink('simpletest_1.0.1.tar.gz');
    //print "*** Page after click simpletest_1.0.1.tar.gz\n$page\n";
    $this->assertTrue($this->myassertText($page, "/simpletest\//"),
                      "BrowseUploadedTest FAILED! simpletest link not found\n)");
    /*
     * TODO: these asserts are bogus, they pass, when those strings are
     * NOT on the page!  wait, sirius, is way messed up....
     */
    $this->assertFalse($this->myassertText($page, "/>View</"),
                      "BrowseUploadedTest FAILED! Do not see >View< link\n");
    $this->assertFalse($this->myassertText($page, "/>Info</"),
                      "BrowseUploadedTest FAILED! Do not see >Info< link\n");
    $this->assertFalse($this->myassertText($page, "/>Download</"),
                      "BrowseUploadedTest FAILED! Do not see >Download< link\n");

    /* Select simpltest link */

    $page = $this->mybrowser->clickLink('simpletest');
    print "*** Page after click simpletest\n$page\n";
    $this->assertTrue($this->myassertText($page, "/HELP_MY_TESTS_DONT_WORK_ANYMORE/"));
    $this->assertTrue($this->myassertText($page, "/$name/"),
                      "BrowseUploadedTest FAILED! did not find simpletest_1.0.1.tar.gz\n");
    $this->assertTrue($this->myassertText($page, "/>View</"),
                      "BrowseUploadedTest FAILED! Do not see >View< link\n");
    $this->assertTrue($this->myassertText($page, "/>Info</"),
                      "BrowseUploadedTest FAILED! Do not see >Info< link\n");
    $this->assertTrue($this->myassertText($page, "/>Download</"),
                      "BrowseUploadedTest FAILED! Do not see >Download< link\n");

    /* Select the License link to View License Historgram */
    $page = $this->mybrowser->clickLink('License');
    $this->assertTrue($this->myassertText($page, '/License Browser/'),
                      "BrowseUploadedTest FAILED!FAIL! License Browser not found\n");
    $this->assertTrue($this->myassertText($page, '/Total licenses: 3/'),
                      "BrowseUploadedTest FAILED!FAIL! Total Licenses does not equal 3\n");
    //print "************ Should be a License Browser page *************\n$page\n";
    /* Select Show in the table */
    $showLink = $this->mybrowser->clickLink('Show');
    /* view the license */
    $licLink = $this->mybrowser->clickLink('LICENSE');
    $viewLink = $this->makeUrl($this->host, $licLink);
    $page = $this->mybrowser->get($viewLink);
    $this->assertTrue($this->myassertText($page, '/View License/'),
                          "BrowseUploadedTest FAILED!FAIL! View License not found\n");
    $licenseResult = $this->mybrowser->getContentAsText($viewLink);
    $this->assertTrue($this->myassertText($licenseResult, '/100% view LGPL v2\.1/'),
                      "BrowseUploadedTest FAILED!FAIL! Did not find '100% view LGPL v2.1'\n   In the License Table for simpletest\n");

    //print "************ page after Browse $nlink *************\n$page\n";
  }
}
?>
