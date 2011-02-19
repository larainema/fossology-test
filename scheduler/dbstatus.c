/*******************************************************
 dbstatus: Functions for updating the DB status.

 Copyright (C) 2007 Hewlett-Packard Development Company, L.P.
 
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
 *******************************************************/

#include <stdlib.h>
#include <stdio.h>
#include <string.h>
#include <ctype.h>
/* for signals */
#include <sys/types.h>
#include <signal.h>

#include <libfossdb.h>
#include <libfossrepo.h>
#include "scheduler.h"
#include "spawn.h"
#include "sockets.h"
#include "agents.h"
#include "dbq.h"
#include "hosts.h"
#include "logging.h"

char Hostname[128]; /* should be 64 max, but overkill is fine */

/***********************************************************
 DBSetHostname(): Basic initialization.
 ***********************************************************/
void	DBSetHostname	()
{
  memset(Hostname,'\0',sizeof(Hostname));
  gethostname(Hostname,127);
} /* DBSetHostname() */

/***********************************************************
 DBstrcatTaint(): Add a string (V) to a string (S), quoting as needed.
 Returns: 1 on success, 0 on problem (e.g., overflow)
 ***********************************************************/
int	DBstrcatTaint	(char *V, char *S, int MaxS)
{
  int s,v; /* indexes */
  s=strlen(S);
  for(v=0; (V[v] != '\0') && (s < MaxS); v++)
  {
    switch(V[v])
    {
      case '\n':
        S[s++]='\\'; S[s++]='n';
        break;
      case '\r':
        S[s++]='\\'; S[s++]='r';
        break;

      case '"': case '`': case '$': case '\\': case '\'':
        S[s++]='\\'; S[s++]=V[v];
        break;

      default:
        S[s++]=V[v];
        break;
    }
  }
  if (V[v]=='\0') return(1);
  return(0);
} /* DBstrcatTaint() */

/***********************************************************
 DBMkArgCols(): Convert a DB row into an arg.
 This consists of field="value" pairs.
 Special characters are quoted.
 ***********************************************************/
void	DBMkArgCols	(void *DB, int Row, char *Arg, int MaxArg)
{
  char *Value;
  int c, Maxc; /* column value */

  memset(Arg,'\0',MaxArg);
  Maxc = DBcolsize(DB);
  for(c=0; c<Maxc; c++)
  {
    Value = DBgetvalue(DB,Row,c);
    if (Value)
    {
      if (Arg[0] != '\0') strcat(Arg," ");
      strcat(Arg,DBgetcolname(DB,c));
      strcat(Arg,"=\"");
      DBstrcatTaint(Value,Arg,MaxArg);
      strcat(Arg,"\"");
    }
  }
  strcat(Arg,"\n");	/* add a \n to the line */
} /* DBMkArgCols() */

/***********************************************************
 DBLockAccess(): DBaccess with signal locking.
 ***********************************************************/
inline int	DBLockAccess	(void *VDB, char *SQL)
{
  int rc;
  sigset_t Mask, OldMask;

  /*****
   The problem being solved: Some signals, like SIGCHLD, cause
   calls to DBaccess(). If DBaccess() is called WHILE DBaccess()
   is called, then common libraries get confused.

   What we are currently seeing (real case):
     - An agent fails (in this case, wget_agent failed to get the URL).
     - The agent sends a "LOG" command, causing a call to DBaccess().
     - Since the fflush(stdout) from the agent completes, the child
       terminates.  (wget_agent exists)
     - During the scheduler's processing of the DBaccess(LOG), it
       receives a SIGCHLD.  This jumps to the child signal handler.
     - The child signal handler calls DBaccess() to record the failure.
       Then it returns.
   The function that hangs is libc's "poll()" called by the Postgres
   library.  The poll() function uses a timer and since it already
   completed (from the child signal hander), it ends up hanging forever.

   The solution: block any signal that would lead to a DBaccess() call
   during a DBaccess() call.
   *****/

  /* Set the mask */
  sigemptyset(&Mask);
  sigaddset(&Mask,SIGCHLD);
  sigaddset(&Mask,SIGTERM);
  sigaddset(&Mask,SIGQUIT);
  sigaddset(&Mask,SIGINT);
  sigprocmask(SIG_BLOCK,&Mask,&OldMask);
  rc = DBaccess(VDB,SQL);
  sigprocmask(SIG_UNBLOCK,&OldMask,NULL);
  return(rc);
} /* DBLockAccess() */

