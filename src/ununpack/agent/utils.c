/*******************************************************************
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
 *******************************************************************/
#include "ununpack.h"
#include "externs.h"

enum BITS {
  BITS_PROJECT = 27, 
  BITS_ARTIFACT = 28,
  BITS_CONTAINER = 29
};


/**
 * @brief Test if the file is a compression bomb.  
 *        If the size of FileName is a factor of InflateSize more than the
 *        size of the directory containing it, then it is a bomb.
 * @param FileName pathname to file
 * @param InflateSize Inflation factor.
 * @return: 1 on is one inflated file, 0 on is not
 */
int IsInflatedFile(char *FileName, int InflateSize)
{
  int result = 0;
  char FileNameParent[PATH_MAX];
  memset(FileNameParent, 0, PATH_MAX);
  struct stat st, stParent;
  strncpy(FileNameParent, FileName, sizeof(FileNameParent));
  char  *lastSlashPos = strrchr(FileNameParent, '/');
  if (NULL != lastSlashPos)
  {
    /* get the parent container,
       e.g. for the file ./10g.tar.bz.dir/10g.tar, partent file is ./10g.tar.bz.dir
     */
    FileNameParent[lastSlashPos - FileNameParent] = '\0';
    if (!strcmp(FileNameParent + strlen(FileNameParent) - 4, ".dir"))
    {
      /* get the parent file, must be one file
         e.g. for the file ./10g.tar.bz.dir/10g.tar, partent file is ./10g.tar.bz
       */
      FileNameParent[strlen(FileNameParent) - 4] = '\0';
      stat(FileNameParent, &stParent);
      stat(FileName, &st);
      if(S_ISREG(stParent.st_mode) && (st.st_size/stParent.st_size > InflateSize))
      {
        result = 1;
      }
    }
  }
  return result;
}


/**
 * @brief Close scheduler and database connections, then exit.
 * @param rc exit code
 * @returns no return, calls exit(rc)
 */
void	SafeExit	(int rc)
{
  if (pgConn) PQfinish(pgConn); 
  fo_scheduler_disconnect(rc);
  exit(rc);
} /* SafeExit() */

/**
 * @brief get rid of the postfix
 *   for example: test.gz --> test
 * @param Name input file name
 */
void RemovePostfix(char *Name)
{
  if (NULL == Name) return; // exception
  // keep the part before the last dot
  char *LastDot = strrchr(Name, '.');
  if (LastDot) *LastDot = 0;
}

/**
 * @brief Initialize the metahandler CMD table.
 * This ensures that:
 *  - every mimetype is loaded
 *  - every mimetype has an DBindex.
 */
void	InitCmd	()
{
  int i;
  PGresult *result;

  /* clear existing indexes */
  for(i=0; CMD[i].Magic != NULL; i++)
  {
    CMD[i].DBindex = -1; /* invalid value */
  }

  if (!pgConn) return; /* DB must be open */

  /* Load them up! */
  for(i=0; CMD[i].Magic != NULL; i++)
  {
    if (CMD[i].Magic[0] == '\0') continue;
    ReGetCmd:
    memset(SQL,'\0',MAXSQL);
    snprintf(SQL,MAXSQL,"SELECT mimetype_pk FROM mimetype WHERE mimetype_name = '%s';",CMD[i].Magic);
    result =  PQexec(pgConn, SQL); /* SELECT */
    if (fo_checkPQresult(pgConn, result, SQL, __FILE__, __LINE__)) 
    {
      SafeExit(4);
    }
    else if (PQntuples(result) > 0) /* if there is a value */
    {  
      CMD[i].DBindex = atol(PQgetvalue(result,0,0));
      PQclear(result);
    }
    else /* No value, so add it */
    {
      PQclear(result);
      memset(SQL,'\0',MAXSQL);
      snprintf(SQL,MAXSQL,"INSERT INTO mimetype (mimetype_name) VALUES ('%s');",CMD[i].Magic);
      result =  PQexec(pgConn, SQL); /* INSERT INTO mimetype */
      if (fo_checkPQcommand(pgConn, result, SQL, __FILE__ ,__LINE__))
      {
        SafeExit(5);
      }
      else 
      {
        PQclear(result);
        goto ReGetCmd;
      }
    }
  }
} /* InitCmd() */


/**
 * @brief Protect strings intelligently.
 * Prevents filenames containing ' or % or \ from screwing
 * up system() and snprintf().  Even supports a "%s".
 * NOTE: %s is assumed to be in single quotes!
 * @returns 0 on success, 1 on overflow.
 **/
int	TaintString	(char *Dest, int DestLen,
    char *Src, int ProtectQuotes, char *Replace)
{
  int i,d;
  char Temp[FILENAME_MAX];

  memset(Dest,'\0',DestLen);
  i=0;
  d=0;
  while((Src[i] != '\0') && (d < DestLen))
  {
    /* save */
    if (ProtectQuotes && (Src[i]=='\''))
    {
      if (d+4 >= DestLen) return(1);
      strcpy(Dest+d,"'\\''"); /* unquote, raw quote, requote (for shells) */
      d+=4;
      i++;
    }
    else if (!ProtectQuotes && strchr("\\",Src[i]))
    {
      if (d+2 >= DestLen) return(1);
      Dest[d] = '\\'; d++;
      Dest[d] = Src[i]; d++;
      i++;
    }
    else if (Replace && (Src[i]=='%') && (Src[i+1]=='s'))
    {
      TaintString(Temp,sizeof(Temp),Replace,1,NULL);
      if (d+strlen(Temp) >= DestLen) return(1);
      strcpy(Dest+d,Temp);
      d = strlen(Dest);
      i += 2;
    }
    else
    {
      Dest[d] = Src[i];
      d++;
      i++;
    }
  }
  return(0);
} /* TaintString() */

/**
 * @brief Given a filename and its stat, prune it:
 * - Remove anything that is not a regular file or directory
 * - Remove files when hard-link count > 1 (duplicate search)
 * - Remove zero-length files
 * @returns 1=pruned, 0=no change.
 **/
inline int	Prune	(char *Fname, struct stat Stat)
{
  if (!Fname || (Fname[0]=='\0')) return(1);  /* not a good name */
  /* check file type */
  if (S_ISLNK(Stat.st_mode) || S_ISCHR(Stat.st_mode) ||
      S_ISBLK(Stat.st_mode) || S_ISFIFO(Stat.st_mode) ||
      S_ISSOCK(Stat.st_mode))
  {
    unlink(Fname);
    return(1);
  }
  /* check hard-link count */
  if (S_ISREG(Stat.st_mode) && (Stat.st_nlink > 1))
  {
    unlink(Fname);
    return(1);
  }
  /* check zero-length files */
  if (S_ISREG(Stat.st_mode) && (Stat.st_size == 0))
  {
    unlink(Fname);
    return(1);
  }
  return(0);
} /* Prune() */

/**
 * @brief Same as command-line "mkdir -p".
 * @param Fname file name
 * @returns 0 on success, 1 on failure.
 **/
