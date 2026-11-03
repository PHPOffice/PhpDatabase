# Formats

The features supported by each reader and writer are listed in [features.md](features.md).

## SQL of the model

Views, check constraints, partial indexes, expression indexes and default expressions hold standard SQL
(`"identifier"`, `'text'`, `||`, `DATE 'yyyy-mm-dd'`, `CURRENT_DATE/TIME/TIMESTAMP`, `LIMIT n [OFFSET m]`).
`Shared/Sql/Dialect` converts it from and to each format:

| Standard | SQLite | HSQLDB 1.8 | Access |
|----------|--------|------------|--------|
| `"Name"` | `"Name"` | `"Name"` (every identifier quoted: HSQLDB folds unquoted names to upper case) | `[Name]` |
| `'text'`, `a \|\| b` | same | same | `"text"`, `a & b` |
| `DATE '2024-01-31'` | `'2024-01-31'` | same | `#2024-01-31#` |
| `CURRENT_TIMESTAMP` | same | same | `Now()` (`Date()`, `Time()`) |
| `LIKE 'a%_'` | same | same | `Like "a*?"` |
| `x % 2` | same | same | `x Mod 2` |
| `LIMIT n OFFSET m` | same | `SELECT LIMIT m n ...` | `SELECT TOP n ...` (no offset) |

The SQLite writer computes the entries of the partial and expression indexes with `Writer/Sqlite/Evaluator`,
which follows the semantics of SQLite (integer division, ASCII only `lower`/`upper`, `NULL` propagation).

## SQLite 3

Reference: [Database File Format](https://www.sqlite.org/fileformat2.html).

- The schema (`sqlite_schema`) is parsed with a SQL tokenizer; the declared types are mapped with the
  [affinity rules](https://www.sqlite.org/datatype3.html) of SQLite.
- The writer builds 4 KB pages (`Writer\Sqlite::setPageSize()`): table b-trees, overflow pages and the
  index b-trees of the primary key (when it is not an `INTEGER PRIMARY KEY`) and of the indexes.
  Foreign keys are written in `CREATE TABLE` (`PRAGMA foreign_keys` is off by default in SQLite).
- Views and triggers are rows of `sqlite_schema` (root page 0); `WITHOUT ROWID` tables are index
  b-trees whose records start with the primary key; descending index columns are stored in descending
  order (schema format 4).
- The reader applies the write-ahead log (`<file>-wal`): the checksums of the frames are verified and
  only the frames up to the last commit are used, like SQLite does when it opens the database.
- Generated files pass `PRAGMA integrity_check`.

## ODB (LibreOffice Base)

An ODB file is a ZIP package (`mimetype`, `content.xml`, `META-INF/manifest.xml`...), read and written with
PclZip (the ZIP adapter of phpoffice/common), holding the files of an HSQLDB 1.8 database in `database/`:

- `script`: SQL statements: `CREATE TABLE`, `SET TABLE ... INDEX` (roots of the indexes of the CACHED tables),
  rows of the MEMORY tables (`INSERT INTO`). Non ASCII characters are written as `\uXXXX`.
- `data`: rows of the CACHED tables (the default of LibreOffice). Each row holds one AVL node per index;
  the reader walks the tree of the primary index from its root.
- `properties`: settings of the engine.
- `log`: statements executed since the last checkpoint, when the database was not closed properly
  (`/*Cn*/` session prefix, `INSERT`, `DELETE`, DDL, `COMMIT`, `SET AUTOCOMMIT`...; an update is a
  `DELETE` followed by an `INSERT`). The reader replays it like HSQLDB: the transactions not committed
  at the end of the log are dropped.

Queries saved by LibreOffice Base are in `content.xml` (`db:queries`): they are read as views.

The writer creates MEMORY tables, an identity column for an auto-increment integer primary key and
`ALTER TABLE ... RESTART WITH` for its next value, `CREATE [UNIQUE] INDEX` and
`ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY` (HSQLDB checks the references only when rows are
inserted later, not when it loads the script), table level `CHECK` constraints (HSQLDB 1.8 refuses
column level ones) and `CREATE VIEW`. HSQLDB refuses to open a database whose script holds an invalid
statement: the views are written only when they use the functions and syntax of HSQLDB 1.8 and
reference existing tables. Generated databases are validated by opening them with
HSQLDB 1.8.0.10 (the engine embedded in LibreOffice).

## Access (ACCDB, MDB)

The Jet 4 (Access 2000-2003) and ACE (Access 2007-2019) formats use 4 KB pages:

- page 0: header, partly RC4-encoded with a fixed key (version, code page, sort order, creation date);
- `MSysObjects` (table definition on page 2): catalog of the tables, containers and system objects;
- table definitions (TDEF pages, possibly chained): columns, real and logical indexes, usage maps;
- data pages: rows (null mask, fixed columns, variable columns and their offsets);
- LVAL pages: memo and OLE values larger than 64 bytes;
- index pages: B-tree nodes and leaves (entry masks, encoded keys);
- usage maps: inline bitmaps or reference maps listing the pages owned by a table.

Encoded Jet 4 databases (RC4 page encoding) are read. Access 2010+ encryption is not supported.

The writer starts from an empty database created by Access (`src/PhpDatabase/Resources/Access/`: one per
version, like Jackcess): its pages are kept, the user tables are appended, and the rows describing them
are added to the system tables `MSysObjects`, `MSysACEs` and `MSysRelationships`, whose indexes are
written again. The global usage map (free pages) is updated.

- Indexes: primary key, secondary indexes and the indexes of the relationships, for all the indexable
  types. Text keys use the "General Legacy" (Access 2000-2007) or "General" (2010+) sort order, with the
  code tables of Jackcess.
- Relationships: a row of `MSysRelationships` per column, with referential integrity when the referenced
  columns are unique, and cascade updates/deletes or "set null".
- Properties (`LvProp` column of `MSysObjects`): description of the tables and columns, `Required`,
  `DefaultValue` (literal or expression such as `Now()`), `ValidationRule` of the table (check
  constraints); title, author and comments of the database (`SummaryInfo`).
- Queries: a row of `MSysObjects` (type 5, flags: 0 select, 128 union) and one row of `MSysQueries` per
  element of the statement (`Shared/Access/QueryFormat`): type, flags (`DISTINCT`, `TOP`), tables,
  columns, joins, `WHERE`, `GROUP BY`, `HAVING`, `ORDER BY`; for a union, the SQL of its two parts.
- Types: GUID (Replication ID), hyperlink (memo with the hyperlink flag), "Date/Time Extended" of
  Access 2019 (42 ASCII bytes: days since 0001-01-01, seconds and 100 ns units; written with
  `FORMAT_ACCDB_2019`), descending index columns.
- The sort order of the text indexes is the one of the template ("General Legacy" for Access 2000-2007
  and for the Access 2019 template, "General" for 2010 and 2016).

Read only:

- Attachments and multi-valued fields (complex columns): the values are rows of a hidden "flat" table
  (`MSysComplexColumns`); the file of an attachment is stored zlib compressed with a header holding
  its type. The version history of the "append only" memo columns is skipped.
- Calculated columns (Access 2010): the type of the values is the `ResultType` property, each value is
  wrapped in a header of 20 bytes.

Generated files are validated with [Jackcess](https://jackcess.sourceforge.io/): reading, lookups through
the indexes (also descending, GUID and extended date indexes), referential integrity and cascades,
queries (`Query.toSQLString()`), rows inserted and tables created afterwards by Jackcess. They have not
been opened in Microsoft Access.