/***********************************************************
 DBLockReconnect(): DBclose and DBopen.
 ***********************************************************/
void	DBLockReconnect	()
{
  sigset_t Mask, OldMask;

  /*****
   The problem being solved:
   Postgres seems to have a problem on AMD64 processors.
   (NOTE: This problem has not been seen on i686 processors.) 
   If a connection is held open and heavily used for a long time
   (e.g., 24-72 hours with SELECT and UPDATE every few seconds) then
   the DB can become sluggish and non-responsive.  Eventually, the client
   hangs forever.
   Some of the observed symptoms:
   - A few postmaster processes grow in memory usage -- from 1M to 10M
     or larger.
   - psql queries like "select * from pfile" work fast, but "select * from
     pfile where <any clause>" hang forever.  Simply having the WHERE
     clause causes the hang.  Pressing Control-C can take minutes to cancel
     the request.
   - The delays get larger and larger, and eventually stop.
   - To reset: Stop the scheduler, stop the db, restart the db, restart
     the scheduler.  Suddenly everything is faster.  (If you only stop the
     scheduler, then the problem does not go away.)
   This function is an attempt to reset the connection and allow
   postgres to cleanup periodically.  It should be called at least hourly.
   *****/

  /* Set the mask */
  sigemptyset(&Mask);
  sigaddset(&Mask,SIGCHLD);
  sigaddset(&Mask,SIGTERM);
  sigaddset(&Mask,SIGQUIT);
  sigaddset(&Mask,SIGINT);
  sigprocmask(SIG_BLOCK,&Mask,&OldMask);
  DBclose(DB);
  DB = DBopen();
  if (!DB)
  {
    LogPrint("FATAL: Scheduler unable to reconnect to the database.\n");
    exit(-1);
  }
  sigprocmask(SIG_UNBLOCK,&OldMask,NULL);
  return;
} /* DBLockReconnect() */

/***********************************************************
 DBUpdateJob(): Update a particular job in the DB.
 JobId = jq_pk
 Possible update modes:
   0 = working on it
   1 = completed
   2 = mark for repeat
   3 = failure
 ***********************************************************/
