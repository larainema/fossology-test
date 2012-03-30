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

 ***************************************************************/

/* std library includes */
#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <assert.h>
#include <ctype.h>

/* other library includes */
#include <pcre.h>
#include <libfossology.h>

/* local includes */
#include <copyright.h>
#include <radixtree.h>
#include <cvector.h>

#define MAXBUF 1024*1024  ///< max bytes to scan
#define LINE_LENGTH 256   ///< the max length of a line in a file


/** regular expression to find email statements in natural language */
char* email_regex = "[\\<\\(]?[A-Za-z0-9\\-_\\.\\+]{1,100}@[A-Za-z0-9\\-_\\.\\+]{1,100}\\.[A-Za-z]{1,4}[\\>\\)]?(?C1)";
/** regular expression to find url statements in natural language */
char* url_regex = "(?:(:?ht|f)tps?\\:\\/\\/[^\\s\\<]+[^\\<\\.\\,\\s])(?C2)";
/** the list of letter that will be removed when matching to a radix tree */
char token[34] = {' ','!','"','#','$','%','&','`','*','+','\'','-','.','/',':','\n',',',
                  '\t',';','<','=','>','?','@','[','\\',']','^','_','{','|','}','~',0};

/* ************************************************************************** */
/* **** Local Function ****************************************************** */
/* ************************************************************************** */

/** the internal structure for a copyright */
struct copyright_internal
{
  radix_tree dict;        ///< the dictionary to search within
  radix_tree name;        ///< the list of names to match
  cvector entries;        ///< the set of copyright found in a particular file
  pcre* email_re;         ///< regex for finding emails
  pcre* url_re;           ///< the regex for finding urls
  const char* reg_error;  ///< for regular expression error messages
  int reg_error_offset;   ///< for regex error offsets
};

struct copy_entry_internal
{
  char text[1024];            ///< the code that was identified as a copyright
  char name_match[256];       ///< the name that matched the entry identified as a copyright
  char dict_match[256];       ///< the dictionary match that originally identified the entry
  int start_byte;             ///< the location in the file that this copyright starts
  int end_byte;               ///< the location in the file that this copyright ends
  char* type;                 ///< the type of entry that was found, i.e. copyright, email, url
};

/**
 * @brief looks for the beginning of a line in the provided string
 *
 * will look at most 50 characters back for the beginning of a line based off of
 * the provided index. If within 50 characters it does not find the beginning of
 * the line, this will return the index 50 characters before the given index.
 *
 * @param ptext the string to search within
 * @param idx the index to base the search from
 * @return the index that is the beginning of the line.
 */
int find_beginning(char* ptext, int idx)
{
  int maxback = 50;
  int minidx = idx - maxback;

  while (idx-- && (idx > minidx))
  {
    if (!isalpha(ptext[idx])) return MAX(0, idx);
  }

  return idx;
}


/**
 * @brief looks for the end of a line in ptext
 *
 * Looks at most 200 characters after the given index in the string. If the end
 * of the sentence or line is not within 200 characters, this will return the
 * given index plus 200 characters.
 *
 * @param ptext the string to search through
 * @param idx the index to search from
 * @param bufsize the largest valid index in the string
 * @return the location of the end of the current line or sentence
 */
int find_end(char* ptext, int idx, int bufsize)
{
  int maxchars = 200;
  int start    = 50;
  int last = idx + maxchars;

  for (idx = idx + start; (idx < bufsize) && (idx < last); idx++)
  {
    if (ptext[idx] == '\n') return idx - 1;
  }
  return last;
}

/**
 * @brief strips false entries out of a copyright instance
 *
 * entries that do not contain any dictionary or name information should be
 * deleted since they are false entries. This private function will get run
 * after analyze has been run.
 *
 * @param copy the copyright instance to check for false entries
 */