inline int	MkDirs	(char *Fname)
{
  char Dir[FILENAME_MAX+1];
  int i;
  int rc=0;
  struct stat Status;

  memset(Dir,'\0',sizeof(Dir));
  strcpy(Dir,Fname);
  for(i=1; Dir[i] != '\0'; i++)
  {
    if (Dir[i] == '/')
    {
      Dir[i]='\0';
      /* Only mkdir if it does not exist */
      if (stat(Dir,&Status) == 0)
      {
        if (!S_ISDIR(Status.st_mode))
        {
          FATAL("'%s' is not a directory.",Dir)
          return(1);
        }
      }
      else /* else, it does not exist */
      {
        rc=mkdir(Dir,0770); /* create this path segment + Setgid */
        if (rc && (errno == EEXIST)) rc=0;
        if (rc)
        {
          FATAL("mkdir %s' failed, error: %s",Dir,strerror(errno))
          SafeExit(7);
        }
        chmod(Dir,02770);
      } /* else */
      Dir[i]='/';
    }
  }
  rc = mkdir(Dir,0770);	/* create whatever is left */
  if (rc && (errno == EEXIST)) rc=0;
  if (rc)
  {
    FATAL("mkdir %s' failed, error: %s",Dir,strerror(errno))
    SafeExit(8);
  }
  chmod(Dir,02770);
  return(rc);
} /* MkDirs() */

/**
 * @brief Smart mkdir.
 * If mkdir fails, then try running MkDirs.
 * @param Fname file name
 * @returns 0 on success, 1 on failure.
 **/
inline int	MkDir	(char *Fname)
{
  if (mkdir(Fname,0770))
  {
    if (errno == EEXIST) return(0); /* failed because it exists is ok */
    return(MkDirs(Fname));
  }
  chmod(Fname,02770);
  return(0);
} /* MkDir() */

/**
 * @brief Given a filename, is it a directory?
 * @param Fname file name
 * @returns 1=yes, 0=no.
 **/
inline int	IsDir	(char *Fname)
{
  struct stat Stat;
  int rc;
  if (!Fname || (Fname[0]=='\0')) return(0);  /* not a directory */
  rc = lstat(Fname,&Stat);
  if (rc != 0) return(0); /* bad name */
  return(S_ISDIR(Stat.st_mode));
} /* IsDir() */

/**
 * @brief: Given a filename, is it a file?
 * @param Link True if should it follow symbolic links
 * @returns 1=yes, 0=no.
 **/
int      IsFile  (char *Fname, int Link)
{
  struct stat Stat;
  int rc;
  if (!Fname || (Fname[0]=='\0')) return(0);  /* not a directory */
  if (Link) rc = stat(Fname,&Stat);
  else rc = lstat(Fname,&Stat);
  if (rc != 0) return(0); /* bad name */
  return(S_ISREG(Stat.st_mode));
} /* IsFile() */


/**
 * @brief Read a command from a stream.
 * If the line is empty, then try again.
 * @param Fin  Input file pointer
 * @param Line Output line buffer
 * @param MaxLine Max line length
 * @returns line length, or -1 of EOF.
 **/
int     ReadLine (FILE *Fin, char *Line, int MaxLine)
{
  int C;
  int i;

  if (!Fin) return(-1);
  if (feof(Fin)) return(-1);
  memset(Line,'\0',MaxLine);
  i=0;
  C=fgetc(Fin);
  if (C<0) return(-1);
  while(!feof(Fin) && (C>=0) && (i<MaxLine))
  {
    if (C=='\n')
    {
      if (i > 0) return(i);
      /* if it is a blank line, then ignore it. */
    }
    else
    {
      Line[i]=C;
      i++;
    }
    C=fgetc(Fin);
  }
  return(i);
} /* ReadLine() */

/**
 * @brief Check if the executable exists.
 * (Like the command-line "which" but without returning the path.)
 * This should only be used on relative path executables.
 * @param Exe Executable file name
 * @param Quiet If true, do not write warning on file not found
 * @returns: 1 if exists, 0 if does not exist.
 **/
int	IsExe	(char *Exe, int Quiet)
{
  char *Path;
  int i,j;
  char TestCmd[FILENAME_MAX];

  Path = getenv("PATH");
  if (!Path) return(0);	/* nope! */

  memset(TestCmd,'\0',sizeof(TestCmd));
  j=0;
  for(i=0; (j<FILENAME_MAX-1) && (Path[i] != '\0'); i++)
  {
    if (Path[i]==':')
    {
      if ((j>0) && (TestCmd[j-1] != '/')) strcat(TestCmd,"/");
      strcat(TestCmd,Exe);
      if (IsFile(TestCmd,1))	return(1); /* found it! */
      /* missed */
      memset(TestCmd,'\0',sizeof(TestCmd));
      j=0;
    }
    else
    {
      TestCmd[j]=Path[i];
      j++;
    }
  }

  /* check last path element */
  if (j>0)
  {
    if (TestCmd[j-1] != '/') strcat(TestCmd,"/");
    strcat(TestCmd,Exe);
    if (IsFile(TestCmd,1))	return(1); /* found it! */
  }
  if (!Quiet) WARNING("%s not found in $PATH",Exe)
  return(0); /* not in path */
} /* IsExe() */

/**
 * @brief Copy a file.
 * For speed: mmap and save.
 * @param Src Source file path
 * @param Dst Destination file path
 * @returns: 0 if copy worked, 1 if failed.
 **/
int	CopyFile	(char *Src, char *Dst)
{
  int Fin, Fout;
  unsigned char * Mmap;
  int LenIn, LenOut, Wrote;
  struct stat Stat;
  int rc=0;
  char *Slash;

  if (lstat(Src,&Stat) == -1) return(1);
  LenIn = Stat.st_size;
  if (!S_ISREG(Stat.st_mode))	return(1);

  Fin = open(Src,O_RDONLY);
  if (Fin == -1)
  {
    FATAL("Unable to open source '%s'",Src)
    return(1);
  }

  /* Make sure the directory exists for copying */
  Slash = strrchr(Dst,'/');
  if (Slash && (Slash != Dst))
  {
    Slash[0]='\0';
    MkDir(Dst);
    Slash[0]='/';
  }

  Fout = open(Dst,O_WRONLY|O_CREAT|O_TRUNC,Stat.st_mode);
  if (Fout == -1)
  {
    FATAL("Unable to open target '%s'",Dst)
    close(Fin);
    return(1);
  }

  /* load the source file */
  Mmap = mmap(0,LenIn,PROT_READ,MAP_PRIVATE,Fin,0);
  if (Mmap == NULL)
  {
    FATAL("pfile %s Unable to process file.",Pfile_Pk)
    WARNING("pfile %s Mmap failed during copy.",Pfile_Pk)
    rc=1;
    goto CopyFileEnd;
  }

  /* write file at maximum speed */
  LenOut=0;
  Wrote=0;
  while((LenOut < LenIn) && (Wrote >= 0))
  {
    Wrote = write(Fout,Mmap+LenOut,LenIn-LenOut);
    LenOut += Wrote;
  }

  /* clean up */
  munmap(Mmap,LenIn);
  CopyFileEnd:
  close(Fout);
  close(Fin);
  return(rc);
} /* CopyFile() */


