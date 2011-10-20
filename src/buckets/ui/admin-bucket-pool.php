<?php
/***********************************************************
 Copyright (C) 2010-2011 Hewlett-Packard Development Company, L.P.

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

define("TITLE_admin_bucket_pool", _("Duplicate Bucketpool"));

class admin_bucket_pool extends FO_Plugin
{
  var $Name       = "admin_bucket_pool";
  var $Version    = "1.0";
  var $Title      = TITLE_admin_bucket_pool;
  var $MenuList   = "Admin::Buckets::Duplicate Bucketpool";
  var $Dependency = array("db");
  var $DBaccess   = PLUGIN_DB_USERADMIN;

  /*
   * @brief Clone a bucketpool and its bucketdef records.
   *        Increment the bucketpool version.
   *
   * @param $bucketpool_pk  pk to clone.
   * @param $UpdateDefault  'on' if true,  or empty if false
   *
   * @return the new bucketpool_pk
   *         A message suitable to display to the user is returned in $msg.
   *         This may be a success message or a non-fatal error message.
   */
  function CloneBucketpool($bucketpool_pk, $UpdateDefault, &$msg)
  {
    global $PG_CONN;

    /* select the old bucketpool record */
    $sql = "select * from bucketpool where bucketpool_pk='$bucketpool_pk' ";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    pg_free_result($result);

    /* Get the last version for this bucketpool name.
     * There could be a race condition between getting the last version and
     * inserting the new version, but this is an admin only function and it
     * would be pretty odd if two admins were modifying the same bucketpool
     * at the same instant.  Besides if this does occur, the loser will just
     * get a message about the dup record and no harm done.
     */
    $sql = "select max(version) as version from bucketpool where bucketpool_name='$row[bucketpool_name]'";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $vrow = pg_fetch_assoc($result);
    pg_free_result($result);
    $newversion = $vrow['version'] + 1;

    /* Insert the new bucketpool record 
     */
    $sql = "insert into bucketpool (bucketpool_name, version, active, description) select bucketpool_name, '$newversion', active, description from bucketpool where bucketpool_pk=$bucketpool_pk";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);

    /* Retrieve the new bucketpool_pk */
    $sql = "select bucketpool_pk from bucketpool where bucketpool_name='$row[bucketpool_name]' and version='$newversion'";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    $newbucketpool_pk = $row['bucketpool_pk'];

    /* duplicate all the bucketdef records for the new bucketpool_pk */
    $sql = "insert into bucket_def (bucket_name, bucket_color, bucket_reportorder, bucket_evalorder, bucketpool_fk, bucket_type, bucket_regex, bucket_filename, stopon, applies_to) 
select bucket_name, bucket_color, bucket_reportorder, bucket_evalorder, $newbucketpool_pk, bucket_type, bucket_regex, bucket_filename, stopon, applies_to from bucket_def where bucketpool_fk=$bucketpool_pk";
    $insertresult = pg_query($PG_CONN, $sql);
    DBCheckResult($insertresult, $sql, __FILE__, __LINE__);

    /* Update default bucket pool in user table for this user only */
    if ($UpdateDefault == 'on')
    {
      $sql = "update users set default_bucketpool_fk='$newbucketpool_pk' where user_pk='$_SESSION[UserId]'";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
    }

    return $newbucketpool_pk;
  }
 
  /************************************************
   * Output()
   * User chooses a bucketpool to duplicate from a select list.
   * The new bucketpool and bucket_def records will be identical 
   * to the originals except for the primary keys and bucketpool version
   * (which will be bumped).
   * The user can optionally also set their default bucketpool to the 
   * new one.  This is the default.
   *
   * The user must then manually modify the bucketpool and/or bucketdef
   * records to create their new (modified) bucketpool.
   ************************************************/
  function Output()
  {
    global $DB;
    global $PROJECTSTATEDIR;

    if ($this->State != PLUGIN_STATE_READY) { return; }

    /* get the bucketpool_pk to clone */
    $bucketpool_pk = GetParm("default_bucketpool_fk",PARM_INTEGER);
    $UpdateDefault = GetParm("updatedefault",PARM_RAW);

    if (!empty($bucketpool_pk))
    {
      $msg = "";
      $newbucketpool_pk = $this->CloneBucketpool($bucketpool_pk, $UpdateDefault, $msg);
$text = _("Your new bucketpool_pk is");
      echo "$text $newbucketpool_pk<hr>";
    }

echo "<p>";
echo _("The purpose of this is to facilitate editing an existing bucketpool.  Make sure you
understand");
echo " <a href='http://fossology.org/buckets'>";
echo _("Creating Bucket Pools");
echo "</a> ";
echo _("before continuing.");
echo _(" It will explain why you should create a new bucketpool rather than edit an old one that has already recorded results.");
echo "<p>";
echo _("Steps to modify a bucketpool:");
echo "<ol>";
echo "<li>";
echo _("Create a baseline with your current bucketpool.  In other words, run a bucket scan on something.  If you do this before creating a new modified bucketpool, you can compare the old results with the new to verify it is working as you expect.");
echo "<li>";
echo _("Duplicate the bucketpool (this will increment the bucketpool version and its bucketdef records).  You should also check 'Update my default bucketpool' since new bucket jobs only use your default bucketpool.");
echo "<li>";
echo _("Duplicate any bucket scripts that you defined in $PROJECTSTATEDIR.");
echo "<li>";
echo _("Manually edit the new bucketpool record, if desired.");
echo "<li>";
echo _("Manually insert/update/delete the new bucketdef records.");
echo "<li>";
echo _("Delete your old bucket job from the job queue (from Show Jobs).  You must delete the old completed bucket job from the queue rather than reset it because a reset will use the previous bucketpool (the one you originally queued).");
echo "<li>";
echo _("Queue up the new bucket job in Jobs > Agents.");
echo "<li>";
echo _("Use Buckets > Compare to compare the new and old runs.  Verify the results.");
echo "<li>";
echo _("If you still need to edit the buckets, use Buckets > Remove Bucket Results to remove the previous runs results and repeat starting with editing the bucketpool or def records.");
echo "<li>";
echo _("When the bucket results are what you want, then you can reset all the users of the old bucketpool to the new one with Buckets > New Bucketpool.");
echo "</ol>";
echo "<hr>";

	echo "<form method='POST'>";
    $Val = "";
$text = _("Choose the bucketpool to duplicate");
    echo "$text ";
    echo SelectBucketPool($Val);

    echo "<p>";
$text = _("Update my default bucketpool");
    echo "<input type='checkbox' name='updatedefault' checked> $text.";
    echo "<p>";
$text = _("Submit");
    echo "<input type='submit' value='$text'>";
	echo "</form>";

    return;
  } // Output()

};

$NewPlugin = new admin_bucket_pool;
$NewPlugin->Initialize();
?>
