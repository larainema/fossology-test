/***************************************************************
 Copyright (C) 2006-2009 Hewlett-Packard Development Company, L.P.

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
/**
 \file nomos.c
 \brief Main for the nomos agent

 Nomos detects licenses and copyrights in a file.  Depending on how it is
 invoked, it either stores it's findings in the FOSSology data base or
 reports them to standard out.

 */
/* CDB - What is this define for??? */
#ifndef	_GNU_SOURCE
#define	_GNU_SOURCE
#endif	/* not defined _GNU_SOURCE */

#include "nomos.h"
#include "util.h"
#include "list.h"
#include "licenses.h"
#include "process.h"
#include "nomos_regex.h"
#include "_autodefs.h"

#include "libfossrepo.h"
#include "libfossdb.h"
#include "libfossagent.h"

#include "libfossscheduler.h"

#ifdef SVN_REV
char BuildVersion[]="Build version: " SVN_REV ".\n";
#endif /* SVN_REV */

extern licText_t licText[]; /* Defined in _autodata.c */
struct globals gl;
struct curScan cur;
int schedulerMode = 0; /**< Non-zero when being run from scheduler */


/* shortname cache very simple nonresizing hash table */
struct cachenode 
{
  char *rf_shortname;
  long  rf_pk;
};
typedef struct cachenode cachenode_t;

struct cacheroot
{
  int maxnodes;
  cachenode_t *nodes;
};
typedef struct cacheroot cacheroot_t;

#define FUNCTION

int   checkPQresult(PGresult *result, char *sql, char *FcnName, int LineNumb);
char *getFieldValue(char *inStr, char *field, int fieldMax, char *value, int valueMax, char separator) ;
void  parseLicenseList() ;
void  Usage(char *Name) ;
void  Bail(int exitval) ;
int   optionIsSet(int val) ;
static void getFileLists(char *dirpath) ;
void  freeAndClearScan(struct curScan *thisScan) ;
void  processFile(char *fileToScan) ;
int   recordScanToDB(cacheroot_t *pcroot, struct curScan *scanRecord) ;
long  get_rfpk(cacheroot_t *pcroot, char *rf_shortname);

long  add2license_ref(char *licenseName) ;
int   updateLicenseFile(long rfPk) ;

int   initLicRefCache(cacheroot_t *pcroot) ;
long  lrcache_hash(cacheroot_t *pcroot, char *rf_shortname);
int   lrcache_add(cacheroot_t *pcroot, long rf_pk, char *rf_shortname);
long  lrcache_lookup(cacheroot_t *pcroot, char *rf_shortname);
 

/**
 checkPQresult

 check the result status of a postgres SELECT
 If an error occured, log the error message.

 @param PGresult *result
 @param char *sql the sql query
 @param char * FcnName the function name of the caller
 @param int LineNumb the line number of the caller

 @return 0 on OK, -1 on failure.
  On failure, result will be freed.
  NOTE this should be moved to std library.
 */
FUNCTION int checkPQresult(PGresult *result, char *sql, char *FcnName, int LineNumb) 
{
  if (!result) 
  {
    printf("Error: checkPQresult called from %s:%d with invalid parameter",
      FcnName, LineNumb);
    return 0;
  }

  /* If no error, return */
  if (PQresultStatus(result) == PGRES_TUPLES_OK) return 0;

  printf("ERROR: %s:%d, %s\nOn: %s", FcnName, LineNumb, PQresultErrorMessage(result), sql);
  PQclear(result);
  return (-1);
} /* checkPQresult */


/****************************************************
 checkPQcommand

  check the result status of a postgres commands (not select)
  If an error occured, write the error to stdout

  @param PGresult *result
  @param char *sql the sql query
  @param char * FcnName the function name of the caller
  @param int LineNumb the line number of the caller

  @return 0 on OK, -1 on failure.
  On failure, result will be freed.

  NOTE: this function should be moved to a std library
****************************************************/
FUNCTION int checkPQcommand(PGresult *result, char *sql, char *FcnName, int LineNumb)
{
  if (!result)
  {
    printf("Error: %s:%d - checkPQcommand called with invalid parameter.\n",
            FcnName, LineNumb);
    return 0;
  }

  /* If no error, return */
  if (PQresultStatus(result) == PGRES_COMMAND_OK) return 0;

  printf("ERROR: %s:%d, %s\nOn: %s\n",
          FcnName, LineNumb, PQresultErrorMessage(result), sql);
  PQclear(result);
  return (-1);
} /* checkPQcommand */


