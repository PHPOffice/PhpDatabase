# Features support

✅ supported · ⚠️ partial (see the note) · ❌ not supported · — not applicable to the format

## File versions

| Feature | SQLite read | SQLite write | ODB read | ODB write | Access read | Access write |
|---------|:-:|:-:|:-:|:-:|:-:|:-:|
| SQLite 3 | ✅ | ✅ | — | — | — | — |
| ODB with embedded HSQLDB 1.8 | — | — | ✅ | ✅ | — | — |
| ODB with embedded Firebird (LibreOffice 6.1+ option) | — | — | ❌ | ❌ | — | — |
| ODB connected to an external data source | — | — | ⚠️ ¹ | ❌ | — | — |
| Access 97 (Jet 3) | — | — | — | — | ❌ | ❌ |
| Access 2000-2003 (Jet 4, `.mdb`) | — | — | — | — | ✅ | ✅ |
| Access 2007, 2010, 2016 (ACE, `.accdb`) | — | — | — | — | ✅ | ✅ |
| Access 2019 (`.accdb`, "Date/Time Extended") | — | — | — | — | ✅ | ✅ |
| Encoded Jet 4 database (RC4 page encoding) | — | — | — | — | ✅ | ❌ |
| Encrypted / password protected database | ❌ | ❌ | — | — | ❌ | ❌ |

¹ Only the URL of the data source is read (in the description of the document).

## Storage

| Feature | SQLite read | SQLite write | ODB read | ODB write | Access read | Access write |
|---------|:-:|:-:|:-:|:-:|:-:|:-:|
| Tables with rowid | ✅ | ✅ | — | — | — | — |
| `WITHOUT ROWID` tables | ✅ | ✅ | — | — | — | — |
| `MEMORY` tables (rows in `database/script`) | — | — | ✅ | ✅ | — | — |
| `CACHED` tables (rows in `database/data`, LibreOffice default) | — | — | ✅ | ❌ ³ | — | — |
| `TEXT` tables (external CSV files) | — | — | ⚠️ ⁴ | ❌ | — | — |
| Long values | ✅ overflow pages | ✅ | ✅ | ✅ | ✅ memo/OLE (inline, LVAL page, LVAL chain) | ✅ |
| Write-ahead log / journal | ✅ `-wal` file | — | ✅ `database/log` ² | — | — | — |
| Unicode | ✅ UTF-8, UTF-16le/be | ✅ UTF-8 | ✅ | ✅ | ✅ UTF-16, Unicode compression | ✅ |

² Changes of a database closed without a checkpoint (or after a crash) are replayed, like HSQLDB does
when it opens the database: the uncommitted transactions are dropped.
³ LibreOffice opens `MEMORY` tables like the `CACHED` tables it creates.
⁴ Structure only: the CSV files are not part of the ODB package.

## Schema

| Feature | SQLite read | SQLite write | ODB read | ODB write | Access read | Access write |
|---------|:-:|:-:|:-:|:-:|:-:|:-:|
| Tables, columns, rows | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Primary key (one or several columns) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Auto-increment | ✅ `INTEGER PRIMARY KEY` | ✅ | ✅ identity | ✅ | ✅ AutoNumber | ✅ Long Integer |
| Not null | ✅ | ✅ | ✅ | ✅ | ✅ Required | ✅ Required |
| Default values (literals) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Default expressions (`CURRENT_TIMESTAMP`, `=Now()`...) | ✅ | ✅ | ✅ | ⚠️ ⁵ | ✅ | ✅ |
| Descriptions of the tables and columns | — | — | — | — | ✅ | ✅ |
| Secondary indexes | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Unique indexes / constraints | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Descending index columns | ✅ | ✅ | — ⁶ | — ⁶ | ✅ | ✅ |
| Partial (`WHERE`) and expression indexes | ✅ | ⚠️ ⁹ | — | — | — | — |
| Foreign keys / relationships | ✅ | ✅ | ✅ | ⚠️ ⁷ | ✅ | ✅ |
| `ON DELETE` / `ON UPDATE` actions | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ ⁸ |
| Check constraints | ✅ | ✅ | ✅ | ✅ | ✅ validation rules ¹⁰ | ✅ ¹⁰ |
| Views / queries | ✅ | ✅ | ✅ ¹² | ⚠️ ¹¹ | ⚠️ ¹³ | ⚠️ ¹³ |
| Triggers | ✅ | ✅ | ❌ | ❌ | — | — |
| Forms, reports, macros, modules | — | — | ❌ | ❌ | ❌ | ❌ |