/**
 * @brief Wait for a child.  Sets child status.
 * @returns the queue record, or -1 if no more children.
 **/
int     ParentWait      ()
{
  int i;
  int Pid;
  int Status;

  Pid = wait(&Status);
  if (Pid <= 0) return(-1);  /* no pending children, or call failed */

  /* find the child! */
  for(i=0; (i<MAXCHILD) && (Queue[i].ChildPid != Pid); i++)        ;
  if (Queue[i].ChildPid != Pid)
  {
    /* child not found */
    return(-1);
  }

  /* check if the child had an error */
  if (!WIFEXITED(Status))
  {
    if (!ForceContinue)
    {
      FATAL("Child had unnatural death")
      SafeExit(9);
    }
    Queue[i].ChildCorrupt=1;
    Status = -1;
  }
  else Status = WEXITSTATUS(Status);
  if (Status != 0)
  {
    if (!ForceContinue)
    {
      FATAL("Child had non-zero status: %d",Status)
      FATAL("Child was to recurse on %s",Queue[i].ChildRecurse)
      SafeExit(10);
    }
    Queue[i].ChildCorrupt=1;
  }

  /* Finish record */
  Queue[i].ChildStatus = Status;
  Queue[i].ChildPid = 0;
  Queue[i].PI.EndTime = time(NULL);
  return(i);
} /* ParentWait() */

/***************************************************************************/
/***************************************************************************/
/*** Command Processing ***/
/***************************************************************************/
/***************************************************************************/

/**
 * @brief Make sure all commands are usable.
 * @param Show Unused
 **/
void	CheckCommands	(int Show)
{
  int i;
  int rc;

  /* Check for CMD_PACK and CMD_ARC tools */
  for(i=0; CMD[i].Cmd != NULL; i++)
  {
    if (CMD[i].Cmd[0] == '\0')	continue; /* no command to check */
    switch(CMD[i].Type)
    {
      case CMD_PACK:
      case CMD_RPM:
      case CMD_DEB:
      case CMD_ARC:
      case CMD_AR:
      case CMD_PARTITION:
        CMD[i].Status = IsExe(CMD[i].Cmd,Quiet);
        break;
      default:
        ; /* do nothing */
    }
  }

  /* Check for CMD_ISO */
  rc = ( IsExe("isoinfo",Quiet) && IsExe("grep",Quiet) );
  for(i=0; CMD[i].Cmd != NULL; i++)
  {
    if (CMD[i].Type == CMD_ISO) CMD[i].Status = rc;
  }

  /* Check for CMD_DISK */
  rc = ( IsExe("icat",Quiet) && IsExe("fls",Quiet) );
  for(i=0; CMD[i].Cmd != NULL; i++)
  {
    if (CMD[i].Type == CMD_DISK) CMD[i].Status = rc;
  }
} /* CheckCommands() */

/**
 * @brief Try a command and return command code.
 * Command becomes:
 * - Cmd CmdPre 'File' CmdPost Out
 * - If there is a %s, then that becomes Where.
 * @param Cmd
 * @param CmdPre
 * @param File
 * @param CmdPost
 * @param Out
 * @param Where
 * @returns -1 if command could not run.
 *************************************************/
int	RunCommand	(char *Cmd, char *CmdPre, char *File, char *CmdPost,
    char *Out, char *Where)
{
  char Cmd1[FILENAME_MAX * 3];
  char CWD[FILENAME_MAX];
  int rc;
  char TempPre[FILENAME_MAX];
  char TempFile[FILENAME_MAX];
  char TempCwd[FILENAME_MAX];
  char TempPost[FILENAME_MAX];

  if (!Cmd) return(0); /* nothing to do */

  if (Verbose)
  {
    if (Where && Out)
      DEBUG("Extracting %s: %s > %s",Cmd,File,Out)
    else 
    if (Where) 
      DEBUG("Extracting %s in %s: %s\n",Cmd,Where,File)
    else 
      DEBUG("Testing %s: %s\n",Cmd,File)
  }

  if (getcwd(CWD,sizeof(CWD)) == NULL)
  {
    FATAL("directory name longer than %d characters",(int)sizeof(CWD))
    return(-1);
  }
  if (Verbose > 1) DEBUG("CWD: %s\n",CWD);
  if ((Where != NULL) && (Where[0] != '\0'))
  {
    if (chdir(Where) != 0)
    {
      MkDir(Where);
      if (chdir(Where) != 0)
      {
        FATAL("Unable to access directory '%s'",Where)
        return(-1);
      }
    }
    if (Verbose > 1) DEBUG("CWD: %s",Where)
  }

  /* CMD: Cmd CmdPre 'CWD/File' CmdPost */
  /* CmdPre and CmdPost may contain a "%s" */
  memset(Cmd1,'\0',sizeof(Cmd1));
  if (TaintString(TempPre,FILENAME_MAX,CmdPre,0,Out) ||
      TaintString(TempFile,FILENAME_MAX,File,1,Out) ||
      TaintString(TempPost,FILENAME_MAX,CmdPost,0,Out))
  {
    return(-1);
  }
  if (File[0] != '/')
  {
    TaintString(TempCwd,FILENAME_MAX,CWD,1,Out);
    snprintf(Cmd1,sizeof(Cmd1),"%s %s '%s/%s' %s",
        Cmd,TempPre,TempCwd,TempFile,TempPost);
  }
  else
  {
    snprintf(Cmd1,sizeof(Cmd1),"%s %s '%s' %s",
        Cmd,TempPre,TempFile,TempPost);
  }
  rc = system(Cmd1);
  if (WIFSIGNALED(rc))
  {
    ERROR("Process killed by signal (%d): %s",WTERMSIG(rc),Cmd1)
    SafeExit(11);
  }
  if (WIFEXITED(rc)) rc = WEXITSTATUS(rc);
  else rc=-1;
  if (Verbose) DEBUG("in %s -- %s ; rc=%d",Where,Cmd1,rc)

  if(chdir(CWD) != 0)
    ERROR("Unable to change directory to %s", CWD)
  if (Verbose > 1) DEBUG("CWD: %s",CWD)
  return(rc);
} /* RunCommand() */

/***************************************************
 FindCmd(): Given a file name, determine the type of
 extraction command.  This uses Magic.
 Returns index to command-type, or -1 on error.
 ***************************************************/