/**
 add2license_ref
 \brief Add a new license to license_ref table

 Adds a license to license_ref table.

 @param  char *licenseName

 @return rf_pk for success, 0 for failure
 */
FUNCTION long add2license_ref(char *licenseName) {

    PGresult *result;
    char  query[myBUFSIZ];
    char  insert[myBUFSIZ];
    char  escLicName[myBUFSIZ];
    char *specialLicenseText;
    long rf_pk;

    int len;
    int error;
    int numRows;

    // escape the name
    len = strlen(licenseName);
    PQescapeStringConn(gl.pgConn, escLicName, licenseName, len, &error);
    if (error)
      printf("WARNING: %s(%d): Does license name have multibyte encoding?", __FILE__, __LINE__);

    /* verify the license is not already in the table */
    sprintf(query, "SELECT rf_pk FROM license_ref where rf_shortname='%s' and rf_detector_type=2", escLicName);
    result = PQexec(gl.pgConn, query);
    if (checkPQresult(result, query, "add2license_ref", __LINE__)) return 0;
    numRows = PQntuples(result);
    if (numRows)
    {
      rf_pk = atol(PQgetvalue(result, 0, 0));
      return rf_pk;
    }

    /* Insert the new license */
    specialLicenseText = "License by Nomos.";

    sprintf( insert,
            "insert into license_ref(rf_shortname, rf_text, rf_detector_type) values('%s', '%s', 2)",
            escLicName, specialLicenseText);
    result = PQexec(gl.pgConn, insert);
    if (PQresultStatus(result) != PGRES_COMMAND_OK) {
        printf("ERROR: %s(%d): Nomos failed to add a new license. %s/n: %s/n",
            __FILE__,__LINE__, PQresultErrorMessage(result), insert);
        PQclear(result);
        return (0);
    }
    PQclear(result);

    /* retrieve the new rf_pk */
    result = PQexec(gl.pgConn, query);
    if (checkPQresult(result, query, "add2license_ref", __LINE__)) return 0;
    numRows = PQntuples(result);
    if (numRows)
      rf_pk = atol(PQgetvalue(result, 0, 0));
    else
    {
      printf("ERROR: %s:%s:%d Just inserted value is missing. On: %s", __FILE__, "add2license_ref()", __LINE__, query);
      return(0);
    }
    PQclear(result);

    return (rf_pk);
}


/**
 lrcache_hash

 \brief calculate the hash of an rf_shortname
 rf_shortname is the key

 @param cacheroot_t *
 @param rf_shortname

 @return hash value
 */
FUNCTION long lrcache_hash(cacheroot_t *pcroot, char *rf_shortname)
{
  long hashval = 0;
  int len, i;

  /* use the first sizeof(long) bytes for the hash value */
  len = (strlen(rf_shortname) < sizeof(long)) ? strlen(rf_shortname) : sizeof(long);
  for (i=0; i<len;i++) hashval += rf_shortname[i] << 8*i;
  hashval = hashval % pcroot->maxnodes;
  return hashval;
}
 

/**
 lrcache_print

 \brief Print the contents of the hash table

 @param cacheroot_t *

 @return none
 */
FUNCTION void lrcache_print(cacheroot_t *pcroot)
{
  cachenode_t *pcnode;
  long hashval = 0;
  int i;

  pcnode = pcroot->nodes;
  for (i=0; i<pcroot->maxnodes; i++)
  {
    if (pcnode->rf_pk != 0L) 
    {
      hashval = lrcache_hash(pcroot, pcnode->rf_shortname);
      // printf("%ld, %ld, %s\n", hashval, pcnode->rf_pk, pcnode->rf_shortname);
    }
    pcnode++;
  }
}
 

/**
 lrcache_free

 \brief free the hash table

 @param cacheroot_t *

 @return none
 */
