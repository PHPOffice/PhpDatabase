# PhpDatabase
PhpDatabase is a pure PHP library to read and write database documents.

| Format | Extension | Read | Write | Notes |
|--------|-----------|:----:|:-----:|-------|
| Microsoft Access 2007+ | `.accdb` | ✅ | ✅ | Jet/ACE pages, memo/OLE long values, Unicode compression, indexes, relationships, properties |
| Microsoft Access 2000-2003 | `.mdb` | ✅ | ✅ | Jet 4 (Access 97/Jet 3 is not supported) |
| OpenDocument Database (LibreOffice Base) | `.odb` | ✅ | ✅ | embedded HSQLDB 1.8 database: MEMORY and CACHED tables |
| SQLite 3 | `.sqlite`, `.sqlite3`, `.db` | ✅ | ✅ | B-trees, overflow pages, `WITHOUT ROWID` tables, UTF-8/UTF-16, WAL file |

No database engine, PDO driver or `zip` extension is needed: the files are read and written by the
library itself (ODB packages through PclZip, with [phpoffice/common](https://github.com/PHPOffice/Common)).
Only `dom`, `mbstring` and `zlib` are required.

The features supported by each reader and writer (versions, storage, schema, data types, document
properties) are listed in [docs/features.md](docs/features.md); format internals are described in
[docs/formats.md](docs/formats.md).

## Installation

```bash
composer require phpoffice/phpdatabase
```

## Usage

### Create a database

```php
use PhpOffice\PhpDatabase\ColumnType;
use PhpOffice\PhpDatabase\Database;
use PhpOffice\PhpDatabase\ForeignKey;
use PhpOffice\PhpDatabase\IOFactory;

$database = new Database();
$database->getDocumentProperties()->setTitle('Library');

$books = $database->createTable('Books');
$books->createColumn('ID', ColumnType::INTEGER)->setPrimaryKey()->setAutoIncrement();
$books->createColumn('Title', ColumnType::TEXT, 200)->setNullable(false);
$books->createColumn('Price', ColumnType::DECIMAL)->setPrecision(8, 2);
$books->createColumn('Published', ColumnType::DATE);
$books->createColumn('Available', ColumnType::BOOLEAN)->setDefaultValue(true);
$books->createColumn('Cover', ColumnType::BLOB);

$books->addRow(['Title' => 'Les Misérables', 'Price' => '12.90', 'Published' => '1862-04-03']);
$books->addRow([null, 'Germinal', 8.2, new DateTimeImmutable('1885-03-01'), false, null]); // list in the column order

$authors = $database->createTable('Authors');
$authors->createColumn('ID', ColumnType::INTEGER)->setPrimaryKey()->setAutoIncrement();
$authors->createColumn('Name', ColumnType::TEXT, 100)->setDescription('Full name');
$books->createColumn('AuthorID', ColumnType::INTEGER);
$books->createIndex('BooksTitle', ['Title']);                       // unique: createIndex(..., true)
$books->createForeignKey(['AuthorID'], 'Authors', ['ID'], 'BooksAuthors')
    ->setOnDelete(ForeignKey::CASCADE);

$writer = IOFactory::createWriter($database, 'Accdb'); // Accdb, Odb, Sqlite
$writer->save('library.accdb');
```

### Read a file

```php
use PhpOffice\PhpDatabase\IOFactory;
use PhpOffice\PhpDatabase\Reader\ReaderInterface;

$database = IOFactory::load('library.odb');          // format detected from the content
// or: IOFactory::createReader('Odb')->load('library.odb', ReaderInterface::SKIP_ROWS);

foreach ($database->getTables() as $table) {
    echo $table->getName(), ': ', $table->getRowCount(), ' rows', PHP_EOL;
    foreach ($table->getColumns() as $column) {
        echo '  ', $column->getName(), ' ', $column->getType(), $column->isPrimaryKey() ? ' (PK)' : '', PHP_EOL;
    }
    foreach ($table->getRows() as $row) {
        echo '  ', $row['Title'], PHP_EOL;
    }
}
```

### Views, constraints and expressions

The SQL of views, check constraints, partial indexes and default expressions is written in standard SQL
(`"identifier"`, `'text'`, `||`, `DATE '2024-01-31'`, `CURRENT_TIMESTAMP`, `LIMIT n`); each writer
converts it to the dialect of its format (SQLite, HSQLDB, Access: `[identifier]`, `"text"`, `&`,
`#2024-01-31#`, `Now()`, `TOP n`).

```php
use PhpOffice\PhpDatabase\Expression;
use PhpOffice\PhpDatabase\Index;

$books->getColumn('Price')->setDefaultValue(0);
$books->createColumn('Added', ColumnType::DATETIME)->setDefaultValue(Expression::currentTimestamp());
$books->createCheck('"Price" >= 0');
$books->addIndex(new Index('BooksByPrice', ['Price' => 'DESC', 'Title' => 'ASC']));
$books->addIndex((new Index('BooksCheap', ['Title']))->setWhere('"Price" < 10'));      // SQLite only
$books->addIndex(new Index('BooksLower', [new Expression('lower("Title")')]));        // SQLite only
$database->createView('CheapBooks', 'SELECT "Title", "Price" FROM "Books" WHERE "Price" < 10');
$database->createTrigger('BooksAudit', 'Books', 'CREATE TRIGGER ...');              // SQLite only

$writer = IOFactory::createWriter($database, 'Odb');
$writer->save('library.odb');
print_r($writer->getWarnings()); // objects the format can't hold (here: the SQLite only indexes and the trigger)
```

### Convert

```php
IOFactory::save(IOFactory::load('legacy.mdb'), 'legacy.sqlite');   // writer chosen from the extension
IOFactory::save(IOFactory::load('data.sqlite'), 'data.odb');
```

### Access file format

```php
use PhpOffice\PhpDatabase\Writer\Accdb;

(new Accdb($database))->setFormat(Accdb::FORMAT_ACCDB_2016)->save('data.accdb'); // BIGINT as "Large Number"
// FORMAT_MDB_2000, FORMAT_ACCDB_2007 (default for .accdb), FORMAT_ACCDB_2010, FORMAT_ACCDB_2016,
// FORMAT_ACCDB_2019 ("Date/Time Extended" for the columns with setFractionalSeconds(7))
```

## Object model

- `Database`: the document (like `PhpPresentation`), with `DocumentProperties`, tables, views and triggers.
- `Table`: name, description, columns, rows, indexes, foreign keys, check constraints, `WITHOUT ROWID`
  (SQLite). A row is an array indexed by column name, holding PHP values.
- `Column`: name, type, length, precision/scale, nullable, primary key, auto-increment, default value
  (a value or an `Expression`), description, fractional seconds digits, multi-valued (Access).
- `Index`: name, columns (with their sort direction, or an `Expression`), unique, `WHERE` condition.
- `CheckConstraint`: expression (standard SQL), name.
- `View`: name, `SELECT` statement (standard SQL), description. Access select and union queries are
  read as views.
- `Trigger`: name, table, complete `CREATE TRIGGER` statement (SQLite).
- `Attachment`: file of an Access attachment column (name, data, type, modification date).
- `ForeignKey`: name, columns, referenced table and columns, `ON DELETE` / `ON UPDATE` actions
  (`NO ACTION`, `CASCADE`, `SET NULL`, `SET DEFAULT`, `RESTRICT`).
- `ColumnType`: the logical types and their PHP values:

| ColumnType | PHP value | SQLite | HSQLDB (ODB) | Access |
|------------|-----------|--------|--------------|--------|
| `BOOLEAN` | `bool` | `BOOLEAN` (0/1) | `BOOLEAN` | Yes/No |
| `TINYINT`, `SMALLINT`, `INTEGER`, `BIGINT` | `int` | `TINYINT`... | `TINYINT`... | Byte, Integer, Long Integer, Large Number (2016) |
| `FLOAT`, `DOUBLE` | `float` | `REAL`, `DOUBLE` | `REAL`, `DOUBLE` | Single, Double |
| `DECIMAL` | numeric `string` | `DECIMAL(p,s)` | `DECIMAL(p,s)` | Decimal, Currency for `DECIMAL(19,4)` |
| `TEXT` | `string` | `VARCHAR(n)` / `TEXT` | `VARCHAR(n)` | Short Text (≤ 255), Long Text above |
| `LONGTEXT` | `string` | `TEXT` | `LONGVARCHAR` | Long Text (memo) |
| `DATE`, `TIME`, `DATETIME` | `DateTimeImmutable` | ISO 8601 text | `DATE`, `TIME`, `TIMESTAMP` | Date/Time |
| `BLOB` | binary `string` | `BLOB` | `LONGVARBINARY` | OLE Object |
| `GUID` | `string` (`{XXXXXXXX-...}`) | `GUID` (text) | `CHAR(38)` | Replication ID |
| `HYPERLINK` | `string` | `HYPERLINK TEXT` | `LONGVARCHAR` | Hyperlink |
| `ATTACHMENT` | `Attachment[]` | file names (text) | file names (text) | read; written as text |

A multi-valued column (`Column::setMultiValued()`) holds a list of values: read from Access, written
as text (`"a; b"`) by all the writers.

Values given to `Table::addRow()` are converted to these PHP types (`'abc'` for an `INTEGER` column
throws an `InvalidArgumentException`). Auto-increment columns get the next value when they are `null`.

## Limits

- Access: generated files are validated with [Jackcess](https://jackcess.sourceforge.io/) (reading,
  index lookups, relationships with cascades, inserting rows, creating tables) and this library's
  reader. They have **not** been tested in Microsoft Access. They are built from empty databases
  created by Access (see [NOTICE](NOTICE)), like Jackcess does.
- Access: select and union queries are read and written as views; the other queries (action,
  crosstab, data definition, pass-through, with parameters), forms, reports and macros are not.
  Check constraints are combined in the validation rule of the table. Attachments and multi-valued
  fields are read, but written as text. Calculated columns (Access 2010) are read as plain values
  (their decimals with 18 digits after the point), their expression is not. Dates keep
  microseconds (the 7th digit of the extended date/time is lost). `SET DEFAULT` and `RESTRICT` are
  written as "no action". Encrypted or password protected databases (Office crypto) are not supported.
- ODB: only the embedded HSQLDB engine is supported (not Firebird, not external data sources).
  Tables are written as `MEMORY` tables, which LibreOffice opens like the `CACHED` tables it creates.
  A foreign key is written only when the referenced columns are a primary key or a unique index
  (required by HSQLDB). HSQLDB 1.8 has no partial/expression indexes nor triggers, no descending
  index columns; views are written only when HSQLDB can load them (an invalid view prevents the
  whole database from opening). The `database/log` file of an unclosed database is replayed. Dates of
  the CACHED tables are stored by HSQLDB in the time zone of the Java VM: they are read in the
  default time zone of PHP.
- SQLite: the `-wal` file next to the database is applied when reading (committed frames only).
  Virtual tables are not read nor written. Expression and partial indexes are written for the
  expressions handled by the evaluator of the library (SQLite semantics: operators, `CASE`, `CAST`,
  `lower`, `substr`...). Index names are global in SQLite: they must be unique in the database.
  Values not matching the declared type of their column are read as `null`.
- Files are read in memory: very large databases need some memory.

## Development

```bash
composer install
composer test       # PHPUnit
composer phpstan    # PHPStan (level 6)
composer samples
```

## License

PhpOffice\PhpDatabase is licensed under [LGPL v3](COPYING.LESSER). The Access templates and index code
tables come from Jackcess (Apache License 2.0): see [NOTICE](NOTICE).