void	DBUpdateJob	(int JobId, int UpdateType, char *Message)
{
  int rc;
  char SQL[MAXCMD];
  int Len;
  memset(SQL,'\0',MAXCMD);
  switch(UpdateType)
  {
    case 0:	/* mark the DB entry as work in progress */
      snprintf(SQL,MAXCMD,"UPDATE jobqueue SET jq_starttime = now(), jq_schedinfo ='%s.%d'",Hostname,getpid());
      break;
    case 1:	/* mark the DB entry as completed */
      /* If you see endtime without starttime, then this is the culprit */
      snprintf(SQL,MAXCMD,"UPDATE jobqueue SET jq_endtime = now(), jq_end_bits = jq_end_bits | 1, jq_schedinfo = null");
      break;
    case 3:	/* mark the DB entry as ready to fail */
      snprintf(SQL,MAXCMD,"UPDATE jobqueue SET jq_endtime = now(), jq_end_bits = jq_end_bits | 2, jq_schedinfo = null");
      break;
    case 2:	/* mark the DB entry as ready to try again */
    default:
      snprintf(SQL,MAXCMD,"UPDATE jobqueue SET jq_starttime = null, jq_endtime = null, jq_schedinfo = null");
      break;
  }
  Len = strlen(SQL);
  if (Message)
  {
    snprintf(SQL+Len,MAXCMD-Len,", jq_endtext = ");
    Len = strlen(SQL);
    switch(UpdateType)
    {
      case 0: snprintf(SQL+Len,MAXCMD-Len,"'Started: %s'",Message); break;
      case 1: snprintf(SQL+Len,MAXCMD-Len,"'Completed: %s'",Message); break;
      case 2: snprintf(SQL+Len,MAXCMD-Len,"'Restart: %s'",Message); break;
      case 3: snprintf(SQL+Len,MAXCMD-Len,"'Failed: %s'",Message); break;
      default:
        snprintf(SQL+Len,MAXCMD-Len,"'%s'",Message);
        break;
    }
  }
  else
  {
    snprintf(SQL+Len,MAXCMD-Len,", jq_endtext = ");
    Len = strlen(SQL);
    switch(UpdateType)
    {
      case 0: snprintf(SQL+Len,MAXCMD-Len,"'Started'"); break;
      case 1: snprintf(SQL+Len,MAXCMD-Len,"'Completed'"); break;
      case 2: snprintf(SQL+Len,MAXCMD-Len,"'Restart'"); break;
      case 3: snprintf(SQL+Len,MAXCMD-Len,"'Failed'"); break;
      default:
        break;
    }
  }
  Len = strlen(SQL);
  snprintf(SQL+Len,MAXCMD-Len," WHERE jq_pk = '%d';",JobId);
  if (Verbose)
  {
    LogPrint("SQL Update: '%s'\n",SQL);
  }
  rc = DBLockAccess(DB,SQL);
  if (rc >= 0) return;

  /* How to handle a DB error? Right now, they are just logged per agent */
  /* TBD: This will be implemented when we have interprocess communication
     between the scheduler and UI. */
  LogPrint("ERROR: Unable to process: '%s'\n",SQL);
  return;
} /* DBUpdateJob() */

/***************************************************************************/
/***************************************************************************/
/******** Save Scheduler State to DB ***************************************/
/***************************************************************************/
/***************************************************************************/

/**********************************************
 DBCheckStatus(): Remove any status codes that
 are older than the given time.
 **********************************************/
void	DBCheckStatus	()
{
  void *DBs;
  char SQL[MAXCMD];
  int i;

  /** Delete anything older than 10 minutes **/
  DBLockAccess(DB,"SELECT unique_scheduler FROM scheduler_status WHERE record_update < now() - interval '600';");
  if (DBdatasize(DB) > 0)
  {
    /* Reclaim the old scheduler jobs */
    DBs = DBmove(DB);
    for(i=0; i<DBdatasize(DBs); i++)
    {
      memset(SQL,'\0',MAXCMD);
      snprintf(SQL,MAXCMD-1,"UPDATE jobqueue SET jq_starttime = NULL, jq_schedinfo = NULL, jq_endtext = 'Released for restart' WHERE jq_schedinfo = '%s' AND jq_starttime is not NULL AND jq_endtime is NULL;",DBgetvalue(DBs,i,0));
      DBLockAccess(DB,SQL);
    }
    DBclose(DBs);
    /* Delete the old scheduler status (more than 4 min old) 
     * Synchronized with fo_watchdog and spawn.c > SaveStatus()
     * */
    DBLockAccess(DB,"DELETE FROM scheduler_status WHERE record_update < now() - interval '240';");
  }
} /* DBCheckStatus() */

/**********************************************
 DBCheckSchedulerUnique(): When starting up, make
 sure there are no other competing schedulers.
 Warn if there are any other schedulers running
 the same processes.
 This assumes: DB is open, DBSaveSchedulerStatus has NOT
 been called, and agent table is loaded.
 Returns the number of warnings issued.
 **********************************************/