void strip_empty_entries(copyright copy)
{
  copyright_iterator iter;

  for(iter = copyright_begin(copy); iter != copyright_end(copy) && iter != NULL; iter++)
  {
    /* remove if the copyright entry is emtpy */
    if(*iter == NULL || strlen(copy_entry_dict(*iter)) == 0 ||
        strlen(copy_entry_name(*iter)) == 0)
    {
      iter = (copyright_iterator)cvector_remove(copy->entries,
          (cvector_iterator)iter);
    } else if(copy_entry_start(*iter) < 0) {
      (*iter)->start_byte = 0;
    }
  }
}

/**
 * @brief checks to see if a string contains a word in a dictionary
 *
 * This will tokenize the whitespace and special characters out of a sentence
 * to search for a word that is contained within a dictionary.
 *
 * @param tree the diction to search within
 * @param string the string to search within
 * @param buf a string to place the find within
 * @return the index the string was found at, otherwise the length of the string
 */
int contains_copyright(radix_tree tree, char* string, char* buf)
{
  /* locals */
  char string_copy[strlen(string) + 1];
  char* curr;

  /* set up the necessary memory */
  memset(string_copy, '\0', sizeof(string_copy));
  strcpy(string_copy, string);
  curr = strtok(string_copy, token);
  buf[0] = '\0';

  /* loop until we find a match in the dictionary */
  while(curr != NULL)
  {
    if(radix_contains(tree, curr))
    {
      strcpy(buf, curr);
      return curr - string_copy;
    }
    curr = strtok(NULL, token);
  }

  /* a match was not found */
  return strlen(string);
}

/**
 * @brief Loads a file that is a list of words into a dictionary
 *
 * @param dict the diction to add the strings to
 * @param filename the file to grab the string from
 */
int load_dictionary(radix_tree dict, char* filename)
{
  FILE* pfile;
  char str[256];

  pfile = fopen(filename, "r");
  if(!pfile)
  {
    return 0;
  }

  while(fgets(str, LINE_LENGTH, pfile) != NULL)
  {
    str[strlen(str) - 1] = '\0';
    radix_insert(dict, str);
  }

  fclose(pfile);
  return 1;
}

/**
 * @brief Initialize the data in this entry
 *
 * clean up the strings and numbers in an entry so that we don't get any
 * accidental overlap between the entries.
 *
 * @param entry the entry to initialize
 */
void copy_entry_init(copy_entry entry)
{
  memset(entry->text, '\0', sizeof(entry->text));
  memset(entry->dict_match, '\0', sizeof(entry->dict_match));
  memset(entry->name_match, '\0', sizeof(entry->name_match));
  entry->start_byte = 0;
  entry->end_byte = 0;
  entry->type = NULL;
}

/**
 * @brief copy the data from the passed void* into a copyright entry
 *
 * This is a deep copy, any data that is pointed at will also be copied instead
 * of simply pointed at as well
 *
 * @param to_copy the memory to copy
 */
void* copy_entry_copy(void* to_copy)
{
  copy_entry cpy = (copy_entry)to_copy;
  copy_entry new = (copy_entry)calloc(1,sizeof(struct copy_entry_internal));

  strcpy(new->text, cpy->text);
  strcpy(new->dict_match, cpy->dict_match);
  strcpy(new->name_match, cpy->name_match);
  new->type = cpy->type;
  new->start_byte = cpy->start_byte;
  new->end_byte = cpy->end_byte;

  return new;
}

/**
 * @brief will delete the memory associated with to_destroy
 *
 * This is a deep destructor, all memory associated with and pointed at by
 * to_destroy will be deallocated
 *
 * @param to_destroy the memory to destroy
 */
void  copy_entry_destroy(void* to_destroy)
{
  free(to_destroy);
}

/**
 * @brief print the data contained in the entry to the file provided
 *
 * @param to_print the copy_entry that should be printed
 * @param ostr the file pointer to print to
 */
void  copy_entry_print(void* to_print, FILE* ostr)
{
  copy_entry prt = (copy_entry)to_print;
  fprintf(ostr, "%s\t%s ==>\n%s\n%d -> %d\n",
      prt->dict_match,
      prt->name_match,
      prt->text,
      prt->start_byte,
      prt->end_byte);
}