int	FindCmd	(char *Filename)
{
  char *Type;
  char Static[256];
  int Match;
  int i;
  int rc;

  Type = (char *)magic_file(MagicCookie,Filename);
  if (Type == NULL) return(-1);

  /* Set .dsc file magic as application/x-debian-source */
  char *pExt;
  FILE *fp;
  char line[500];
  int j;
  char c;
  pExt = strrchr(Filename, '.');
  if ( pExt != NULL)
  {
    if (strcmp(pExt, ".dsc")==0)
    {
      //check .dsc file contect to verify if is debian source file
      if ((fp = fopen(Filename, "r")) == NULL){
        DEBUG("Unable to open .dsc file %s",Filename)
        return(-1);
      }
      j=0;	
      while ((c = fgetc(fp)) != EOF && j < 500 ){
        line[j]=c;
        j++;
      }
      fclose(fp);
      if ((strstr(line, "-----BEGIN PGP SIGNED MESSAGE-----") && strstr(line,"Source:")) || 
          (strstr(line, "Format:") && strstr(line, "Source:") && strstr(line, "Version:")))
      {
        if (Verbose > 0) DEBUG("First bytes of .dsc file %s\n",line)
        memset(Static,0,sizeof(Static));
        strcpy(Static,"application/x-debian-source");
        Type=Static;
      }
    }	
  }

  /* sometimes Magic is wrong... */
  if (strstr(Type, "application/x-iso")) strcpy(Type, "application/x-iso");
  /* for xx.deb and xx.udeb package in centos os */
  if(strstr(Type, " application/x-debian-package"))
    strcpy(Type,"application/x-debian-package");

  /* for ms file, maybe the Magic contains 'msword', or the Magic contains 'vnd.ms', all unpack via 7z */
  if(strstr(Type, "msword") || strstr(Type, "vnd.ms"))
    strcpy(Type, "application/x-7z-w-compressed");

  if (strstr(Type, "octet" ))
  {
    rc = RunCommand("zcat","-q -l",Filename,">/dev/null 2>&1",NULL,NULL);
    if (rc==0)
    {
      memset(Static,0,sizeof(Static));
      strcpy(Static,"application/x-gzip");
      Type=Static;
    }
    else  // zcat failed so try cpio (possibly from rpm2cpio)
    {
      rc = RunCommand("cpio","-t<",Filename,">/dev/null 2>&1",NULL,NULL);
      if (rc==0)
      {
        memset(Static,0,sizeof(Static));
        strcpy(Static,"application/x-cpio");
        Type=Static;
      }
      else  // cpio failed so try 7zr (possibly from p7zip)
      {
        rc = RunCommand("7zr","l -y -p",Filename,">/dev/null 2>&1",NULL,NULL);
        if (rc==0)
        {
          memset(Static,0,sizeof(Static));
          strcpy(Static,"application/x-7z-compressed");
          Type=Static;
        }
        else
        {
          /* .deb and .udeb as application/x-debian-package*/
          char CMDTemp[FILENAME_MAX];
          sprintf(CMDTemp, "file '%s' |grep \'Debian binary package\'", Filename);
          rc = system(CMDTemp);
          if (rc==0) // is one debian package
          {
            memset(Static,0,sizeof(Static));
            strcpy(Static,"application/x-debian-package");
            Type=Static;
          }
          else /* for ms(.msi, .cab) file in debian os */
          {
            rc = RunCommand("7z","l -y -p",Filename,">/dev/null 2>&1",NULL,NULL);
            if (rc==0)
            {
              memset(Static,0,sizeof(Static));
              strcpy(Static,"application/x-7z-w-compressed");
              Type=Static;
            }
            else
            {
              memset(CMDTemp, 0, FILENAME_MAX);
              /* get the file type */
              sprintf(CMDTemp, "file '%s'", Filename);
              FILE *fp;
              char Output[FILENAME_MAX];
              /* Open the command for reading. */
              fp = popen(CMDTemp, "r");
              if (fp == NULL)
              {
                printf("Failed to run command");
                SafeExit(50);
              }

              /* Read the output the first line */
              if(fgets(Output, sizeof(Output) - 1, fp) == NULL)
                ERROR("Failed read")

              /* close */
              pclose(fp);
              /* the file type is ext2 */
              if (strstr(Output, "ext2"))
              {
                memset(Static,0,sizeof(Static));
                strcpy(Static,"application/x-ext2");
                Type=Static;
              }
              else if (strstr(Output, "ext3")) /* the file type is ext3 */
              {
                memset(Static,0,sizeof(Static));
                strcpy(Static,"application/x-ext3");
                Type=Static;
              }
              else if (strstr(Output, "x86 boot sector, mkdosfs")) /* the file type is FAT */
              {
                memset(Static,0,sizeof(Static));
                strcpy(Static,"application/x-fat");
                Type=Static;
              }
              else if (strstr(Output, "x86 boot sector")) /* the file type is NTFS */
              {
                memset(Static,0,sizeof(Static));
                strcpy(Static,"application/x-ntfs");
                Type=Static;
              }
              else if (strstr(Output, "x86 boot")) /* the file type is boot partition */
              {
                memset(Static,0,sizeof(Static));
                strcpy(Static,"application/x-x86_boot");
                Type=Static;
              }
              else
              {
                // only here to validate other octet file types
                if (Verbose > 0) DEBUG("octet mime type, file: %s", Filename)
              }
            }
          }
        }
      }
    }
  }

  if (strstr(Type, "application/x-exe") ||
      strstr(Type, "application/x-shellscript"))
  {
    int rc;
    rc = RunCommand("unzip","-q -l",Filename,">/dev/null 2>&1",NULL,NULL);
    if ((rc==0) || (rc==1) || (rc==2) || (rc==51))
    {
      memset(Static,0,sizeof(Static));
      strcpy(Static,"application/x-zip");
      Type=Static;
    }
    else
    {
      rc = RunCommand("cabextract","-l",Filename,">/dev/null 2>&1",NULL,NULL);
      if (rc==0)
      {
        memset(Static,0,sizeof(Static));
        strcpy(Static,"application/x-cab");
        Type=Static;
      }
    }
  } /* if was x-exe */
  else if (strstr(Type, "application/x-tar"))
  {
    if (RunCommand("tar","-tf",Filename,">/dev/null 2>&1",NULL,NULL) != 0)
      return(-1); /* bad tar! (Yes, they do happen) */
  } /* if was x-tar */

  /* determine command for file */
  Match=-1;
  for(i=0; (CMD[i].Cmd != NULL) && (Match == -1); i++)
  {
    if (CMD[i].Status == 0) continue; /* cannot check */
    if (CMD[i].Type == CMD_DEFAULT)
    {
      Match=i; /* done! */
    }
    else
      if (!strstr(Type, CMD[i].Magic)) continue; /* not a match */
    Match=i;
  }

  if (Verbose > 0)
  {
    /* no match */
    if (Match == -1) DEBUG("MISS: Type=%s  %s",Type,Filename)
    else DEBUG("MATCH: Type=%d  %s %s %s %s",CMD[Match].Type,CMD[Match].Cmd,CMD[Match].CmdPre,Filename,CMD[Match].CmdPost)
  }

  return(Match);
} /* FindCmd() */

/***************************************************************************/
/***************************************************************************/
/*** File Processing ***/
/***************************************************************************/
/***************************************************************************/

/***************************************************
 FreeDirList(): Free a list of files in a directory.
 ***************************************************/
