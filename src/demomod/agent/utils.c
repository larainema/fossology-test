/***************************************************************
 Copyright (C) 2013 Hewlett-Packard Development Company, L.P.

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
 * \file utils.c
 * \brief Miscellaneous utility functions for demomod
 */

#include "demomod.h"

/**********  Globals  *************/
extern psqlCopy_t psqlcpy;       // fo_sqlCopy struct used for fast data insertion
extern PGconn    *pgConn;        // database connection

/**
 * @brief Check to make sure the demomod and demomod_ars tables exists.
 *        If they don't, then create them.
 * @param AgentARSName Name of _ars table
 *
 * @returns void  Can call ExitNow() on fatal error.
 */
FUNCTION void CheckTable(char *AgentARSName) 
{
  PGresult* result; // the result of the database access
  int rv;             // generic return value
  char *TableName = "demomod";
  char *CreateTableSQL =
       "CREATE TABLE demomod ( \
          demomod_pk serial NOT NULL PRIMARY KEY, \
          pfile_fk integer, \
          firstbytes character(32), \
        FOREIGN KEY (pfile_fk) REFERENCES pfile(pfile_pk) ON DELETE CASCADE \
        ); \
        COMMENT ON TABLE demomod IS 'table for demo module'; \
        COMMENT ON COLUMN demomod.firstbytes IS 'first 32 bytes of the pfile'; \
      ";

//ALTER TABLE ONLY demomod ADD CONSTRAINT demomod_pfile_fk_fkey FOREIGN KEY (pfile_fk) REFERENCES pfile(pfile_pk) ON DELETE CASCADE;
//ALTER TABLE ONLY demomod ADD CONSTRAINT demomod_pkey PRIMARY KEY (demomod_pk);

  /* Check if the demomod_ars table exists.  If not, create it.  
   * The _ars tables serve as an audit trail.  They tell you when an agent
   * has run and what the parameters were.  They also provide a database service.
   * Without the _ars tables, the agent would have to run in a single transaction,
   * potentially inserting millions of records in that single transaction.  This
   * can result in deadlocks, large memory consumption, and slower performance.
   * Because of the _ars tables, we can run in multiple transactions.
   */
  rv = fo_tableExists(pgConn, AgentARSName);
  if (!rv)
  {
    rv = fo_CreateARSTable(pgConn, AgentARSName);
    if (!rv)
    {
      LOG_FATAL("%s table could not be created", AgentARSName);
      ExitNow(-10);
    }
  }

  /* Check if TableName exists.  If not, create it 
   * An easy way to get all the table creation sql is to create the table with all your
   * constraints manually or with phppgadmin, then export the table definition.
   */
  rv = fo_tableExists(pgConn, TableName);
  if (!rv)
  {
     result = PQexec(pgConn, CreateTableSQL);
     if (fo_checkPQresult(pgConn, result, CreateTableSQL, __FILE__, __LINE__)) 
     {
       LOG_ERROR("Failed to create %s table.", TableName);
       ExitNow(-11);
     }
  }

}

/**
 * @brief Exit function.  This does all cleanup and should be used
 *        instead of calling exit() or main() return.
 *
 * @param ExitVal Exit value
 * @returns void Calls exit()
 */
FUNCTION void ExitNow(int ExitVal) 
{
  int FlushWriteBuff = 1;  // default to flush fo_sqlCopy buffer

  if (pgConn) 
  {
    if (ExitVal) FlushWriteBuff = 0;  // don't bother flushing db writes if there is a non zero ExitVal (i.e. an error)
    fo_sqlCopyDestroy(psqlcpy, FlushWriteBuff);
    PQfinish(pgConn);
  }

  fo_scheduler_disconnect(ExitVal);
  exit(ExitVal);
} /* ExitNow() */
