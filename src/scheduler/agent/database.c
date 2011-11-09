/* **************************************************************
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
 ************************************************************** */

/* local includes */
#include <agent.h>
#include <database.h>
#include <logging.h>
#include <scheduler.h>

/* std library includes */

/* other library includes */
#include <libfossdb.h>

/* ************************************************************************** */
/* **** data and sql statements ********************************************* */
/* ************************************************************************** */

PGconn* db_conn = NULL;
char fossy_url[256];

/* email related sql */
const char* url_checkout = "\
    SELECT conf_value FROM sysconfig\
      WHERE variablename = 'FOSSology_URL';";

const char* select_upload_fk =" \
    SELECT job_upload_fk FROM job, jobqueue \
      WHERE jq_job_fk = job_pk and jq_pk = %d;";

const char* upload_common = "\
    SELECT * FROM jobqueue \
      WHERE jq_job_fk in ( \
        SELECT job_pk FROM job \
          WHERE job_upload_fk = %d \
      );";

const char* jobsql_email = "\
    SELECT user_name, user_email, email_notify FROM users, upload \
      WHERE user_pk = user_fk AND upload_pk = %d;";

/* job queue related sql */
const char* basic_checkout = "\
    SELECT * FROM getrunnable()\
      LIMIT 10;";

const char* change_priority = "\
    SELECT job_priority FROM job \
      WHERE job_pk = %s;";

const char* jobsql_started = "\
    UPDATE jobqueue \
      SET jq_starttime = now(), \
          jq_schedinfo ='%s.%d', \
          jq_endtext = 'Started' \
      WHERE jq_pk = '%d';";

const char* jobsql_complete = "\
    UPDATE jobqueue \
      SET jq_endtime = now(), \
          jq_end_bits = jq_end_bits | 1, \
          jq_schedinfo = null, \
          jq_endtext = 'Completed' \
      WHERE jq_pk = '%d';";

const char* jobsql_restart = "\
    UPDATE jobqueue \
      SET jq_endtime = now(), \
          jq_schedinfo = null, \
          jq_endtext = 'Restart' \
      WHERE jq_pk = '%d';";

const char* jobsql_failed = "\
    UPDATE jobqueue \
      SET jq_endtime = now(), \
          jq_end_bits = jq_end_bits | 2, \
          jq_schedinfo = null, \
          jq_endtext = 'Failed' \
      WHERE jq_pk = '%d';";

const char* jobsql_processed = "\
    Update jobqueue \
      SET jq_itemsprocessed = %d \
      WHERE jq_pk = '%d';";

const char* jobsql_paused = "\
    UPDATE jobqueue \
      SET jq_endtext = 'Paused' \
      WHERE jq_pk = '%d';";

const char* jobsql_log = "\
    UPDATE jobqueue \
      SET jq_log = '%s' \
      WHERE jq_pk = '%d';";

/* ************************************************************************** */
/* **** email format ******************************************************** */
/* ************************************************************************** */

const char* email_fmt = "\
Dear %s,\nDo not reply to this message. This is an automatically generated \
message by the FOSSolgy system.\n\n";

/* ************************************************************************** */
/* **** local functions ***************************************************** */
/* ************************************************************************** */

/**
 * Initializes any one-time attributes relating to the database. Currently this
 * includes creating the db connection and checking the URL of the fossology
 * instance out of the db.
 */
void database_init()
{
  PGresult* db_result;
  char *DBConfFile = NULL;  /* use default Db.conf */
  char *ErrorBuf;

  /* create the connection to the database */
  db_conn = fo_dbconnect(DBConfFile, &ErrorBuf);
  memset(fossy_url, '\0', sizeof(fossy_url));

  /* get the url for the fossology instance */
  db_result = PQexec(db_conn, url_checkout);
  if(PQresultStatus(db_result) != PGRES_TUPLES_OK && PQntuples(db_result) != 0)
    strcpy(fossy_url, PQgetvalue(db_result, 0, 0));
  PQclear(db_result);
}

/**
 * close the connection to the database
 */