/*!
 * @brief creates a function registry for a copyright entry
 *
 * This function is private to the copyright class since the copyright object is
 * responsible for managing all memory relating to these.
 *
 * @return the new function registry
 */
function_registry* copy_entry_function_registry()
{
  function_registry* ret =
      (function_registry*)calloc(1, sizeof(function_registry));

  ret->name = "cvector";
  ret->copy = &copy_entry_copy;
  ret->destroy = &copy_entry_destroy;
  ret->print = &copy_entry_print;

  return ret;
}

/**
 * This function will be called from within libpcre. Every time pcre_exec finds
 * a match for the regex it will call this function. This function will save
 * the match to the copyright object and tell the pcre_exec function to
 * continue searching for new matches.
 *
 * @param info pcre_callout_block created by the pcre_exec function. This function
 *             will only use the callout_data, subject, start_match, current_possition
 *             field of the pcre_callout_block structure.
 * @return integer telling pcre_exec if it should continue searching for matches
 */
int copyright_callout(pcre_callout_block* info)
{
  /* locals */
  char temp[1024];
  memset(temp, 0, sizeof(temp));
  struct copy_entry_internal new_entry;
  copy_entry prev;
  cvector data = (cvector)info->callout_data;
  int size = (info->current_position - info->start_match > 1023) ?
                1023 : info->current_position - info->start_match;

  /* initialize memory */
  copy_entry_init(&new_entry);

  /* copy information into the entry */
  strncpy(new_entry.text,
      &(info->subject[info->start_match]),
      size);
  new_entry.start_byte = info->start_match;
  new_entry.end_byte = info->start_match + size;

  /* copy the type that was found into the entry */
  switch(info->callout_number)
  {
    case(1) :
      strcpy(new_entry.name_match, "email");
      strcpy(new_entry.dict_match, "email");
      new_entry.type = "email";
      break;
    case(2) :
      strcpy(new_entry.name_match, "url");
      strcpy(new_entry.dict_match, "url");
      new_entry.type = "url";
      break;
    default :
      return 1;
  }

  /* only log the new entry if it wasn't already located by the regex */
  if(cvector_size(data) != 0)
  {
    prev = *(cvector_end(data) - 1);
    if(!strcmp(prev->dict_match, new_entry.dict_match)
        || !strcmp(prev->dict_match, new_entry.dict_match))
    {
      if(!(prev->start_byte <= new_entry.start_byte && prev->end_byte >= new_entry.end_byte))
      {
        cvector_push_back(data, &new_entry);
      }
    }
    else
    {
      cvector_push_back(data, &new_entry);
    }
  }
  else
  {
    cvector_push_back(data, &new_entry);
  }

  /* force the pcre_exec function to continue searching */
  return 1;
}

/* ************************************************************************** */
/* **** Constructor Destructor ********************************************** */
/* ************************************************************************** */

/**
 * the constructor for a copyright object
 *
 * @param copy the copyright object to initialize
 */
int copyright_init(copyright* copy, char* copy_dir, char* name_dir)
{
  /* call constructor for all sub objects */
  (*copy) = (copyright)calloc(1,sizeof(struct copyright_internal));
  radix_init(&((*copy)->dict));
  radix_init(&((*copy)->name));
  cvector_init(&((*copy)->entries), copy_entry_function_registry());

  /* setup the copy_dir and name_dir variables */

  /* load the dictionaries */
  if(!load_dictionary((*copy)->dict, copy_dir) ||
     !load_dictionary((*copy)->name, name_dir))
  {
    return 0;
  }

  (*copy)->reg_error_offset = 0;

  /* compile the regular expressions */
  (*copy)->email_re = pcre_compile(
      email_regex,
      PCRE_CASELESS,
      &(*copy)->reg_error,
      &(*copy)->reg_error_offset,
      NULL);
  (*copy)->url_re   = pcre_compile(
      url_regex,
      PCRE_CASELESS,
      &(*copy)->reg_error,
      &(*copy)->reg_error_offset,
      NULL);

  /* set the callout function */
  pcre_callout = copyright_callout;

  return 1;
}

