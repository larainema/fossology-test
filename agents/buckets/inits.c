/***************************************************************
 Copyright (C) 2010 Hewlett-Packard Development Company, L.P.

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

 ***************************************************************/
/*
 \file inits.c
 \brief Bucket agent initialization and lookup functions

 */

#include "buckets.h"


/****************************************************
 getBucketPool

 Get a bucketpool_pk based on the bucketpool_name

 @param PGconn *pgConn  Database connection object
 @param char *bucketpool_name

 @return active bucketpool_pk or 0 if error
****************************************************/
FUNCTION int getBucketpool_pk(PGconn *pgConn, char *bucketpool_name)
{
  char *fcnName = "getBucketpool";
  int bucketpool_pk=0;
  char sqlbuf[128];
  PGresult *result;

  /* Skip file if it has already been processed for buckets. */
  sprintf(sqlbuf, "select bucketpool_pk from bucketpool where (bucketpool_name='%s') and (active='Y') order by version desc", 
          bucketpool_name);
  result = PQexec(pgConn, sqlbuf);
  if (checkPQresult(result, sqlbuf, fcnName, __LINE__)) return 0;
  if (PQntuples(result) > 0) bucketpool_pk = atoi(PQgetvalue(result, 0, 0));
  PQclear(result);
  return bucketpool_pk;
}


/****************************************************
 initBuckets

 Initialize the bucket definition list
 If an error occured, write the error to stdout

 @param PGconn *pgConn  Database connection object
 @param int bucketpool_pk
 @param cacheroot_t *pcroot  license cache root

 @return an array of bucket definitions (in eval order)
 or 0 if error.
****************************************************/
FUNCTION pbucketdef_t initBuckets(PGconn *pgConn, int bucketpool_pk, cacheroot_t *pcroot)
{
  char *fcnName = "initBuckets";
  char sqlbuf[256];
  PGresult *result;
  pbucketdef_t bucketDefList = 0;
  int  numRows, rowNum;
  int  rv, numErrors=0;

  /* reasonable input validation  */
  if ((!pgConn) || (!bucketpool_pk)) 
  {
    printf("ERROR: %s.%s.%d Invalid input pgConn: %d, bucketpool_pk: %d.\n",
            __FILE__, fcnName, __LINE__, (int)pgConn, bucketpool_pk);
    return 0;
  }

  /* get bucket defs from db */
  sprintf(sqlbuf, "select bucket_pk, bucket_type, bucket_regex, bucket_filename, stopon, bucket_name from bucket where bucketpool_fk=%d order by bucket_evalorder asc", bucketpool_pk);
  result = PQexec(pgConn, sqlbuf);
  if (checkPQresult(result, sqlbuf, fcnName, __LINE__)) return 0;
  numRows = PQntuples(result);
  if (numRows == 0) /* no bucket recs for pool?  return error */
  {
    printf("ERROR: %s.%s.%d No bucket defs for pool %d.\n",
            __FILE__, fcnName, __LINE__, bucketpool_pk);
    PQclear(result);
    return 0;
  }

  bucketDefList = calloc(numRows+1, sizeof(bucketdef_t));
  if (bucketDefList == 0)
  {
    printf("ERROR: %s.%s.%d No memory to allocate %d bucket defs.\n",
            __FILE__, fcnName, __LINE__, numRows);
    return 0;
  }

  /* put each db bucket def into bucketDefList in eval order */
  for (rowNum=0; rowNum<numRows; rowNum++)
  {
    bucketDefList[rowNum].bucket_pk = atoi(PQgetvalue(result, rowNum, 0));
    bucketDefList[rowNum].bucket_type = atoi(PQgetvalue(result, rowNum, 1));

    rv = regcomp(&bucketDefList[rowNum].compRegex, PQgetvalue(result, rowNum, 2), 
                 REG_NOSUB | REG_ICASE);
    if (rv != 0)
    {
      printf("ERROR: %s.%s.%d Invalid regular expression for bucketpool_pk: %d, bucket: %s\n",
             __FILE__, fcnName, __LINE__, bucketpool_pk, PQgetvalue(result, rowNum, 5));
      numErrors++;
    }
    bucketDefList[rowNum].regex = strdup(PQgetvalue(result, rowNum, 2));

    bucketDefList[rowNum].execFilename = strdup(PQgetvalue(result, rowNum, 3));

    if (bucketDefList[rowNum].bucket_type == 1)
      bucketDefList[rowNum].match_every = getMatchEvery(pgConn, bucketpool_pk, bucketDefList[rowNum].execFilename, pcroot);

    if (bucketDefList[rowNum].bucket_type == 2)
    {
      bucketDefList[rowNum].match_only = getMatchOnly(pgConn, bucketpool_pk, bucketDefList[rowNum].execFilename, pcroot);
    }

    bucketDefList[rowNum].stopon = *PQgetvalue(result, rowNum, 4);
    bucketDefList[rowNum].bucket_name = strdup(PQgetvalue(result, rowNum, 5));
  }
  PQclear(result);
  if (numErrors) return 0;

#ifdef DEBUG
  for (rowNum=0; rowNum<numRows; rowNum++)
  {
    printf("\nbucket_pk[%d] = %d\n", rowNum, bucketDefList[rowNum].bucket_pk);
    printf("bucket_name[%d] = %s\n", rowNum, bucketDefList[rowNum].bucket_name);
    printf("bucket_type[%d] = %d\n", rowNum, bucketDefList[rowNum].bucket_type);
    printf("execFilename[%d] = %s\n", rowNum, bucketDefList[rowNum].execFilename);
    printf("stopon[%d] = %c\n", rowNum, bucketDefList[rowNum].stopon);
    printf("nomos_agent_pk[%d] = %d\n", rowNum, bucketDefList[rowNum].nomos_agent_pk);
    printf("bucket_agent_pk[%d] = %d\n", rowNum, bucketDefList[rowNum].bucket_agent_pk);
    printf("regex[%d] = %s\n", rowNum, bucketDefList[rowNum].regex);
  }
#endif

  return bucketDefList;
}