FUNCTION void lrcache_free(cacheroot_t *pcroot)
{
  cachenode_t *pcnode;
  int i;

  pcnode = pcroot->nodes;
  for (i=0; i<pcroot->maxnodes; i++)
  {
    if (pcnode->rf_pk != 0L) 
    {
      free(pcnode->rf_shortname);
    }
    pcnode++;
  }
  free(pcroot->nodes);
}


/**
 lrcache_add

 \brief add a rf_shortname, rf_pk to the license_ref cache 
 rf_shortname is the key

 @param cacheroot_t *
 @param rf_pk
 @param rf_shortname

 @return -1 for failure, 0 for success
 */
FUNCTION int lrcache_add(cacheroot_t *pcroot, long rf_pk, char *rf_shortname)
{
  cachenode_t *pcnode;
  long hashval = 0;
  int i;
  int noden;

  hashval = lrcache_hash(pcroot, rf_shortname);

  noden = hashval;
  for (i=0; i<pcroot->maxnodes; i++)
  {
    noden = (hashval +i) & (pcroot->maxnodes -1);

    pcnode = pcroot->nodes + noden;
    if (!pcnode->rf_pk)
    {
      pcnode->rf_shortname = strdup(rf_shortname);
      pcnode->rf_pk = rf_pk;
      break;
    }
  }
  if (i < pcroot->maxnodes) return 0;

  return -1;  /* no space */
}


/**
 lrcache_lookup

 \brief lookup rf_pk in the license_ref cache 
 rf_shortname is the key

 @param cacheroot_t *
 @param rf_shortname

 @return rf_pk, 0 if the shortname is not in the cache
 */
FUNCTION long lrcache_lookup(cacheroot_t *pcroot, char *rf_shortname)
{
  cachenode_t *pcnode;
  long hashval = 0;
  int i;
  int noden;

  hashval = lrcache_hash(pcroot, rf_shortname);

  noden = hashval;
  for (i=0; i<pcroot->maxnodes; i++)
  {
    noden = (hashval +i) & (pcroot->maxnodes -1);

    pcnode = pcroot->nodes + noden;
    if (!pcnode->rf_pk) return 0;
    if (strcmp(pcnode->rf_shortname, rf_shortname) == 0) 
    {
      return pcnode->rf_pk;
    }
  }

  return 0;  /* not found */
}



/**
 initLicRefCache

 \brief build a cache the license ref db table.

 @param cacheroot_t *

 initLicRefCache builds a cache using the rf_shortname as the key
 and the rf_pk as the value.  This is an optimization. The cache is used for 
 reference license lookups instead of querying the db.

 @return 0 for failure, 1 for success
 */

FUNCTION int initLicRefCache(cacheroot_t *pcroot) {

    PGresult *result;
    char query[myBUFSIZ];
    int row;
    int numLics;

    if (!pcroot) return 0;

    sprintf(query, "SELECT rf_pk, rf_shortname FROM license_ref where rf_detector_type=2;");
    result = PQexec(gl.pgConn, query);
    if (checkPQresult(result, query, "initLicRefCache", __LINE__)) return 0;

    numLics = PQntuples(result);
    /* populate the cache  */
    for (row = 0; row < numLics; row++) 
    {
      lrcache_add(pcroot, atol(PQgetvalue(result, row, 0)), PQgetvalue(result, row, 1));
    }

    PQclear(result);

    return (1);
} /* initLicRefCache */


/**
 get_rfpk
 \brief Get the rf_pk for rf_shortname

 Checks the cache to get the rf_pk for this shortname.
 If it doesn't exist, add it to both license_ref and the 
 license_ref cache (the hash table).

 @param cacheroot_t *
 @param char *rf_shortname

 @return rf_pk of the matched license or 0
 */
FUNCTION long get_rfpk(cacheroot_t *pcroot, char *rf_shortname) {
    long  rf_pk;
    size_t len;

    if ((len = strlen(rf_shortname)) == 0) {
        printf("ERROR! Nomos.c get_rfpk() passed empty name");
        return (0);
    }

    /* is this in the cache? */
    rf_pk = lrcache_lookup(pcroot, rf_shortname);
    if (rf_pk) return rf_pk;

    /* shortname was not found, so add it */
    /* add to the license_ref table */
    rf_pk = add2license_ref(rf_shortname);

    /* add to the cache */
    lrcache_add(pcroot, rf_pk, rf_shortname);

    return (rf_pk);
} /* get_rfpk */