void	FreeDirList	(dirlist *DL)
{
  dirlist *d;
  /* free records */
  while(DL)
  {
    d=DL;  /* grab the head */
    DL=DL->Next; /* increment new head */
    /* free old head */
    if (d->Name) free(d->Name);
    free(d);
  }
} /* FreeDirList() */

/***************************************************
 MakeDirList(): Allocate a list of files in a directory.
 ***************************************************/
dirlist *	MakeDirList	(char *Fullname)
{
  dirlist *dlist=NULL, *dhead=NULL;
  DIR *Dir;
  struct dirent *Entry;

  /* no effort is made to sort since all records need to be processed anyway */
  /* Current order is "reverse inode order" */
  Dir = opendir(Fullname);
  if (Dir == NULL)	return(NULL);

  Entry = readdir(Dir);
  while(Entry != NULL)
  {
    if (!strcmp(Entry->d_name,".")) goto skip;
    if (!strcmp(Entry->d_name,"..")) goto skip;
    dhead = (dirlist *)malloc(sizeof(dirlist));
    if (!dhead)
    {
      FATAL("Failed to allocate dirlist memory")
      SafeExit(12);
    }
    dhead->Name = (char *)malloc(strlen(Entry->d_name)+1);
    if (!dhead->Name)
    {
      FATAL("Failed to allocate dirlist.Name memory")
      SafeExit(13);
    }
    memset(dhead->Name,'\0',strlen(Entry->d_name)+1);
    strcpy(dhead->Name,Entry->d_name);
    /* add record to the list */
    dhead->Next = dlist;
    dlist = dhead;
#if 0
    {
      /* bubble-sort name -- head is out of sequence */
      /** This is SLOW! Only use for debugging! **/
      char *Name;
      dhead = dlist;
      while(dhead->Next && (strcmp(dhead->Name,dhead->Next->Name) > 0))
      {
        /* swap names */
        Name = dhead->Name;
        dhead->Name = dhead->Next->Name;
        dhead->Next->Name = Name;
        dhead = dhead->Next;
      }
    }
#endif

    skip:
    Entry = readdir(Dir);
  }
  closedir(Dir);

#if 0
  /* debug: List the directory */
  printf("Directory: %s\n",Fullname);
  for(dhead=dlist; dhead; dhead=dhead->Next)
  {
    printf("  %s\n",dhead->Name);
  }
#endif

  return(dlist);
} /* MakeDirList() */

/***************************************************
 SetDir(): Set a destination directory name.
 Smain = main extraction directory (may be null)
 Sfile = filename
 This will concatenate Smain and Sfile, but remove
 and terminating filename.
 ***************************************************/
void	SetDir	(char *Dest, int DestLen, char *Smain, char *Sfile)
{
  int i;

  memset(Dest,'\0',DestLen);
  if (Smain)
  {
    strcpy(Dest,Smain);
    /* remove absolute path (stay in destination) */
    if (Sfile && (Sfile[0]=='/')) Sfile++;
    /* skip "../" */
    /** NOTE: Someone that embeds "../" within the path can still
	    climb out! **/
    i=1;
    while(i && Sfile)
    {
      i=0;
      if (!memcmp(Sfile,"../",3)) { Sfile+=3; i=1; }
      else if (!memcmp(Sfile,"./",2)) { Sfile+=2; i=1; }
    }
    while(Sfile && !memcmp(Sfile,"../",3)) Sfile+=3;
  }

  if ((strlen(Dest) > 0) && (Last(Smain) != '/') && (Sfile[0] != '/'))
    strcat(Dest,"/");
  if (Sfile) strcat(Dest,Sfile);
  /* remove terminating file */
  for(i=strlen(Dest)-1; (i>=0) && (Dest[i] != '/'); i--)
  {
    Dest[i]='\0';
  }
} /* SetDir() */


/***************************************************
 DebugContainerInfo(): Check the structure.
 ***************************************************/
void	DebugContainerInfo	(ContainerInfo *CI)
{
  DEBUG("Container:")
  printf("  Source: %s\n",CI->Source); 
  printf("  Partdir: %s\n",CI->Partdir); 
  printf("  Partname: %s\n",CI->Partname); 
  printf("  PartnameNew: %s\n",CI->PartnameNew); 
  printf("  TopContainer: %d\n",CI->TopContainer);
  printf("  HasChild: %d\n",CI->HasChild);
  printf("  Pruned: %d\n",CI->Pruned);
  printf("  Corrupt: %d\n",CI->Corrupt);
  printf("  Artifact: %d\n",CI->Artifact);
  printf("  IsDir: %d\n",CI->IsDir);
  printf("  IsCompressed: %d\n",CI->IsCompressed);
  printf("  uploadtree_pk: %ld\n",CI->uploadtree_pk);
  printf("  pfile_pk: %ld\n",CI->pfile_pk);
  printf("  ufile_mode: %ld\n",CI->ufile_mode);
  printf("  Parent Cmd: %d\n",CI->PI.Cmd);
  printf("  Parent ChildRecurseArtifact: %d\n",CI->PI.ChildRecurseArtifact);
  printf("  Parent uploadtree_pk: %ld\n",CI->PI.uploadtree_pk);
} /* DebugContainerInfo() */

/***************************************************
 DBInsertPfile(): Insert a Pfile record.
 Sets the pfile_pk in CI.
 Returns: 1 if record exists, 0 if record does not exist.
 ***************************************************/