/**
 * the destructor for a copyright object
 *
 * @param copy
 */
void copyright_destroy(copyright copy)
{
  radix_destroy(copy->dict);
  radix_destroy(copy->name);
  cvector_destroy(copy->entries);
  pcre_free(copy->email_re);
  pcre_free(copy->url_re);
  free(copy);
}

/* ************************************************************************** */
/* **** Modifier Functions ************************************************** */
/* ************************************************************************** */

/**
 * @brief clear all copyright specific information
 *
 * clears the set of matches found by any previous calls to analyze and clears
 * the file_name that will be opened if analyze is called
 *
 * @param copy the copyright object to clear
 */
void copyright_clear(copyright copy)
{
  cvector_clear(copy->entries);
}

/**
 * @brief analyze a file for the copyright information
 *
 * This will open and analyze the file provided. It is important to note that
 * this function will clear any information that was previously stored in copy.
 * All information that is needed should be extracted from copy after a call to
 * analyze before calling analyze again.
 *
 * @param copy the copyright instance that will be analyzed
 * @param file_name the name of the file to be openned and analyzed
 */
void copyright_analyze(copyright copy, FILE* istr)
{
  /* local variables */
  char buf[MAXBUF];
  int i, bufidx, bufsize;
  int beg = 0, end = 0;
  char temp[1024];
  copy_entry entry;

  /* get memory for the copyright entry */
  entry = (copy_entry)calloc(1, sizeof(struct copy_entry_internal));

  /* open the relevant file */
  fseek(istr, 0, SEEK_SET);

  /* clear any previous information stored in copy */
  copyright_clear(copy);

  /* read the beginning 1M from the file */
  memset(buf, '\0', sizeof(buf));
  bufsize = fread(buf, sizeof(char), sizeof(buf)/sizeof(char), istr);
  buf[bufsize-1] = 0;

  /* convert file to lower case and validate any characters */
  for (i=0; i<bufsize; i++)
  {
    buf[i] = tolower(buf[i]);
    if(buf[i] < 0)
      buf[i] = 32;
  }

  /* look through the whole file for something in the dictionary */
  for(bufidx = 0; bufidx < bufsize; bufidx = end+1)
  {
    copy_entry_init(entry);
    bufidx += contains_copyright(copy->dict, &buf[bufidx], temp);
    if(bufidx < bufsize)
    {
      /* copy the dictionary entry into the copyright entry */
      strcpy(entry->dict_match, temp);

      /* grab the begging and end of the match */
      beg = find_beginning(buf, bufidx);
      end = find_end(buf, bufidx, bufsize);

      /* copy the match into a new entry */
      memcpy(entry->text, &buf[beg+1], end-beg);
      entry->text[end-beg]=0;
      entry->start_byte = beg;
      entry->end_byte = end;
      entry->type = "statement";

      /* push the string onto the list and increment bufidx */
      contains_copyright(copy->name, entry->text, temp);
      if(strlen(temp))
      {
        strcpy(entry->name_match, temp);
        cvector_push_back(copy->entries, entry);
      }
    }
  }

  copyright_email_url(copy, buf);
  strip_empty_entries(copy);
  free(entry);
}

/**
 * function to specifically find emails and urls within a block of text. This
 * will make two different calls to pcre_exec to do this searching.
 *
 * @param copy the copyright to store the results in
 * @param file the text that will be searched for emails and urls
 */