/**
 getFieldValue
 \brief Given a string that contains field='value' pairs, save the items.

 @return pointer to start of next field, or NULL at \0.

 \callgraph
 */
FUNCTION char *getFieldValue(char *inStr, char *field, int fieldMax, char *value,
        int valueMax, char separator) {
    int s;
    int f;
    int v;
    int gotQuote;

#ifdef	PROC_TRACE
    traceFunc("== getFieldValue(inStr= %s fieldMax= %d separator= '%c'\n",
            inStr, fieldMax, separator);
#endif	/* PROC_TRACE */

    memset(field, 0, fieldMax);
    memset(value, 0, valueMax);

    /* Skip initial spaces */
    while (isspace(inStr[0])) {
        inStr++;
    }

    if (inStr[0] == '\0') {
        return (NULL);
    }
    f = 0;
    v = 0;

    /* Skip to end of field name */
    for (s = 0; (inStr[s] != '\0') && !isspace(inStr[s]) && (inStr[s] != '='); s++) {
        field[f++] = inStr[s];
    }

    /* Skip spaces after field name */
    while (isspace(inStr[s])) {
        s++;
    }
    /* If it is not a field, then just return it. */
    if (inStr[s] != separator) {
        return (inStr + s);
    }
    if (inStr[s] == '\0') {
        return (NULL);
    }
    /* Skip '=' */
    s++;

    /* Skip spaces after '=' */
    while (isspace(inStr[s])) {
        s++;
    }
    if (inStr[s] == '\0') {
        return (NULL);
    }

    gotQuote = '\0';
    if ((inStr[s] == '\'') || (inStr[s] == '"')) {
        gotQuote = inStr[s];
        s++; /* skip quote */
        if (inStr[s] == '\0') {
            return (NULL);
        }
    }

    if (gotQuote) {
        for (; (inStr[s] != '\0') && (inStr[s] != gotQuote); s++) {
            if (inStr[s] == '\\') {
                value[v++] = inStr[++s];
            }
            else {
                value[v++] = inStr[s];
            }
        }
    }
    else {
        /* if it gets here, then there is no quote */
        for (; (inStr[s] != '\0') && !isspace(inStr[s]); s++) {
            if (inStr[s] == '\\') {
                value[v++] = inStr[++s];
            }
            else {
                value[v++] = inStr[s];
            }
        }
    }
    /* Skip spaces */
    while (isspace(inStr[s])) {
        s++;
    }

    return (inStr + s);
} /* getFieldValue */

/**
 parseLicenseList
 \brief parse the comma separated list of license names found
 Uses cur.compLic and sets cur.licenseList

 void?
 */

FUNCTION void parseLicenseList() {

    int numLics = 0;

    /* char saveLics[myBUFSIZ]; */
    char *saveptr = 0; /* used for strtok_r */
    char *saveLicsPtr;

    if ((strlen(cur.compLic)) == 0) {
        return;
    }

    /* check for a single name  FIX THIS!*/
    if (strstr(cur.compLic, ",") == NULL_CHAR) {
        cur.licenseList[0] = cur.compLic;
        cur.licenseList[1] = NULL;
        return;
    }

    saveLicsPtr = strcpy(saveLics, cur.compLic);

    cur.tmpLics = strtok_r(saveLicsPtr, ",", &saveptr);

    cur.licenseList[numLics] = cur.tmpLics;
    numLics++;

    saveLicsPtr = NULL;
    while (cur.tmpLics) {
        cur.tmpLics = strtok_r(saveLicsPtr, ",", &saveptr);
        if (cur.tmpLics == NULL) {
            break;
        }
        cur.licenseList[numLics] = cur.tmpLics;
        numLics++;
    }
    cur.licenseList[numLics] = NULL;
    numLics++;

    /*
     int i;
     for(i=0; i<numLics; i++){
     printf("cur.licenseList[%d] is:%s\n",i,cur.licenseList[i]);
     }

     printf("parseLicenseList: returning\n");
     */

    return;
} /* parseLicenseList */