int	DBInsertPfile	(ContainerInfo *CI, char *Fuid)
{
  PGresult *result;
  char *Val; /* string result from SQL query */

  /* idiot checking */
  if (!Fuid || (Fuid[0] == '\0')) return(1);

  /* Check if the pfile exists */
  memset(SQL,'\0',MAXSQL);
  snprintf(SQL,MAXSQL,"SELECT pfile_pk,pfile_mimetypefk FROM pfile WHERE pfile_sha1 = '%.40s' AND pfile_md5 = '%.32s' AND pfile_size = '%s';",
      Fuid,Fuid+41,Fuid+74);
  result =  PQexec(pgConn, SQL); /* SELECT */
  if (fo_checkPQresult(pgConn, result, SQL, __FILE__, __LINE__))
  {
    SafeExit(33);
  }

  /* add it if it was not found */
  if (PQntuples(result) == 0)
  {
    /* blindly insert to pfile table in database (don't care about dups) */
    /** If TWO ununpacks are running at the same time, they could both
        create the same pfile at the same time.  Ignore the dup constraint. */
    PQclear(result);
    memset(SQL,'\0',MAXSQL);
    if (CMD[CI->PI.Cmd].DBindex > 0)
    {
      snprintf(SQL,MAXSQL,"INSERT INTO pfile (pfile_sha1,pfile_md5,pfile_size,pfile_mimetypefk) VALUES ('%.40s','%.32s','%s','%ld');",
          Fuid,Fuid+41,Fuid+74,CMD[CI->PI.Cmd].DBindex);
    }
    else
    {
      snprintf(SQL,MAXSQL,"INSERT INTO pfile (pfile_sha1,pfile_md5,pfile_size) VALUES ('%.40s','%.32s','%s');",
          Fuid,Fuid+41,Fuid+74);
    }
    result =  PQexec(pgConn, SQL); /* INSERT INTO pfile */
    if (fo_checkPQcommand(pgConn, result, SQL, __FILE__ ,__LINE__))
    {
      SafeExit(34);
    }
    PQclear(result);

    /* Now find the pfile_pk.  Since it might be a dup, we cannot rely
       on currval(). */
    memset(SQL,'\0',MAXSQL);
    snprintf(SQL,MAXSQL,"SELECT pfile_pk,pfile_mimetypefk FROM pfile WHERE pfile_sha1 = '%.40s' AND pfile_md5 = '%.32s' AND pfile_size = '%s';",
        Fuid,Fuid+41,Fuid+74);
    result =  PQexec(pgConn, SQL);  /* SELECT */
    if (fo_checkPQresult(pgConn, result, SQL, __FILE__, __LINE__))
    {
      SafeExit(15);
    }
  }

  /* Now *DB contains the pfile_pk information */
  Val = PQgetvalue(result,0,0);
  if (Val)
  {
    CI->pfile_pk = atol(Val);
    if (Verbose) DEBUG("pfile_pk = %ld",CI->pfile_pk)
    /* For backwards compatibility... Do we need to update the mimetype? */
    if ((CMD[CI->PI.Cmd].DBindex > 0) &&
        (atol(PQgetvalue(result,0,1)) != CMD[CI->PI.Cmd].DBindex))
    {
#if 0
      PQclear(result);
      memset(SQL,'\0',MAXSQL);
      snprintf(SQL,MAXSQL,"BEGIN;");
      result = PQexec(pgConn, SQL);
      if (fo_checkPQresult(pgConn, result, SQL, __FILE__, __LINE__))
      {
        SafeExit(45);
      }
      PQclear(result);
      memset(SQL,'\0',MAXSQL);
      snprintf(SQL,MAXSQL,"SELECT * FROM pfile WHERE pfile_pk = '%ld' FOR UPDATE;", CI->pfile_pk);
      result =  PQexec(pgConn, SQL); /* lock pfile */
      if (fo_checkPQresult(pgConn, result, SQL, __FILE__, __LINE__))
      {
        SafeExit(35);
      }
#endif
      PQclear(result);
      memset(SQL,'\0',MAXSQL);
      snprintf(SQL,MAXSQL,"UPDATE pfile SET pfile_mimetypefk = '%ld' WHERE pfile_pk = '%ld';",
          CMD[CI->PI.Cmd].DBindex, CI->pfile_pk);
      result =  PQexec(pgConn, SQL); /* UPDATE pfile */
      if (fo_checkPQcommand(pgConn, result, SQL, __FILE__ ,__LINE__))
      {
        SafeExit(36);
      }
      PQclear(result);
#if 0
      memset(SQL,'\0',MAXSQL);
      snprintf(SQL,MAXSQL,"COMMIT;");
      result = PQexec(pgConn, SQL);      
      if (fo_checkPQcommand(pgConn, result, SQL, __FILE__ ,__LINE__))
      {
        SafeExit(37);
      }
      PQclear(result);      
#endif
    }
    else
    {
      PQclear(result);
    }
  }
  else
  {
    PQclear(result);
    CI->pfile_pk = -1;
    return(0);
  }

  return(1);
} /* DBInsertPfile() */

/***************************************************
 DBInsertUploadTree(): Insert an UploadTree record.
 If the tree is a duplicate, then we need to replicate
 all of the uploadtree records for the tree.
 This uses Upload_Pk.
 Returns: 1 if tree exists for some other project (duplicate)
 and 0 if tree does not exist.
 ***************************************************/
int	DBInsertUploadTree	(ContainerInfo *CI, int Mask)
{
  char UfileName[1024];
  PGresult *result;

  if (!Upload_Pk) return(-1); /* should never happen */
  // printf("=========== BEFORE ==========\n"); DebugContainerInfo(CI);

  /* Find record's mode */
  CI->ufile_mode = CI->Stat.st_mode & Mask;
  if (!CI->TopContainer && CI->Artifact) CI->ufile_mode |= (1 << BITS_ARTIFACT);
  if (CI->HasChild) CI->ufile_mode |= (1 << BITS_CONTAINER);

  /* Find record's name */
  memset(UfileName,'\0',sizeof(UfileName));
  if (CI->TopContainer)
  {
    char *ufile_name;
    snprintf(UfileName,sizeof(UfileName),"SELECT upload_filename FROM upload WHERE upload_pk = %s;",Upload_Pk);
    result =  PQexec(pgConn, UfileName);
    if (fo_checkPQresult(pgConn, result, UfileName, __FILE__, __LINE__))
    {
      SafeExit(38);
    }
    memset(UfileName,'\0',sizeof(UfileName));
    ufile_name = PQgetvalue(result,0,0);
    PQclear(result);
    if (strchr(ufile_name,'/')) ufile_name = strrchr(ufile_name,'/')+1;
    strncpy(UfileName,ufile_name,sizeof(UfileName)-1);
  }
  else if (CI->Artifact)
  {
    int Len;
    Len = strlen(CI->Partname);
    /* determine type of artifact */
    if ((Len > 4) && !strcmp(CI->Partname+Len-4,".dir"))
      strcpy(UfileName,"artifact.dir");
    else if ((Len > 9) && !strcmp(CI->Partname+Len-9,".unpacked"))
      strcpy(UfileName,"artifact.unpacked");
    else if ((Len > 5) && !strcmp(CI->Partname+Len-5,".meta"))
      strcpy(UfileName,"artifact.meta");
    else /* Don't know what it is */
      strcpy(UfileName,"artifact");
  }
  else /* not an artifact -- use the name */
  {
    char *S = 0;
    int   error;
    PQescapeStringConn(pgConn, S, CI->Partname, strlen(CI->Partname), &error);
    if (error)
        WARNING("Error escaping filename with multibype character set (%s).", CI->Partname)

    strncpy(UfileName,S,sizeof(UfileName));
    free(S);
  }

  // Begin add by vincent
  if(ReunpackSwitch)
  {
    /* Get the parent ID */
    /* Two cases -- depending on if the parent exists */
    memset(SQL,'\0',MAXSQL);
    if (CI->PI.uploadtree_pk > 0) /* This is a child */
    {
      /* Prepare to insert child */
      snprintf(SQL,MAXSQL,"INSERT INTO uploadtree (parent,pfile_fk,ufile_mode,ufile_name,upload_fk) VALUES (%ld,%ld,%ld,E'%s',%s);",
          CI->PI.uploadtree_pk, CI->pfile_pk, CI->ufile_mode,
          UfileName, Upload_Pk);
      result =  PQexec(pgConn, SQL); /* INSERT INTO uploadtree */
      if (fo_checkPQcommand(pgConn, result, SQL, __FILE__ ,__LINE__))
      {
        SafeExit(39);
      }
      PQclear(result);
    }
    else /* No parent!  This is the first upload! */
    {
      snprintf(SQL,MAXSQL,"INSERT INTO uploadtree (upload_fk,pfile_fk,ufile_mode,ufile_name) VALUES (%s,%ld,%ld,E'%s');",
          Upload_Pk, CI->pfile_pk, CI->ufile_mode, UfileName);
      result =  PQexec(pgConn, SQL); /* INSERT INTO uploadtree */
      if (fo_checkPQcommand(pgConn, result, SQL, __FILE__ ,__LINE__))
      {
        SafeExit(41);
      }
      PQclear(result);
    }
    /* Find the inserted child */
    memset(SQL,'\0',MAXSQL);
    /* snprintf(SQL,MAXSQL,"SELECT uploadtree_pk FROM uploadtree WHERE upload_fk=%s AND pfile_fk=%ld AND ufile_mode=%ld AND ufile_name=E'%s';",
    Upload_Pk, CI->pfile_pk, CI->ufile_mode, UfileName); */
    snprintf(SQL,MAXSQL,"SELECT currval('uploadtree_uploadtree_pk_seq');");
    result =  PQexec(pgConn, SQL);
    if (fo_checkPQresult(pgConn, result, SQL, __FILE__, __LINE__))
    {
      SafeExit(42);
    }
    CI->uploadtree_pk = atol(PQgetvalue(result,0,0));
    PQclear(result);
    //TotalItems++;
    // printf("=========== AFTER ==========\n"); DebugContainerInfo(CI);
  } 
  //End add by Vincent
  TotalItems++;
  fo_scheduler_heart(1);
  return(0);
} /* DBInsertUploadTree() */