int	DBCheckSchedulerUnique	()
{
  int rv = 0;
  int Thread;
  int Row,MaxRow;
  char Attr[256];
  char *DBattr;
  int *DBattrChecked;

  /* Find all running jobs */
  /** For for anything under 20 seconds old since schedulers update every
      ten seconds **/
  DBLockAccess(DB,"SELECT distinct unique_scheduler,agent_attrib FROM scheduler_status WHERE record_update >= now() - interval '20';");
  MaxRow = DBdatasize(DB);
  DBattrChecked = (int *)calloc(MaxRow,sizeof(int));

  for(Thread=0; Thread < MaxThread; Thread++)
  {
    memset(Attr,0,sizeof(Attr));
    strncpy(Attr,GetValueFromAttr(CM[Thread].Attr,"agent="),sizeof(Attr)-1);
    for(Row=0; Row < MaxRow; Row++)
    {
      if (DBattrChecked[Row]) continue;
      DBattr = DBgetvalue(DB,Row,1);
      DBattr = GetValueFromAttr(DBattr,"agent=");
      if (DBattr && !strcmp(Attr,DBattr))
      {
        rv++;  // add up warnings
        LogPrint("WARNING: Competing scheduler for '%s' detected: %s ",
            Attr,DBgetvalue(DB,Row,0));
        LogPrint("%s\n",DBgetvalue(DB,Row,1));
        DBattrChecked[Row] = 1; /* only report it once */
      }
    }
  }
  free(DBattrChecked);
  return(rv);
} /* DBCheckSchedulerUnique() */

/**********************************************
 DBSaveSchedulerStatus(): Save the status code for a thread.
 Use Thread==-1 for the scheduler itself.
 **********************************************/
void	DBSaveSchedulerStatus	(int Thread, char *StatusName)
{
  char SQL[MAXCMD];
  char Args[MAXCMD];
  char *Value;
  char Empty[2]="";
  int rc;
  static int DBDead=0;
  char Ctime[MAXCTIME];

  if ((Thread >= 0) && (CM[Thread].DBagent < 0))
  {
    CM[Thread].DBagent = DBGetAgentIndex(CM[Thread].Attr,1);
  }

  /* Do an update */
  memset(SQL,'\0',MAXCMD);
  memset(Args,'\0',MAXCMD);
  memset(Ctime,'\0',MAXCTIME);
  /* Not checking string size since I know MAXCMD is much larger */
  if (Thread >= 0) ctime_r((&(CM[Thread].StatusTime)),Ctime);
  DBstrcatTaint( (Thread >= 0) ? CM[Thread].Parm : "" , Args,MAXCMD);
  sprintf(SQL,"UPDATE scheduler_status SET agent_status='%s', agent_status_date='%s', record_update=now(), agent_param=E'%s' WHERE unique_scheduler='%s.%d' AND agent_number='%d';",
      StatusName,
      (Thread >= 0) ? Ctime : "now()",
          Args,
          Hostname,getpid(),Thread);
  rc = DBLockAccess(DB,SQL);
  if (rc < 0)
  {
    LogPrint("FATAL: Scheduler failed to update status in DB. SQL was: \"%s\"\n",SQL);
    exit(-1);
  }

  /* If nothing updated, then do an INSERT instead */
  if (DBrowsaffected(DB) < 1)
  {
    memset(SQL,'\0',MAXCMD);
    memset(Ctime,'\0',MAXCTIME);
    Value = NULL;
    if (Thread >= 0)
    {
      Value = GetValueFromAttr(CM[Thread].Attr,"agent=");
      ctime_r((&(CM[Thread].StatusTime)),Ctime);
    }
    if (!Value) Value = Empty;
    sprintf(SQL,"INSERT INTO scheduler_status (unique_scheduler,agent_number,agent_attrib,agent_fk,agent_status,agent_status_date,agent_param,agent_host,record_update) VALUES ('%s.%d','%d','%s','%d','%s','%s','%s',",
        Hostname,getpid(),
        Thread,
        (Thread >= 0) ? CM[Thread].Attr : "scheduler",
            (Thread >= 0) ? CM[Thread].DBagent : -1,
                StatusName,
                (Thread >= 0) ? Ctime : "now()",
                    (Thread >= 0) ? CM[Thread].Parm : ""
    );
    if (Thread >= 0) Value = GetValueFromAttr(CM[Thread].Attr,"host=");
    if (!Value) Value = Empty;
    sprintf(SQL+strlen(SQL),"'%s',now());", (Thread >= 0) ? Value : Hostname);
    rc = DBLockAccess(DB,SQL);
    if (rc < 0)
    {
      LogPrint("FATAL: Scheduler failed to insert status in DB.\n");
      exit(-1);
    }
  }

  /* Idiot check */
  if (Thread < 0)
  {
    if (DBDead > 3)
    {
      LogPrint("FATAL: Scheduler had too many database connection retries.\n");
      exit(-1);
    }

    /* Make sure the scheduler actually updated its record. */
    memset(SQL,'\0',MAXCMD);
    sprintf(SQL,"SELECT * FROM scheduler_status WHERE agent_number=-1 AND unique_scheduler = '%s.%d';",Hostname,getpid());
    rc = DBLockAccess(DB,SQL);
    if ((rc < 0) || (DBdatasize(DB) <= 0))
    {
      time_t Now;
      Now = time(NULL);
      memset(Ctime,'\0',MAXCTIME);
      ctime_r(&Now,Ctime);
      LogPrint("ERROR: Scheduler lost connection to the database! %s",Ctime);
      LogPrint("  Dumping debug information.\n");
      DebugThreads(3);
      LogPrint("INFO: Scheduler attempting to reconnect to the database.\n");
      DBclose(DB);
      DB = DBopen();
      if (!DB)
      {
        LogPrint("FATAL: Scheduler unable to reconnect to the database.\n");
        exit(-1);
      }
      DBDead++;
      DBSaveSchedulerStatus(Thread,StatusName); /* retry */
    }
    else
    {
      DBDead = 0;
    }
  }
} /* DBSaveSchedulerStatus() */

