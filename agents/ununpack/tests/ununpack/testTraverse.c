/*********************************************************************
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
*********************************************************************/

/* cunit includes */
#include <CUnit/CUnit.h>
#include "utility.h"

struct ParentInfo
  {
  int Cmd;      /* index into command table used to run this */
  time_t StartTime;     /* time when command started */
  time_t EndTime;       /* time when command ended */
  int ChildRecurseArtifact; /* child is an artifact -- don't log to XML */
  long uploadtree_pk;	/* if DB is enabled, this is the parent */
  };
typedef struct ParentInfo ParentInfo;

static char *Label = "called by main";
static char *Basename ="";
static ParentInfo *PI = NULL;
static int Result = 0;

/* used funtions */
int     Traverse        (char *Filename, char *Basename,
                         char *Label, char *NewDir,
                         int Recurse, ParentInfo *PI);

/**
 * @brief initialize
 */
int  TraverseInit()
{
  MagicCookie = magic_open(MAGIC_PRESERVE_ATIME|MAGIC_MIME);
  if (MagicCookie == NULL)
  {
    fprintf(stderr,"FATAL: Failed to initialize magic cookie\n");
    return -1;
  }

  magic_load(MagicCookie,NULL);
  return 0;
}

/**
 * @brief clean env and others
 */
int TraverseClean()
{
  magic_close(MagicCookie);
  return 0;
}

/**
 * @brief normal test for one package
 */
void testTraverseNormal4Package()
{
  Filename = "../test-data/testdata4unpack/threezip.zip"; 
  Basename = "threezip.zip";
  deleteTmpFiles(NewDir);
  ParentInfo PITest = {0, 1287725739, 1287725739, 0, 0};
  PI = &PITest;
  Result = Traverse(Filename,Basename,Label,NewDir,Recurse,PI);
  #ifdef DEBUG
  printf("in testTraverseNormal4Package result is :%d\n", Result);
  #endif
  existed = file_dir_existed("../test-result/threezip.zip.dir/Desktop.zip.dir/record.txt");
  CU_ASSERT_EQUAL(existed, 1); // is existing
  CU_ASSERT_EQUAL(Result, 1); // Filename is one containter
}

/**
 * @brief normal test for one package another case
 */
void testTraverseNormal4Package2()
{
  Filename = "../test-data/testdata4unpack/libfossagent.a";
  Basename = "libfossagent.a";
  deleteTmpFiles(NewDir);
  ParentInfo PITest = {0, 1287725739, 1287725739, 0, 0};
  PI = &PITest;
  Result = Traverse(Filename,Basename,Label,NewDir,Recurse,PI);
  #ifdef DEBUG
  printf("in testTraverseNormal4Package2 result is :%d\n", Result);
  #endif
  existed = file_dir_existed("../test-result/libfossagent.a.dir/libfossagent.o");
  CU_ASSERT_EQUAL(existed, 1); //  is existing
  CU_ASSERT_EQUAL(Result, 1); // Filename is one containter
}

/**
 * @brief normal test for one directory 
 */
void testTraverseNormal4Dir()
{
  Filename = "../test-data/testdata4unpack/testdir";
  Basename = "";
  deleteTmpFiles(NewDir);
  MkDirs("../test-result/test-data/testdata4unpack/testdir");
  char *cmdline = "/bin/cp -r ../test-data/testdata4unpack/testdir/* ../test-result/test-data/testdata4unpack/testdir";
  system(cmdline); // cp ../test-data/testdata4unpack/testdir to ../test-result/
  ParentInfo PITest = {0, 1287725739, 1287725739, 0, 0};
  PI = &PITest;
  Label = "Called by dir/wait";
  Result = Traverse(Filename,Basename,Label,NewDir,Recurse,PI);
  #ifdef DEBUG
  printf("in testTraverseNormal4Dir result is :%d\n", Result);
  #endif
  existed = file_dir_existed("../test-result/test-data/testdata4unpack/testdir/test.jar.dir/ununpack");
  CU_ASSERT_EQUAL(existed, 1); // is existing
  CU_ASSERT_EQUAL(Result, 1); // Filename is one containter
}

/**
 * @brief abnormal test for null parameters
 */
void testTraverseNullParams()
{
  Filename = ""; 
  Basename = "";
  deleteTmpFiles(NewDir);
  ParentInfo PITest = {0, 1287725739, 1287725739, 0, 0};
  PI = &PITest;
  Result = Traverse(Filename,Basename,Label,NewDir,Recurse,PI);
  #ifdef DEBUG
  printf("in testTraverseNullParams result is :%d\n", Result);
  #endif
  existed = file_dir_existed("../test-result");
  CU_ASSERT_EQUAL(existed, 0); //  not  existing
  CU_ASSERT_EQUAL(Result, 0); // Filename is not one containter
}

CU_TestInfo Traverse_testcases[] =
{
    {"Testing Traverse normal package:", testTraverseNormal4Package},
    {"Testing Traverse normal package another:", testTraverseNormal4Package2},
    {"Testing Traverse normal directory:", testTraverseNormal4Dir},
    {"Testing Traverse null paramters:", testTraverseNullParams},
    CU_TEST_INFO_NULL
};
