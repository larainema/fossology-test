#!/usr/bin/php
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
 * Nightly test runs of Top of Trunk
 *
 * @param
 *
 * @return
 *
 * \todo add parameters, e.g. -c for checkout?  -d for run fo-postinstall
 * but these parameters should have a + or - ?  as they are switches for 
 * indicating don't do this.... as all actions are on be default.
 * 
 * \todo save the fossology log file and start with a fresh one each night.
 *
 * @version "$Id$"
 *
 * Created on Dec 18, 2008
 */

require_once('TestRun.php');

// Using the standard source path /home/fosstester/fossology
$tonight = new TestRun();

// Step 1 update sources
print "removing model.dat file so sources will update";
$path = '/home/fosstester/fossology/agents/copyright_analysis/model.dat';
$last = exec("rm $path", $output, $rtn);
if($rtn != 0)
{
	print "Error, could not remove $path, sources will not update, exiting\n";
	exit(1);
}
print "Updating sources with svn update\n";
if($tonight->svnUpdate() !== TRUE)
{
  print "Error, could not svn Update the sources, aborting test\n";
  exit(1);
}

/*
 * TODO: remove all log files as sudo
 */
// Step 2 make clean and make sources
print "Making sources\n";
if($tonight->makeSrcs() !== TRUE)
{
  print "There were Errors in the make of the sources examine make.out\n";
  exit(1);
}
//try to stop the scheduler before the make install step.
print "Stopping Scheduler before install\n";
if($tonight->stopScheduler() !== TRUE)
{
  print "Could not stop fossology-scheduler, maybe it wasn't running?\n";
}

// Step 4 install fossology
print "Installing fossology\n";
if($tonight->makeInstall() !== TRUE)
{
  print "There were Errors in the Installation examine make-install.out\n";
  exit(1);
}

// Step 5 run the post install process
/*
 for most updates you don't have to remake the db and license cache.  Need to 
 add a -d for turning it off.
 */

print "Running fo-postinstall\n";
if($tonight->foPostinstall() !== TRUE)
{
  print "There were errors in the postinstall process check fop.out\n";
  exit(1);
}

// Step 6 run the scheduler test to make sure everything is clean
print "Starting Scheduler Test\n";
if($tonight->schedulerTest() !== TRUE)
{
  print "Error! in scheduler test examine ST.out\n";
  exit(1);
}

print "Starting Scheduler\n";
if($tonight->startScheduler() !== TRUE)
{
  print "Error! Could not start fossology-scheduler\n";
  exit(1);
}

print "Running tests\n";
$testPath = "$tonight->srcPath" . "/tests";
print "testpath is:$testPath\n";
if(!chdir($testPath))
     {
       print "Error can't cd to $testPath\n";
       exit(1);
     }


/*
 * This fails if run by fosstester as Db.conf if not world readable and the
 * script is not running a fossy... need to think about this...
 *
 */
$TestLast = exec('./testFOSSology.php -a -e', $results, $rtn);
print "after running tests the output is\n";
print_r($results) . "\n";

/*
   In either case, need to isolate the results file name so we can feed it to
   the results summary program(s).
 */

/*
 * ok, works for running -a, now need to figure out how to tell if all
 * my jobs are done? then run the verifier part...
 *
 * need to grep for message about jobs not done and isolate the run line and
 * use it.
 */


/*
 * At this point should have results, generate the results summary and email it.
 * 
 * 10-29-2009: the results are generated in testFOSSology.php and mailed there
 * for now.... it should be done here.
 */


/*
print "Stoping Scheduler\n";
if($tonight->stopScheduler() !== TRUE)
{
  print "Error! Could not stop fossology-scheduler\n";
  exit(1);
}
*/

?>