FUNCTION void Usage(char *Name) {
    printf("Usage: %s [options] [file [file [...]]\n", Name);
    printf("  -h   :: help (print this message), then exit.\n");
    printf("  -i   :: initialize the database, then exit.\n");
    /*    printf("  -v   :: verbose (-vv = more verbose)\n"); */
    printf(
            "  file :: if files are listed, print the licenses detected within them.\n");
    printf("  no file :: process data from the scheduler.\n");
} /* Usage() */

FUNCTION void Bail(int exitval) {
#ifdef	PROC_TRACE
    traceFunc("== Bail(%d)\n", exitval);
#endif	/* PROC_TRACE */

#if defined(MEMORY_TRACING) && defined(MEM_ACCT)
    if (exitval) {
        memCacheDump("Mem-cache @ Bail() time:");
    }
#endif	/* MEMORY_TRACING && MEM_ACCT */

    if (!cur.cliMode) {
        printf("   LOG: Nomos agent is exiting, exit %d\n", exitval);
        fflush(stdout);
    }

    DBclose(gl.DB);

    exit(exitval);
}


FUNCTION int optionIsSet(int val) {
#ifdef	PROC_TRACE
    traceFunc("== optionIsSet(%x)\n", val);
#endif	/* PROC_TRACE */

    return (gl.progOpts & val);
} /* optionIsSet */

/**
 getFileLists
 \brief Initialize the lists: regular-files list cur.regfList and buffer-offset
 list cur.offList.

 \todo CDB - Could probably roll this back into the main processing
 loop or just put in a generic init func that initializes *all*
 the lists.

 \callgraph
 */

FUNCTION static void getFileLists(char *dirpath) {
#ifdef	PROC_TRACE
    traceFunc("== getFileLists(%s)\n", dirpath);
#endif	/* PROC_TRACE */

    /*    listInit(&gl.sarchList, 0, "source-archives list & md5sum map"); */
    listInit(&cur.regfList, 0, "regular-files list");
    listInit(&cur.offList, 0, "buffer-offset list");
#ifdef	FLAG_NO_COPYRIGHT
    listInit(&gl.nocpyrtList, 0, "no-copyright list");
#endif	/* FLAG_NO_COPYRIGHT */

    listGetItem(&cur.regfList, cur.targetFile);
    return;
} /* getFileLists */


/**
 * updateLicenseFile
 * \brief, insert rf_fk, agent_fk and pfile_fk into license_file table
 *
 * @param long rfPK the reference file foreign key
 *
 * returns boolean (True or False)
 *
 * \callgraph
 */
FUNCTION int updateLicenseFile(long rfPk) {

    PGresult *result;
    char query[myBUFSIZ];

    if (rfPk <= 0) {
        return (FALSE);
    }

    /* If files are comming from command line instead of fossology repo,
       then there are no pfiles.  So don't update the db
    */
    if (cur.cliMode == 1) return (TRUE);

    sprintf(query,
            "INSERT INTO license_file(rf_fk, agent_fk, pfile_fk) VALUES(%ld, %d, %ld)",
            rfPk, gl.agentPk, cur.pFileFk);

    result = PQexec(gl.pgConn, query);

    if ((PQresultStatus(result) != PGRES_COMMAND_OK) &&
        (strncmp("23505", PQresultErrorField(result, PG_DIAG_SQLSTATE),5)))
    {
        // ignoring duplicate constraint failure (23505)
        printf("ERROR: %s:%s:%d  Error:%s %s  On: %s", 
          __FILE__, "updateLicenseFile()", __LINE__, 
          PQresultErrorField(result, PG_DIAG_SQLSTATE),
          PQresultErrorMessage(result), query);
        PQclear(result);
        return (FALSE);
    }
    PQclear(result);
    return (TRUE);
} /* updateLicenseFile */

/**
 * freeAndClearScan
 * \brief Clean-up all the per scan data structures, freeing any old data.
 *
 * \callgraph
 */