/****************************************************
 getMatchOnly

 Read the match only file (bucket type 2)

 @param PGconn *pgConn  Database connection object
 @param int bucketpool_pk
 @param char *filename  File name of match_only file

 @return an array of rf_pk's that match the licenses
 in filename.
 or 0 if error.
****************************************************/
FUNCTION int *getMatchOnly(PGconn *pgConn, int bucketpool_pk, 
                             char *filename, cacheroot_t *pcroot)
{
  char *fcnName = "getMatchOnly";
  char *delims = ",\t\n\r";
  char *sp;
  char filepath[256];  
  char inbuf[256];
  int *match_only = 0;
  int  line_count = 0;
  int  lr_pk;
  int  matchNumb = 0;
  FILE *fin;

  /* put together complete file path to match_only file */
  snprintf(filepath, sizeof(filepath), "%s/bucketpools/%d/%s", 
           DATADIR, bucketpool_pk, filename);

  /* open filepath */
  fin = fopen(filepath, "r");
  if (!fin)
  {
    printf("FATAL: %s.%s.%d Failure to initialize bucket %s (pool=%d).\nError: %s\n",
           __FILE__, fcnName, __LINE__, filepath, bucketpool_pk, strerror(errno));
    return 0;
  }

  /* count lines in file */
  while (fgets(inbuf, sizeof(inbuf), fin)) line_count++;
  
  /* calloc match_only array as lines+1.  This set the array to 
     the max possible size +1 for null termination */
  match_only = calloc(line_count+1, sizeof(int));
  if (!match_only)
  {
    printf("FATAL: %s.%s.%d Unable to allocate %d int array.\n",
           __FILE__, fcnName, __LINE__, line_count+1);
    return 0;
  }

  /* read each line fgets 
     A match_only file has one license per line, no leading whitespace.
     Comments start with leading #
   */
  rewind(fin);
  while (fgets(inbuf, sizeof(inbuf), fin)) 
  {
    /* input string should only contain 1 token (license name) */
    sp = strtok(inbuf, delims);

    /* comment? */
    if ((sp == 0) || (*sp == '#')) continue;

    /* look up license rf_pk */
    lr_pk = lrcache_lookup(pcroot, sp);
    if (lr_pk)
    {
      /* save rf_pk in match_only array */
      match_only[matchNumb++] = lr_pk;
//printf("MATCH_ONLY license: %s, FOUND\n", sp);
    }
    else
    {
//printf("MATCH_ONLY license: %s, NOT FOUND in DB - ignored\n", sp);
    }
  }

return match_only;
}


/****************************************************
 getMatchEvery

 Read the match every file filename, for bucket type 1

 @param PGconn *pgConn  Database connection object
 @param int bucketpool_pk
 @param char *filename
 @param cacheroot_t *pcroot  License cache

 @return an array of arrays of rf_pk's that define a 
 match_every combination.
 or 0 if error.
****************************************************/
FUNCTION int **getMatchEvery(PGconn *pgConn, int bucketpool_pk, 
                             char *filename, cacheroot_t *pcroot)
{
  char *fcnName = "getMatchEvery";
  char filepath[256];  
  char inbuf[256];
  int **match_every = 0;
  int **match_every_head = 0;
  int  line_count = 0;
  int  *lr_pkArray;
  int  matchNumb = 0;
  FILE *fin;

  /* put together complete file path to match_every file */
  snprintf(filepath, sizeof(filepath), "%s/bucketpools/%d/%s", 
           DATADIR, bucketpool_pk, filename);

  /* open filepath */
  fin = fopen(filepath, "r");
  if (!fin)
  {
    printf("FATAL: %s.%s.%d Failure to initialize bucket %s (pool=%d).\nError: %s\n",
           __FILE__, fcnName, __LINE__, filepath, bucketpool_pk, strerror(errno));
    return 0;
  }

  /* count lines in file */
  while (fgets(inbuf, sizeof(inbuf), fin)) line_count++;
  
  /* calloc match_every array as lines+1.  This sets the array to 
     the max possible size +1 for null termination */
  match_every = calloc(line_count+1, sizeof(int *));
  if (!match_every)
  {
    printf("FATAL: %s.%s.%d Unable to allocate %d int array.\n",
           __FILE__, fcnName, __LINE__, line_count+1);
    return 0;
  }
  match_every_head = match_every;

  /* read each line fgets 
     A match_every file has 1-n licenses per line
     Comments start with leading #
   */
  rewind(fin);
  while (fgets(inbuf, sizeof(inbuf), fin)) 
  {
    /* comment? */
    if (inbuf[0] == '#') continue;
    lr_pkArray = getLicsInStr(pgConn, inbuf, pcroot);
    if (lr_pkArray)
    {
      /* save rf_pk in match_every array */
      match_every[matchNumb++] = lr_pkArray;
    }
  }

  if (!matchNumb)
  {
    free(match_every_head);
    match_every_head = 0;
  }
return match_every_head;
}