/***************************************************
 AddToRepository(): Add a ContainerInfo record to the
 repository AND to the database.
 This modifies the CI record's pfile and ufile indexes!
 Returns: 1 if added, 0 if already exists!
 ***************************************************/
int	AddToRepository	(ContainerInfo *CI, char *Fuid, int Mask)
{
  int IsUnique = 1;  /* is it a DB replica? */

  /*****************************************/
  /* populate repository (include artifacts) */
  /* If we ever want to skip artifacts, use && !CI->Artifact */
  if ((Fuid[0]!='\0') && UseRepository)
  {
    /* put file in repository */
    if (!fo_RepExist(REP_FILES,Fuid))
    {
      if (fo_RepImport(CI->Source,REP_FILES,Fuid,1) != 0)
      {
        ERROR("Failed to import '%s' as '%s' into the repository",CI->Source,Fuid)
        SafeExit(16);
      }
    }
    if (Verbose) DEBUG("Repository[%s]: insert '%s' as '%s'",
        REP_FILES,CI->Source,Fuid)
  }

  /* PERFORMANCE NOTE:
     I used to use and INSERT and an UPDATE.
     Turns out, INSERT is fast, UPDATE is *very* slow (10x).
     Now I just use an INSERT.
   */

  /* Insert pfile record */
  if (pgConn)
  {
    if (!DBInsertPfile(CI,Fuid)) return(0);
    /* Update uploadtree table */
    IsUnique = !DBInsertUploadTree(CI,Mask);
  }

  if (ForceDuplicate) IsUnique=1;
  return(IsUnique);
} /* AddToRepository() */

/**************************************************
 DisplayContainerInfo(): Print what can be printed in XML.
 Cmd = command used to create this file (parent)
 CI->Cmd = command to be used ON this file (child)
 Returns: 1 if item is unique, 0 if duplicate.
 ***************************************************/
int	DisplayContainerInfo	(ContainerInfo *CI, int Cmd)
{
  int i;
  int Mask=0177000; /* used for XML modemask */
  char Fuid[1024];

  if (CI->Source[0] == '\0') return(0);
  memset(Fuid,0,sizeof(Fuid));
  /* TotalItems++; */

  /* list source */
  if (ListOutFile)
  {
    fputs("<item source=\"",ListOutFile);
    for(i=0; CI->Source[i] != '\0'; i++)
    {
      if (isalnum(CI->Source[i]) ||
          strchr(" `~!@#$%^*()-=_+[]{}\\|;:',./?",CI->Source[i]))
        fputc(CI->Source[i],ListOutFile);
      else fprintf(ListOutFile,"&#x%02x;",(int)(CI->Source[i])&0xff);
    }
    fputs("\" ",ListOutFile);

    /* list file names */
    if (CI->Partname[0] != '\0')
    {
      fputs("name=\"",ListOutFile);
      /* XML taint-protect name */
      for(i=0; CI->Partname[i] != '\0'; i++)
      {
        if (isalnum(CI->Partname[i]) ||
            strchr(" `~!@#$%^*()-=_+[]{}\\|;:',./?",CI->Partname[i]))
          fputc(CI->Partname[i],ListOutFile);
        else fprintf(ListOutFile,"&#x%02x;",(int)(CI->Partname[i])&0xff);
      }
      fputs("\" ",ListOutFile);
    }

    /* list mime info */
    if ((CI->PI.Cmd >= 0) && (CMD[CI->PI.Cmd].Type != CMD_DEFAULT))
    {
      fprintf(ListOutFile,"mime=\"%s\" ",CMD[CI->PI.Cmd].Magic);
      TotalFiles++;
    }
    else if (S_ISDIR(CI->Stat.st_mode))
    {
      fprintf(ListOutFile,"mime=\"directory\" ");
      TotalDirectories++;
    }
    else TotalFiles++;

    /* identify compressed files */
    if (CMD[CI->PI.Cmd].Type == CMD_PACK)
    {
      fprintf(ListOutFile,"compressed=\"1\" ");
      TotalCompressedFiles++;
    }
    /* identify known artifacts */
    if (CI->Artifact)
    {
      fprintf(ListOutFile,"artifact=\"1\" ");
      TotalArtifacts++;
    }

    if (CI->HasChild) fprintf(ListOutFile,"haschild=\"1\" ");
  } /* if ListOutFile */

  if (!CI->TopContainer)
  {
    /* list mode */
    Mask=0177000;
    if (Cmd >= 0)
    {
      if (S_ISDIR(CI->Stat.st_mode))
      {
        Mask = CMD[Cmd].ModeMaskDir;
      }
      else if (S_ISREG(CI->Stat.st_mode))
      {
        Mask = CMD[Cmd].ModeMaskReg;
      }
    }

    if (ListOutFile)
    {
      if (!CI->Artifact) /* no masks for an artifact */
      {
        fprintf(ListOutFile,"mode=\"%07o\" ",CI->Stat.st_mode & Mask);
        fprintf(ListOutFile,"modemask=\"%07o\" ",Mask);
      }

      /* identify known corrupted files */
      if (CI->Corrupt) fprintf(ListOutFile,"error=\"%d\" ",CI->Corrupt);

      /* list timestamps */
      if (CI->Stat.st_mtime)
      {
        if ((CI->Stat.st_mtime < CI->PI.StartTime) || (CI->Stat.st_mtime > CI->PI.EndTime))
          fprintf(ListOutFile,"mtime=\"%d\" ",(int)(CI->Stat.st_mtime));
      }
#if 0
      /** commented out since almost anything can screw this up. **/
      if (CI->Stat.st_ctime)
      {
        if ((CI->Stat.st_ctime < CI->PI.StartTime) || (CI->Stat.st_ctime > CI->PI.EndTime))
          fprintf(ListOutFile,"ctime=\"%d\" ",(int)(CI->Stat.st_ctime));
      }
#endif
    } /* if ListOutFile */
  } /* if not top container */

  /* list checksum info for files only! */
  if (S_ISREG(CI->Stat.st_mode) && !CI->Pruned)
  {
    CksumFile *CF;
    Cksum *Sum;

    CF = SumOpenFile(CI->Source);
    if (CF)
    {
      Sum = SumComputeBuff(CF);
      SumCloseFile(CF);
      if (Sum)
      {
        for(i=0; i<20; i++) { sprintf(Fuid+0+i*2,"%02X",Sum->SHA1digest[i]); }
        Fuid[40]='.';
        for(i=0; i<16; i++) { sprintf(Fuid+41+i*2,"%02X",Sum->MD5digest[i]); }
        Fuid[73]='.';
        snprintf(Fuid+74,sizeof(Fuid)-74,"%Lu",(long long unsigned int)Sum->DataLen);
        if (ListOutFile) fprintf(ListOutFile,"fuid=\"%s\" ",Fuid);
        free(Sum);
      } /* if Sum */
    } /* if CF */
    else /* file too large to mmap (probably) */
    {
      FILE *Fin;
      Fin = fopen(CI->Source,"rb");
      if (Fin)
      {
        Sum = SumComputeFile(Fin);
        if (Sum)
        {
          for(i=0; i<20; i++) { sprintf(Fuid+0+i*2,"%02X",Sum->SHA1digest[i]); }
          Fuid[40]='.';
          for(i=0; i<16; i++) { sprintf(Fuid+41+i*2,"%02X",Sum->MD5digest[i]); }
          Fuid[73]='.';
          snprintf(Fuid+74,sizeof(Fuid)-74,"%Lu",(long long unsigned int)Sum->DataLen);
          if (ListOutFile) fprintf(ListOutFile,"fuid=\"%s\" ",Fuid);
          free(Sum);
        }
        fclose(Fin);
      }
    }
  } /* if is file */

  /* end XML */
  if (ListOutFile)
  {
    if (CI->HasChild) fputs(">\n",ListOutFile);
    else fputs("/>\n",ListOutFile);
  } /* if ListOutFile */

  return(AddToRepository(CI,Fuid,Mask));
} /* DisplayContainerInfo() */