FUNCTION void freeAndClearScan(struct curScan *thisScan) {
    /*
     Clear lists
     */
    listClear(&thisScan->regfList, DEALLOC_LIST);
    listClear(&thisScan->offList, DEALLOC_LIST);
    listClear(&thisScan->fLicFoundMap, DEALLOC_LIST);
    listClear(&thisScan->parseList, DEALLOC_LIST);
    listClear(&thisScan->lList, DEALLOC_LIST);
    listClear(&thisScan->cList, DEALLOC_LIST);
    listClear(&thisScan->eList, DEALLOC_LIST);

    /* remove keys, data and hash table */
    hdestroy();

}

/**
 * processFile
 * \brief process a single file
 *
 * \callgraph
 */
FUNCTION void processFile(char *fileToScan) {

  char *pathcopy;
#ifdef	PROC_TRACE
    traceFunc("== processFile(%s)\n", fileToScan);
#endif	/* PROC_TRACE */

    /* printf("   LOG: nomos scanning file %s.\n", fileToScan);  DEBUG */

    (void) strcpy(cur.cwd, gl.initwd);

    strcpy(cur.filePath, fileToScan);
    pathcopy = strdup(fileToScan);
    strcpy(cur.targetDir, dirname(pathcopy));
    free(pathcopy);
    strcpy(cur.targetFile, fileToScan);
    cur.targetLen = strlen(cur.targetDir);

    if (!isFILE(fileToScan)) {
      Fatal("\"%s\" is not a plain file", fileToScan);
    }

    getFileLists(cur.targetDir);
    listInit(&cur.fLicFoundMap, 0, "file-license-found map");
    listInit(&cur.parseList, 0, "license-components list");
    listInit(&cur.lList, 0, "license-list");
    listInit(&cur.cList, 0, "copyright-list");
    listInit(&cur.eList, 0, "eula-list");

    processRawSource();

    /* freeAndClearScan(&cur); */
} /* Process File */

/**
 recordScanToDb

 Write out the information about the scan to the FOSSology database.

 curScan is passed as an arg even though it's available as a global,
 in order to facilitate future modularization of the code.

 Returns: 0 if successful, -1 if not.

 \callgraph
 */
FUNCTION int recordScanToDB(cacheroot_t *pcroot, struct curScan *scanRecord) {

    char *noneFound;
    long rf_pk;
    int  numLicenses;

#ifdef SIMULATESCHED
    /* BOBG: This allows a developer to simulate the scheduler
     with a load file for testing/debugging, without updating the
     database.  Like:
     cat myloadfile | ./nomos
     myloadfile is same as what scheduler sends:
     pfile_pk=311667 pfilename=9A96127E7D3B2812B50BF7732A2D0FF685EF6D6A.78073D1CA7B4171F8AFEA1497E4C6B33.183
     pfile_pk=311727 pfilename=B7F5EED9ECB679EE0F980599B7AA89DCF8FA86BD.72B00E1B419D2C83D1050C66FA371244.368
     etc.
     */
    printf("%s\n",scanRecord->compLic);
    return(0);
#endif

    noneFound = strstr(scanRecord->compLic, LS_NONE);
    if (noneFound != NULL)
    {
      rf_pk = get_rfpk(pcroot, "No License Found");
      if (updateLicenseFile(rf_pk) == FALSE)  return (-1);
      return (0);
    }

    /* we have one or more license names, parse them */
    parseLicenseList();
    /* loop through the found license names */
    for (numLicenses = 0; cur.licenseList[numLicenses] != NULL; numLicenses++) {
        rf_pk = get_rfpk(pcroot, cur.licenseList[numLicenses]);
        if (rf_pk == 0) return(-1);
        if (updateLicenseFile(rf_pk) == FALSE)  return (-1);
    }
    return (0);
} /* recordScanToDb */