⁵ `CURRENT_DATE`, `CURRENT_TIME` and `CURRENT_TIMESTAMP` only (other expressions: warning).
⁶ HSQLDB 1.8 ignores the sort direction of the index columns.
⁷ Written only when the referenced columns are a primary key or a unique index (required by HSQLDB).
⁸ `CASCADE` and `SET NULL` (on delete); `SET DEFAULT` and `RESTRICT` are written as "no action".
⁹ The index entries are computed by the evaluator of the library (SQLite semantics): operators,
`CASE`, `CAST`, `lower`, `upper`, `length`, `substr`, `coalesce`... Other expressions: warning.
¹⁰ Validation rule of the table (read as one constraint, the constraints are combined with `And` when
written); validation rule of a column (read as a constraint on this column).
¹¹ Written when the statement is a portable `SELECT` that HSQLDB 1.8 can load (functions, `FROM` required,
no `WITH`/`EXCEPT`/`INTERSECT`...): an invalid view would prevent the whole database from opening.
¹² Views of HSQLDB and queries of LibreOffice Base (`content.xml`).
¹³ Select and union queries (joins, `DISTINCT`, `TOP`, `GROUP BY`, `HAVING`, `ORDER BY`). Action,
crosstab, data definition, pass-through and parameter queries are not read; subqueries in `FROM`,
`LIMIT ... OFFSET` are not written.

## Data types

| Feature | SQLite read | SQLite write | ODB read | ODB write | Access read | Access write |
|---------|:-:|:-:|:-:|:-:|:-:|:-:|
| Boolean, integers, float, double, decimal | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `BIGINT` | ✅ | ✅ | ✅ | ✅ | ✅ Large Number | ⚠️ Large Number with the 2016 format, else Decimal(19) |
| Text, long text | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Date, time, date/time | ✅ ISO 8601 text, Julian day, Unix time | ✅ ISO 8601 text | ✅ | ✅ | ✅ | ✅ |
| Binary (BLOB, OLE object) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| GUID (Replication ID) | ✅ `GUID` | ✅ | ✅ `CHAR(38)` | ✅ | ✅ | ✅ |
| Fractions of seconds | ✅ | ✅ | ✅ | ✅ | ✅ ¹⁴ | ✅ ¹⁴ |
| Attachments, multi-valued fields (complex columns) | — | ⚠️ text | — | ⚠️ text | ✅ ¹⁵ | ⚠️ text |
| Calculated columns (Access 2010) | — | — | — | — | ⚠️ values only | ❌ |
| Hyperlink | ✅ `HYPERLINK TEXT` | ✅ | — | ⚠️ long text | ✅ | ✅ |
| Values not matching the declared type | ⚠️ read as `null` | — | — | — | — | — |

¹⁴ Milliseconds with Date/Time; "Date/Time Extended" (Access 2019, `FORMAT_ACCDB_2019` and
`Column::setFractionalSeconds()` > 3) up to the microsecond (PHP), the 7th digit is lost.
¹⁵ The version history of the "append only" memo columns is skipped.

## Document

| Feature | SQLite read | SQLite write | ODB read | ODB write | Access read | Access write |
|---------|:-:|:-:|:-:|:-:|:-:|:-:|
| Title | ⚠️ file name | — | ✅ | ✅ | ✅ | ✅ |
| Creator / author | — | — | ✅ | ✅ | ✅ | ✅ |
| Description / comments | — | — | ✅ | ✅ | ✅ | ✅ |
| Creation date | — | — | ✅ | ✅ | ✅ | ✅ |
| Modification date | — | — | ✅ | ✅ | ❌ | ❌ |

## Reader and writer options

| Option | Formats |
|--------|---------|
| `ReaderInterface::SKIP_ROWS`: structure only | all readers |
| `Reader\Sqlite::loadFromString()` (with an optional WAL) | SQLite |
| `Writer\Sqlite::setPageSize()` | SQLite |
| `Writer\Accdb::setFormat()`: `FORMAT_MDB_2000`, `FORMAT_ACCDB_2007`, `FORMAT_ACCDB_2010`, `FORMAT_ACCDB_2016`, `FORMAT_ACCDB_2019` | Access |
| `WriterInterface::getWarnings()`: objects not written by the last save (unsupported by the format) | all writers |