/***************************************************
 RemoveDir(char* dirpath) Remove all files under dirpath
 ***************************************************/
int RemoveDir(char *dirpath)
{
  char RMcmd[FILENAME_MAX];
  int rc;
  memset(RMcmd, '\0', sizeof(RMcmd));
  snprintf(RMcmd, FILENAME_MAX -1, "rm -rf '%s'", dirpath);
  rc = system(RMcmd);
  return rc;
} /* RemoveDir() */


/**
 * @brief Check if path contains a "%U". If so, substitute a unique ID.
 * This substitution parameter must be at the end of the DirPath.
 * @parm DirPath Directory path.
 * @returns new directory path
 **/
char *PathCheck(char *DirPath)
{
  char *NewPath;
  char *subs;
  char  TmpPath[512];
  struct timeval time_st;

  if ((subs = strstr(DirPath,"%U")) )
  {
    /* dir substitution */
    if (gettimeofday(&time_st, 0))
    {
      /* gettimeofday failure */
      WARNING("gettimeofday() failure.")
      time_st.tv_usec = 999999;
    }

    *subs = 0;
    snprintf(TmpPath, sizeof(TmpPath), "%s/%ul", DirPath, (unsigned)time_st.tv_usec);
    NewPath = strdup(TmpPath);
  }
  else
  {
    /* no substitution */
    NewPath = strdup(DirPath);
  }
  return(NewPath);
}

/**********************************************
 Usage(): Display program usage.
 **********************************************/
void	Usage	(char *Name, char *Version)
{
  fprintf(stderr,"Universal Unpacker, version %s, compiled %s %s\n",
      Version,__DATE__,__TIME__);
  fprintf(stderr,"Usage: %s [options] file [file [file...]]\n",Name);
  fprintf(stderr,"  Extracts each file.\n");
  fprintf(stderr,"  If filename specifies a directory, then extracts everything in it.\n");
  fprintf(stderr," Unpack Options:\n");
  fprintf(stderr,"  -C     :: force continue when unpack tool fails.\n");
  fprintf(stderr,"  -d dir :: specify alternate extraction directory. %%U substitutes a unique ID.\n");
  fprintf(stderr,"            Default is the same directory as file (usually not a good idea).\n");
  fprintf(stderr,"  -m #   :: number of CPUs to use (default: 1).\n");
  fprintf(stderr,"  -P     :: prune files: remove links, >1 hard links, zero files, etc.\n");
  fprintf(stderr,"  -R     :: recursively unpack (same as '-r -1')\n");
  fprintf(stderr,"  -r #   :: recurse to a specified depth (0=none/default, -1=infinite)\n");
  fprintf(stderr,"  -X     :: remove recursive sources after unpacking.\n");
  fprintf(stderr,"  -x     :: remove ALL unpacked files when done (clean up).\n");
  fprintf(stderr," I/O Options:\n");
  fprintf(stderr,"  -L out :: Generate a log of files extracted (in XML) to out.\n");
  fprintf(stderr,"  -F     :: Using files from the repository.\n");
  fprintf(stderr,"  -i     :: Initialize the database queue system, then exit.\n");
  fprintf(stderr,"  -Q     :: Using scheduler queue system. (Includes -F)\n");
  fprintf(stderr,"            If -L is used, unpacked files are placed in 'files'.\n");
  fprintf(stderr,"      -T rep :: Set gold repository name to 'rep' (for testing)\n");
  fprintf(stderr,"      -t rep :: Set files repository name to 'rep' (for testing)\n");
  fprintf(stderr,"      -A     :: do not set the initial DB container as an artifact.\n");
  fprintf(stderr,"      -f     :: force processing files that already exist in the DB.\n");
  fprintf(stderr,"  -q     :: quiet (generate no output).\n");
  fprintf(stderr,"  -U upload_pk :: upload to unpack (implies -RQ). Writes to db.\n");
  fprintf(stderr,"  -v     :: verbose (-vv = more verbose).\n");
  fprintf(stderr,"Currently identifies and processes:\n");
  fprintf(stderr,"  Compressed files: .Z .gz .bz .bz2 upx\n");
  fprintf(stderr,"  Archives files: tar cpio zip jar ar rar cab\n");
  fprintf(stderr,"  Data files: pdf\n");
  fprintf(stderr,"  Installer files: rpm deb\n");
  fprintf(stderr,"  File images: iso9660(plain/Joliet/Rock Ridge) FAT(12/16/32) ext2/ext3 NTFS\n");
  fprintf(stderr,"  Boot partitions: x86, vmlinuz\n");
  CheckCommands(Quiet);
} /* Usage() */