void copyright_email_url(copyright copy, char* file)
{
  int ovector[30];
  memset(ovector, 0, sizeof(ovector));

  pcre_extra pass;

  pass.flags = PCRE_EXTRA_CALLOUT_DATA;
  pass.callout_data = copy->entries;

  pcre_exec(
      copy->email_re,                /* the compiled regular expression       */
      &pass,                         /* the pattern wasn't studied            */
      file,                          /* the string to be analyzed             */
      strlen(file),                  /* the size of the input string          */
      0,                             /* start with 0 offset into the string   */
      0,                             /* default options                       */
      ovector,                       /* vector to contain the return          */
      sizeof(ovector)/sizeof(int));  /* the size of the return vector         */
  pcre_exec(
      copy->url_re,                  /* the compiled regular expression       */
      &pass,                         /* the pattern wasn't studied            */
      file,                          /* the string to be analyzed             */
      strlen(file),                  /* the size of the input string          */
      0,                             /* start with 0 offset into the string   */
      0,                             /* default options                       */
      ovector,                       /* vector to contain the return          */
      sizeof(ovector)/sizeof(int));  /* the size of the return vector         */
}

/* ************************************************************************** */
/* **** Accessor Functions ************************************************** */
/* ************************************************************************** */

/**
 * @brief gets an iterator to the beginning of the set of matches
 *
 * gets an iterator to the beginning of the set of matches that this copyright
 * has found.
 *
 * @param copy the copyright to get the beginning of
 * @return an iterator to the beginning of the copyright's matches
 */
copyright_iterator copyright_begin(copyright copy)
{
  return (copyright_iterator)cvector_begin(copy->entries);
}

/**
 * @brief gets an iterator just past the end of the set of matches
 *
 *  gets an iterator just pas the end of the set of matches that this copyright
 * has found.
 *
 * @param copy the copyright to get the beginning of
 * @return an iterator just past the end of the copyright's matches
 */
copyright_iterator copyright_end(copyright copy)
{
  return (copyright_iterator)cvector_end(copy->entries);
}

/**
 * @brief bounds checked access function, gets the match at index
 *
 * @param copy the copyright object to grab from
 * @param index the index to grab
 * @return the match at index
 */
copy_entry copyright_at(copyright copy, int index)
{
  return (copy_entry)cvector_at(copy->entries, index);
}

/**
 * @brief simple access function, gets the match at index
 *
 * @param copy the copyright object to grab from
 * @param index the index to grab
 * @return the match at index
 */
copy_entry copyright_get(copyright copy, int index)
{
  return (copy_entry)cvector_get(copy->entries, index);
}

/**
 * @brief gets the number of copyrights
 *
 * retrieves the number of copyrights that were found in the previous call to
 * copyright analyze(). If a call to copyright_analyze() has not been made this
 * will return 0.
 *
 * @param copy the relevant copyright instance
 * @return the number of copyrights
 */
int copyright_size(copyright copy)
{
  return cvector_size(copy->entries);
}

/* ************************************************************************** */
/* **** Entry Accessor Functions ******************************************** */
/* ************************************************************************** */

/**
 * @brief gets the text of the copyright entry
 *
 * @param entry the entry to get the text from
 */
char* copy_entry_text(copy_entry entry)
{
  return entry->text;
}

/**
 * @brief gets the name that is associated with this copyright entry
 *
 * @param entry the entry to get the name from
 */
char* copy_entry_name(copy_entry entry)
{
  return entry->name_match;
}

/**
 * @brief gets the dictionary entry that matches this entry
 *
 * @param the entry to get the dictionary entry from
 */
char* copy_entry_dict(copy_entry entry)
{
  return entry->dict_match;
}

/**
 * @brief gets the type of entry this is, i.e. how it was found
 *
 * @param entry the entry to get the type for
 * @return the string type of the entry
 */
char* copy_entry_type(copy_entry entry)
{
  return entry->type;
}

/**
 * @brief gets the number of the start byte for the text of the entry
 *
 * @param the entry to get the start byte from
 */
int copy_entry_start(copy_entry entry)
{
  return entry->start_byte;
}

/**
 * @brief gets the number of the end byte for the text of the entry
 *
 * @param the entry to get the end byte from
 */
int copy_entry_end(copy_entry entry)
{
  return entry->end_byte;
}