int main(int argc, char **argv) {

    int i;
    int c;
    int file_count = 0;
    int upload_pk = 0;
    int numrows;
    int ars_pk = 0;

    extern int AlarmSecs;
    extern long HBItemsProcessed;

    char *cp;
    char *agent_desc = "Nomos License Detection Agency";
    char *parm = NULL;
    char **files_to_be_scanned; /**< The list of files to scan */
    char sqlbuf[1024];
    PGresult *result;
    PGresult *ars_result;

    cacheroot_t cacheroot;

#ifdef	PROC_TRACE
    traceFunc("== main(%d, %p)\n", argc, argv);
#endif	/* PROC_TRACE */

#ifdef	MEMORY_TRACING
    mcheck(0);
#endif	/* MEMORY_TRACING */
#ifdef	GLOBAL_DEBUG
    gl.DEEBUG = gl.MEM_DEEBUG = 0;
#endif	/* GLOBAL_DEBUG */

    files_to_be_scanned = calloc(argc, sizeof(char *));

    /*
     Set up variables global to the agent. Ones that are the
     same for all scans.
     */
    gl.DB = DBopen();
    if (!gl.DB) {
        printf("FATAL: Nomos agent unable to connect to database, exiting...\n");
        fflush(stdout);
        exit(-1);
    }

    /* MD: move the call the GetAgentKey to the -i code? does that cause other
     * issues?
     */

    gl.agentPk = GetAgentKey(gl.DB, basename(argv[0]), 0, SVN_REV, agent_desc);
    gl.pgConn = DBgetconn(gl.DB);

    /* Record the progname name */
    if ((cp = strrchr(*argv, '/')) == NULL_STR) {
        (void) strcpy(gl.progName, *argv);
    }
    else {
        while (*cp == '.' || *cp == '/') {
            cp++;
        }
        (void) strcpy(gl.progName, cp);
    }

    if (putenv("LANG=C") < 0) {
        perror("putenv");
        Fatal("Cannot set LANG=C in environment");
    }
    unbufferFile(stdout);
    (void) umask(022);

    /* Grab miscellaneous things from the environent */
    if (getcwd(gl.initwd, sizeof(gl.initwd)) == NULL_STR) {
        perror("getcwd");
        Fatal("Cannot obtain starting directory");
    }
    /* DBug: printf("After getcwd in main, starting dir is:\n%s\n", gl.initwd); */

    gl.uPsize = 6;

    /* Build the license ref cache to hold 2**11 (2048) licenses.
       This MUST be a power of 2.
     */
    cacheroot.maxnodes = 2<<11;
    cacheroot.nodes = calloc(cacheroot.maxnodes, sizeof(cachenode_t));
    if (!initLicRefCache(&cacheroot))
    {
      printf("Nomos could not allocate cacheroot nodes\n");
      exit(1);
    }

    /*
     Deal with command line options
     */
    scheduler_connect(&argc, argv);
    while ((c = getopt(argc, argv, "hi")) != -1) {

        /* printf("start of while; argc is:%d\n", argc); */
        /* for(i=0; i<argc; i++){
         printf("args passed in:%s\n",argv[i]);
         }
         */
        switch (c) {
        case 'i':
            /* "Initialize" */
            DBclose(gl.DB); /* DB was opened above, now close it and exit */
            exit(0);
        case 'h':
        default:
            Usage(argv[0]);
            DBclose(gl.DB);
            exit(-1);
        }
    }

    /*
     Copy filename args (if any) into array
     */

    for (i = 1; i < argc; i++) {
        /* printf("argv's are:%s\n", argv[i]); */
        files_to_be_scanned[i - 1] = argv[i];
        file_count++;
    }
    /* printf("after parse args, argc is:%d\n", argc); DEBUG */

    licenseInit();
    gl.flags = 0;

    if (file_count == 0) 
    {
        char *repFile;

        /* We're being run from the scheduler */
        /* DEBUG printf("   LOG: nomos agent starting up in scheduler mode....\n"); */
        schedulerMode = 1;

        /* read upload_pk from scheduler */
        while (scheduler_next() != NULL)
        {
          upload_pk = atoi(scheduler_current());
          if (upload_pk == 0) continue; 

          /* Is this a duplicate request (same upload_pk, sameagent_fk)?
             If so, there is no point in repeating it.
           */
          snprintf(sqlbuf, sizeof(sqlbuf), 
                 "select ars_pk from nomos_ars,agent \
                  where agent_pk=agent_fk and ars_success=true \
                    and upload_fk='%d' and agent_fk='%d'",
                   upload_pk, gl.agentPk);
          result = PQexec(gl.pgConn, sqlbuf);
          if (checkPQresult(result, sqlbuf, __FILE__, __LINE__)) exit(-1);
          if (PQntuples(result) != 0) 
          {
            printf("LOG: Ignoring requested nomos analysis of upload %d - Results are already in database.\n",
                  upload_pk);
            continue;
          }
          PQclear(result);

          /* Record analysis start in nomos_ars, the nomos audit trail. */
          snprintf(sqlbuf, sizeof(sqlbuf),
                  "insert into nomos_ars (agent_fk, upload_fk, ars_success) values(%d,%d,'%s');",
                    gl.agentPk, upload_pk, "false");
          ars_result = PQexec(gl.pgConn, sqlbuf);
          if (checkPQcommand(ars_result, sqlbuf, __FILE__ ,__LINE__)) return -1;

          /* retrieve the ars_pk of the newly inserted record */
          sprintf(sqlbuf, "select ars_pk from nomos_ars \
                            where agent_fk='%d' and upload_fk='%d' \
                            and ars_success='%s' and ars_endtime is null \
                            order by ars_starttime desc limit 1",
                            gl.agentPk, upload_pk, "false");
          ars_result = PQexec(gl.pgConn, sqlbuf);
          if (checkPQresult(ars_result, sqlbuf, __FILE__, __LINE__)) return -1;
          if (PQntuples(ars_result) == 0)
          {
            printf("FATAL: (%s.%d) Missing nomos_ars record.\n%s\n",__FILE__,__LINE__,sqlbuf);
            return -1;
          }
          ars_pk = atol(PQgetvalue(ars_result, 0, 0));
          PQclear(ars_result);

          /* retrieve the records to process */
          snprintf(sqlbuf, sizeof(sqlbuf),
                   "SELECT pfile_pk, pfile_sha1 || '.' || pfile_md5 || '.' || pfile_size AS pfilename FROM (SELECT distinct(pfile_fk) AS PF FROM uploadtree WHERE upload_fk='%d' and (ufile_mode&x'3C000000'::int)=0) as SS left outer join license_file on (PF=pfile_fk and agent_fk='%d') inner join pfile on (PF=pfile_pk) WHERE fl_pk IS null",
                   upload_pk, gl.agentPk);
          result = PQexec(gl.pgConn, sqlbuf);
          if (checkPQresult(result, sqlbuf, __FILE__, __LINE__)) exit(-1);
          numrows = PQntuples(result);

          /* process all files in this upload */
          for (i=0; i<numrows; i++)
          {
            strcpy(cur.pFile, PQgetvalue(result, i, 1));
            cur.pFileFk = atoi(PQgetvalue(result, i, 0));
        
            repFile = RepMkPath("files", cur.pFile);
            if (!repFile) 
            {
              printf("FATAL: pfile %ld Nomos unable to open file %s\n", cur.pFileFk, cur.pFile);
              fflush(stdout);
              DBclose(gl.DB);
              exit(-1);
            }

            /* make sure this is a regular file, ignore if not */
            if (0 == isFILE(repFile)) continue;

            processFile(repFile);
            if (recordScanToDB(&cacheroot, &cur))
            {
              printf("FATAL: nomos terminating on upload %d due to previous errors.",
                     upload_pk);
              exit(-99);
            }
            freeAndClearScan(&cur);

            scheduler_heart(1);
          }
          PQclear(result);

          /* Record analysis success in nomos_ars. */
          snprintf(sqlbuf, sizeof(sqlbuf),
                  "update nomos_ars set ars_endtime=now(), ars_success=true where ars_pk='%d'",
                   ars_pk);
          result = PQexec(gl.pgConn, sqlbuf);
          if (checkPQcommand(result, sqlbuf, __FILE__ ,__LINE__)) return -1;
        }
    }
    else {
        /*
         Files on the command line
         */
        /* printf("Main: running in cli mode, processing file(s)\n"); */
        cur.cliMode = 1;
        for (i = 0; i < file_count; i++) {
            processFile(files_to_be_scanned[i]);
            recordScanToDB(&cacheroot, &cur);
            freeAndClearScan(&cur);
        }
    }
    lrcache_free(&cacheroot);  // for valgrind
    scheduler_disconnect();
    Bail(100);

    /* this will never execute but prevents a compiler warning about reaching 
     the end of a non-void function */
    return (0);
}