/**********************************************
 DBSaveJobStatus(): Save the status for a job.
 Jobs may either be part of an MSQ or an independent task.
 MSQs should update when the entire MSQ is done.
 Independent tasks should be updated when they complete.
 The scheduler may also call this for periodic updates.
   - If Thread != -1, then use CM[Thread].DBJobKey 
   - Else, use MSQ[MSQid].JobId
 If both Thread and MSQid are -1, then it will scan ALL jobs.
 **********************************************/
void	DBSaveJobStatus	(int Thread, int MSQid)
{
  int JobPk=-1;
  int RealItemsProcessed;
  long ProcessCount=0;	/* add to jq_itemsprocessed */
  time_t ElapseTime=0;	/* add to jq_elapsedtime */
  time_t ProcessTime=0;	/* add to jq_processtime */
  time_t Now;
  sigset_t Mask, OldMask;

  sigemptyset(&Mask);
  sigaddset(&Mask,SIGCHLD);
  sigaddset(&Mask,SIGTERM);
  sigaddset(&Mask,SIGQUIT);
  sigaddset(&Mask,SIGINT);
  sigprocmask(SIG_BLOCK,&Mask,&OldMask);

  /* if false, ProcessCount is increment; if true ProcessCount is 
     real Items processed */
  RealItemsProcessed = 0;  
  Now = time(NULL);
  if (Thread >= 0)
  {
    if (!CM[Thread].IsDB) return;
    JobPk = CM[Thread].DBJobKey;
    CM[Thread].StatusLastDuration = Now - CM[Thread].StatusTime;
    CM[Thread].StatusTime = Now;
    ProcessCount = CM[Thread].ItemsProcessed;
    RealItemsProcessed = 1;  
    ProcessTime = CM[Thread].StatusLastDuration;
    ElapseTime = ProcessTime;
  }
  else if (MSQid >= 0)
  {
    JobPk = MSQ[MSQid].JobId;
    /* Update times for any running processes */
    for(Thread=0; Thread < MaxThread; Thread++)
    {
      /* If running job that is working on this MSQ, then update times. */
      if ((CM[Thread].Status == ST_RUNNING) && (CM[Thread].DBJobKey == JobPk))
      {
        CM[Thread].StatusLastDuration = Now - CM[Thread].StatusTime;
        CM[Thread].StatusTime = Now;
        MSQ[MSQid].ProcessTimeAgent += CM[Thread].StatusLastDuration;
        MSQ[MSQid].ProcessCount += CM[Thread].ItemsProcessed;
        CM[Thread].ItemsProcessed = 0;
      }
    }
    /* Grab times */
    ProcessTime  = MSQ[MSQid].ProcessTimeAgent;
    ElapseTime   = Now - MSQ[MSQid].ProcessTimeStart;
    ProcessCount = MSQ[MSQid].ProcessCount;
    /* Reset values since some MSQ may be done, but not all. */
    MSQ[MSQid].ProcessTimeStart = Now;
    MSQ[MSQid].ProcessTimeAgent = 0;
    MSQ[MSQid].ProcessCount = 0;
  }
  else	/* update all jobs */
  {
    for(Thread=0; Thread < MaxThread; Thread++)
    {
      if ((CM[Thread].DBJobKey >= 0) && (CM[Thread].IsDB==1))
        DBSaveJobStatus(Thread,-1);
    }
    for(MSQid=0; MSQid < MAXMSQ; MSQid++)
    {
      if (MSQ[MSQid].JobId >= 0) DBSaveJobStatus(-1,MSQid);
    }
    JobPk = -1; /* no job */
  }

  if (JobPk != -1)	/* ignore non-jobs */
  {
    char SQL[MAXCMD];
    memset(SQL,'\0',MAXCMD);
    if (RealItemsProcessed)
    {
      snprintf(SQL,MAXCMD,"UPDATE jobqueue SET jq_itemsprocessed=%ld, jq_elapsedtime=jq_elapsedtime+%d, jq_processedtime=jq_processedtime+%d WHERE jq_pk=%d;",
          ProcessCount,(int)ElapseTime,(int)ProcessTime,JobPk);
    }
    else
    {
      snprintf(SQL,MAXCMD,"UPDATE jobqueue SET jq_itemsprocessed=jq_itemsprocessed+%ld, jq_elapsedtime=jq_elapsedtime+%d, jq_processedtime=jq_processedtime+%d WHERE jq_pk=%d;",
          ProcessCount,(int)ElapseTime,(int)ProcessTime,JobPk);
    }
    if (DBLockAccess(DB,SQL) < 0)
    {
      LogPrint("FATAL: Scheduler failed to update job status in DB.\n");
      exit(-1);
    }
  }

  /* Done */
  sigprocmask(SIG_UNBLOCK,&OldMask,NULL);
} /* DBSaveJobStatus() */