void database_destroy()
{
  PQfinish(db_conn);
  db_conn = NULL;
}

/**
 * TODO, unfinished function, needs the email construction
 *
 * @param job_id
 * @param failed
 */
void email_notification(int job_id, int failed)
{
  PGresult* db_result;
  int tuples;
  int i;
  int col;
  int upload_id;
  char* val;
  char sql[1024];

  sprintf(sql, select_upload_fk, job_id);
  db_result = PQexec(db_conn, sql);
  if(PQresultStatus(db_result) != PGRES_TUPLES_OK)
  {
    PQ_ERROR(db_result, "unable to select the upload id for job %d", job_id);
    return;
  }
  upload_id = atoi(PQgetvalue(db_result, 0, 0));
  PQclear(db_result);

  sprintf(sql, upload_common, upload_id);
  db_result = PQexec(db_conn, sql);
  if(PQresultStatus(db_result) != PGRES_TUPLES_OK)
  {
    PQ_ERROR(db_result, "unable to check common uploads to job %d", job_id);
    return;
  }

  tuples = PQntuples(db_result);
  col = PQfnumber(db_result, "jq_endtext");
  for(i = 0; i < tuples; i++)
  {
    val = PQgetvalue(db_result, i, col);
    if(strcmp(val, "Started") == 0 ||
       strcmp(val, "Paused")  == 0 ||
       strcmp(val, "Restart") == 0 )
    {
      PQclear(db_result);
      return;
    }
  }
  PQclear(db_result);

  sprintf(sql, jobsql_email, upload_id);
  db_result = PQexec(db_conn, sql);
  if(PQresultStatus(db_result) != PGRES_TUPLES_OK)
  {
    PQ_ERROR(db_result, "unable to access email info for job %d", job_id);
    return;
  }

  if(PQget(db_result, 0, "email_notify")[0] == 'y')
  {
    sprintf(sql, email_fmt, PQget(db_result, 0, "user_name"));



  }

  PQclear(db_result);
}

/* ************************************************************************** */
/* **** event and functions ************************************************* */
/* ************************************************************************** */

void database_exec_event(char* sql)
{
  PGresult* db_result = PQexec(db_conn, sql);

  if(PQresultStatus(db_result) != PGRES_COMMAND_OK)
  {
    lprintf("ERROR %s.%d: failed to perform database exec\n");
    lprintf("ERROR sql: \"%s\"\n", sql);
    lprintf("ERROR postgresql error: %s\n", PQresultErrorMessage(db_result));
  }

  PQclear(db_result);
  g_free(sql);
}

/**
 * Resets the any jobs in the job queue that are not completed. This is to make
 * sure that any jobs that were running with the scheduler shutdown are run correctly
 * when it starts up again.
 */
void database_reset_queue()
{
  PGresult* db_result = PQexec(db_conn, "\
      UPDATE jobqueue \
        SET jq_starttime=null, \
            jq_endtext=null, \
            jq_schedinfo=null\
        WHERE jq_endtime is NULL;");

  if(PQresultStatus(db_result) != PGRES_COMMAND_OK)
  {
    lprintf("ERROR %s.%d: failed to reset job queue\n");
    lprintf("ERROR postgresql error: %s\n", PQresultErrorMessage(db_result));
  }

  PQclear(db_result);
}

/**
 * Checks the job queue for any new entries.
 *
 * @param unused
 */
