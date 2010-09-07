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

#ifndef SQL_STATEMENTS_H_INCLUDE
#define SQL_STATEMENTS_H_INCLUDE

/**
 * @file sql_statements.h
 * @version v1.3
 *
 * The purpose of this file is to make consolidate the sql statements and make
 * allow them to be readable. This file will allow for the correct indentation
 * in the sql statement since the c indentation is unimportant.
 */

/**
 * Given a pfile number this sql statement will attempt to retrieve a set of
 * filenames from the database for analysis
 */
char* fetch_pfile = "\
SELECT pfile_pk, pfile_sha1 || '.' || pfile_md5 || '.' || pfile_size AS pfilename \
  FROM ( \
    SELECT distinct(pfile_fk) AS PF \
      FROM uploadtree \
      WHERE upload_fk = %d and (ufile_mode&x'3C000000'::int)=0 \
  ) AS SS \
    left outer join copyright on (PF = pfile_fk and agent_fk = %d) \
    inner join pfile on (PF = pfile_pk) \
  WHERE ct_pk IS null ";

/**
 * This will enter that no copyright entries were found for the file that was
 * just analyzed
 *
 * TODO determine if this is needed
 */
char* insert_no_copyright = "\
INSERT INTO copyright (agent_fk, pfile_fk, copy_startbyte, copy_endbyte, content, hash, type) \
  VALUES (%d, %d, NULL, NULL, NULL, NULL, 'statement')";

/**
 * This will enter that a copyright has been found for the file that was just
 * analyzed, it enters the start, end, and text of the entry.
 *
 * TODO determine the purpose of the hash and type and if I need to keep those.
 */
char* insert_copyright = "\
INSERT INTO copyright (agent_fk, pfile_fk, copy_startbyte, copy_endbyte, content, hash, type) \
  VALUES (%d, %d, %d, %d, E'%s', E'%s', '%s')";

/** This will check to see if the copyright table exists. */
char* check_database_table = "\
SELECT ct_pk \
  FROM copyright \
  LIMIT 1";

/** This will create the copyright sequence in the database */
char* create_database_squence = "\
CREATE SEQUENCE copyright_ct_pk_seq \
  START WITH 1 \
  INCREMENT BY 1 \
  NO MAXVALUE \
  NO MINVALUE \
  CACHE 1";

/** create the table to the copyright agent */
char* create_database_table = "\
CREATE TABLE copyright (\
  ct_pk bigint            PRIMARY KEY DEFAULT nextval('copyright_ct_pk_seq'::regclass),\
  agent_fk bigint         NOT NULL,\
  pfile_fk bigint         NOT NULL,\
  content text,\
  hash text,\
  type text               CHECK (type in ('statement', 'email', 'url')),\
  copy_startbyte integer,\
  copy_endbyte integer)";

/** create the copyright pfile foreign key index */
char* create_pfile_foreign_index = "\
CREATE INDEX copyright_pfile_fk_index\
  ON copyright\
  USING BTREE (pfile_fk)";

/** create the copyright pfile foreign key index */
char* create_agent_foreign_index = "\
CREATE INDEX copyright_agent_fk_index\
  ON copyright\
  USING BTREE (agent_fk)";

/** Change the owner of the copyright table to fossy*/
char* alter_database_table = "\
ALTER TABLE public.copyright_ct_pk_seq\
  OWNER TO fossy";

/** change the owner of the copyright table */
char* alter_copyright_owner = "\
ALTER TABLE public.copyright\
  OWNER TO fossy";

/** TODO ??? */
char* alter_table_pfile = " \
ALTER TABLE ONLY copyright \
  ADD CONSTRAINT pfile_fk \
  FOREIGN KEY (pfile_fk) \
  REFERENCES pfile(pfile_pk)";

/** TODO ??? */
char* alter_table_agent = " \
ALTER TABLE ONLY copyright \
  ADD CONSTRAINT agent_fk \
  FOREIGN KEY (agent_fk) \
  REFERENCES agent(agent_pk)";

#endif /* SQL_STATEMENTS_H_INCLUDE */