/****************************************************
 getLicsInStr

 Given a string with | separated license names
 return an integer array of rf_pk's

 @param PGconn *pgConn  Database connection object
 @param char *nameStr   string of lic names eg "bsd | gpl"
 @param cacheroot_t *pcroot  License cache

 @return an array of rf_pk's that match the names in nameStr
 
 if nameStr contains a license name that is not in
 the license_ref file, then 0 is returned since there
 is no way to match all the listed licenses.
****************************************************/
FUNCTION int *getLicsInStr(PGconn *pgConn, char *nameStr,
                             cacheroot_t *pcroot)
{
  char *fcnName = "getLicsInStr";
  char *delims = "|\n\r ";
  char *sp;
  int *pkArray;
  int *pkArrayHead = 0;
  int  lic_count = 1;
  int  lr_pk;
  int  matchNumb = 0;

  if (!nameStr) return 0;

  /* count how many seperators are in nameStr
     number of licenses is the count +1 */
  sp = nameStr;
  while (*sp) if (*sp++ == *delims) lic_count++;

  /* we need lic_count+1 int array.  This sets the array to 
     the max possible size +1 for null termination */
  pkArray = calloc(lic_count+1, sizeof(int));
  if (!pkArray)
  {
    printf("FATAL: %s.%s.%d Unable to allocate %d int array.\n",
           __FILE__, fcnName, __LINE__, lic_count+1);
    return 0;
  }
  pkArrayHead = pkArray;  /* save head of array */

  /* read each line then read each license in the line
     Comments start with leading #
   */
  while ((sp = strtok(nameStr, delims)) != 0)
  {
    /* look up license rf_pk */
    lr_pk = lrcache_lookup(pcroot, sp);
    if (lr_pk)
    {
      /* save rf_pk in match_every array */
      pkArray[matchNumb++] = lr_pk;
    }
    else
    {
      /* license not found in license_ref table, so this can never match */
      matchNumb = 0;
      break;
    }
    nameStr = 0;  // for strtok
  }

  if (matchNumb == 0)
  {
    free(pkArrayHead);
    pkArrayHead = 0;
  }

  return pkArrayHead;
}


/****************************************************
 licDataAvailable

 Get the latest nomos agent_pk, and verify that there is
 data from it for this uploadtree.

 @param PGconn *pgConn  Database connection object
 @param int    *uploadtree_pk  

 @return nomos_agent_pk, or 0 if there is no license data from
         the latest version of the nomos agent.
 NOTE: This function writes error to stdout
****************************************************/
FUNCTION int licDataAvailable(PGconn *pgConn, int uploadtree_pk)
{
  char *fcnName = "licDataAvailable";
  char sql[256];
  PGresult *result;
  int  nomos_agent_pk = 0;

  /*** Find the latest enabled nomos agent_pk ***/
  snprintf(sql, sizeof(sql),
           "select agent_pk from agent where agent_name='nomos' order by agent_ts desc limit 1");
  result = PQexec(pgConn, sql);
  if (checkPQresult(result, sql, fcnName, __LINE__)) return 0;
  if (PQntuples(result) == 0)
  {
    /* agent isn't in agent table */
    printf("FATAL: %s.%s.%d agent nomos doesn't exist in agent table.\n",
           __FILE__, fcnName, __LINE__);
    PQclear(result);
    return(0);
  }
  nomos_agent_pk = atoi(PQgetvalue(result,0,0));
  PQclear(result);

  /*** Make sure there is available license data from this nomos agent ***/
  snprintf(sql, sizeof(sql),
           "select fl_pk from license_file where agent_fk=%d limit 1",
           nomos_agent_pk);
  if (checkPQresult(result, sql, fcnName, __LINE__)) return 0;
  if (PQntuples(result) == 0)
  {
    PQclear(result);
    return 0;
  }
  return nomos_agent_pk;
}