/***********************************************************
 DebugMSQ(): Display the entire MSQ table for debugging.
 ***********************************************************/
void	DebugMSQ	()
{
  int m;
  int i;
  char Arg[MAXCMD];

  LogPrint("==============================================================\n");
  for(m=0; m < MAXMSQ; m++)
  {
    LogPrint("Multi-SQL Queue #%d\n",m);
    LogPrint("  Job (jq_pk) = %d\n",MSQ[m].JobId);
    if (MSQ[m].JobId >= 0)
    {
      LogPrint("  Is repeat (jq_repeat) = %d\n",MSQ[m].IsRepeat);
      LogPrint("  Is urgent = %d\n",MSQ[m].IsUrgent);
      LogPrint("  Items processed: %d out of %d\n",MSQ[m].ItemsDone,MSQ[m].MaxItems);
      LogPrint("  Type='%s' (agent type %d)\n",MSQ[m].Type,MSQ[m].DBagent);
      LogPrint("  Attr='%s'\n",MSQ[m].Attr);
      LogPrint("  Host found by column '%s'\n",MSQ[m].HostCol);
      for(i=0; i<MSQ[m].MaxItems; i++)
      {
        DBMkArgCols(MSQ[m].DBQ,i,Arg,MAXCMD);
        if (Arg[strlen(Arg)-1] == '\n')  Arg[strlen(Arg)-1]='\0';
        LogPrint("    Item %d: State=%s  '%s'\n",i,StatusName[MSQ[m].Processed[i]],Arg);
      }
    }
  }
} /* DebugMSQ() */