void database_update_event(void* unused)
{
  /* locals */
  PGresult* db_result;
  PGresult* pri_result;
  int i, job_id;
  char sql[512];
  char* value, * type, * pfile, * parent;
  job j;

  if(closing)
  {
    lprintf("ERROR %s.%d: scheduler is closing, will not perform database update\n", __FILE__, __LINE__);
    return;
  }

  /* make the database query */

  db_result = PQexec(db_conn, basic_checkout);
  if(PQresultStatus(db_result) != PGRES_TUPLES_OK)
  {
    lprintf("ERROR %s.%d: database update failed on call to PQexec\n", __FILE__, __LINE__);
    lprintf("ERROR postgresql error: %s\n", PQresultErrorMessage(db_result));
    PQclear(db_result);
    return;
  }

  VERBOSE2("DB: retrieved %d entries from the job queue\n", PQntuples(db_result));
  for(i = 0; i < PQntuples(db_result); i++)
  {
    /* start by checking that the job hasn't already been grabed */
    if(get_job(job_id = atoi(PQget(db_result, i, "jq_pk"))) != NULL)
      continue;

    /* get relevant values out of the job queue */
    parent =      PQget(db_result, i, "jq_job_fk");
    type   =      PQget(db_result, i, "jq_type");
    pfile  =      PQget(db_result, i, "jq_runonpfile");
    value  =      PQget(db_result, i, "jq_args");

    VERBOSE2("DB: jq_pk[%d] added:\n   jq_type = %s\n   jq_runonpfile = %d\n   jq_args = %s\n",
        job_id, type, (pfile != NULL && pfile[0] != '\0'), value);

    /* check if this is a command */
    if(strcmp(type, "command") == 0)
    {
      lprintf("DB: got a command from job queue\n");
      // TODO handle command
      continue;
    }

    sprintf(sql, change_priority, parent);
    pri_result = PQexec(db_conn, sql);
    if(PQresultStatus(pri_result) != PGRES_TUPLES_OK)
    {
      lprintf("ERROR %s.%d: database update failed on call to PQexec\n", __FILE__, __LINE__);
      lprintf("ERROR postgresql error: %s\n", PQresultErrorMessage(db_result));
      PQclear(db_result);
      continue;
    }

    j = job_init(type, job_id, atoi(PQgetvalue(pri_result, 0, 0)));
    job_set_data(j, value, (pfile && pfile[0] != '\0'));

    PQclear(pri_result);
  }

  PQclear(db_result);
}

/**
 * Change the status of a job in the database.
 *
 * @param j_id id number of the relevant job
 * @param status the new status of the job
 */
void database_update_job(int j_id, job_status status)
{
  /* locals */
  gchar* sql = NULL;
  PGresult* db_result;

  /* check how to update database */
  switch(status)
  {
    case JB_CHECKEDOUT:
      break;
    case JB_STARTED:
      sql = g_strdup_printf(jobsql_started, "localhost", getpid(), j_id);
      break;
    case JB_COMPLETE:
      //email_notification(j_id, 0);
      sql = g_strdup_printf(jobsql_complete, j_id);
      break;
    case JB_RESTART:
      sql = g_strdup_printf(jobsql_restart, j_id);
      break;
    case JB_FAILED:
      //email_notification(j_id, 1);
      sql = g_strdup_printf(jobsql_failed, j_id);
      break;
    case JB_SCH_PAUSED: case JB_CLI_PAUSED:
      sql = g_strdup_printf(jobsql_paused, j_id);
      break;
  }

  /* update the database job queue */
  db_result = PQexec(db_conn, sql);
  if(sql != NULL && PQresultStatus(db_result) != PGRES_COMMAND_OK)
  {
    lprintf("ERROR %s.%d: failed to update job status in job queue\n", __FILE__, __LINE__);
    lprintf("ERROR postgresql error: %s\n", PQresultErrorMessage(db_result));
  }
  PQclear(db_result);
  g_free(sql);
}

/**
 * Updates teh number of items that a job queue entry has processed.
 *
 * @param j_id the id number of the job queue entry
 * @param num the number of items processed in total
 */
void database_job_processed(int j_id, int num)
{
  gchar* sql = NULL;

  sql = g_strdup_printf(jobsql_processed, j_id, num);
  event_signal(database_exec_event, sql);
}

/**
 * enters the name of the log file for a job into the database
 *
 * @param j_id the id number for the relevant job
 * @param log_name the name of the log file
 */
void database_job_log(int j_id, char* log_name)
{
  gchar* sql = NULL;

  sql = g_strdup_printf(jobsql_log, log_name, j_id);
  event_signal(database_exec_event, sql);
}


