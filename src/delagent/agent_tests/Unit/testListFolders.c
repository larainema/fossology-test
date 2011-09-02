/*********************************************************************
Copyright (C) 2011 Hewlett-Packard Development Company, L.P.

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
*********************************************************************/

/* cunit includes */
#include <CUnit/CUnit.h>
#include "delagent.h"
#include <string.h>

/**
 * \file testListFolders.c
 * \brief testing for the function ListFolders and ListUploads
 */

/**
 * \brief for function ListFolders
 */
void testListFolders()
{
  db_conn = fo_dbconnect();
  /** exectue the tested function */
  ListFolders();
  PQfinish(db_conn);
  CU_PASS("ListFolders PASS!");
}
/**
 * \brief for function ListUploads
 */
void testListUploads()
{
  db_conn = fo_dbconnect();
  /** exectue the tested function */
  ListUploads();

  PQfinish(db_conn);
  CU_PASS("ListUploads PASS!");
}

/**
 * \brief testcases for function ListFolders
 */
CU_TestInfo testcases_ListFolders[] =
{
#if 0
#endif
{"Testing the function ListFolders:", testListFolders},
{"Testing the function ListUploads:", testListUploads},
  CU_TEST_INFO_NULL
};

