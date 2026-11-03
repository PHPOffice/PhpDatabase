<?php

/**
 * This file is part of PhpOffice\PhpDatabase - A pure PHP library for reading and writing
 * database documents (ACCDB, ODB, SQLite).
 *
 * PhpOffice\PhpDatabase is free software distributed under the terms of the GNU Lesser
 * General Public License version 3 as published by the Free Software Foundation.
 *
 * @license http://www.gnu.org/licenses/lgpl.txt LGPL version 3
 */

declare(strict_types=1);

namespace PhpOffice\PhpDatabase\Tests\Shared;

use InvalidArgumentException;
use PhpOffice\PhpDatabase\Shared\Access\QueryFormat;
use PhpOffice\PhpDatabase\Shared\Sql\Dialect;
use PhpOffice\PhpDatabase\Tests\AbstractTestCase;
use PhpOffice\PhpDatabase\Writer\Sqlite\Evaluator;

/**
 * SQL dialects, expression evaluator and Access queries.
 */
class SqlTest extends AbstractTestCase
{
    public function testDialects(): void
    {
        $access = 'SELECT DISTINCTROW TOP 3 [Name] & "x" AS n FROM [My Table] WHERE d > #12/31/2023# AND [Name] Like "a*" AND x Mod 2 = 1 AND Created < Now()';
        $standard = Dialect::convert($access, Dialect::ACCESS, Dialect::STANDARD);
        self::assertSame('SELECT DISTINCT "Name" || \'x\' AS n FROM "My Table" WHERE d > DATE \'2023-12-31\' AND "Name" Like \'a%\' AND x % 2 = 1 AND Created < CURRENT_TIMESTAMP LIMIT 3', $standard);
        self::assertSame('SELECT DISTINCT TOP 3 [Name] & "x" AS n FROM [My Table] WHERE d > #2023-12-31# AND [Name] Like "a*" AND x Mod 2 = 1 AND Created < Now()', Dialect::convert($standard, Dialect::STANDARD, Dialect::ACCESS));
        self::assertSame('SELECT DISTINCT "Name" || \'x\' AS "n" FROM "My Table" WHERE "d" > DATE \'2023-12-31\' AND "Name" Like \'a%\' AND "x" % 2 = 1 AND "Created" < CURRENT_TIMESTAMP LIMIT 3', Dialect::convert($standard, Dialect::STANDARD, Dialect::HSQLDB));
        self::assertStringContainsString("d > '2023-12-31'", Dialect::convert($standard, Dialect::STANDARD, Dialect::SQLITE));
        self::assertSame('SELECT "A" FROM "T" LIMIT 5 OFFSET 2', Dialect::convert('SELECT LIMIT 2 5 "A" FROM "T"', Dialect::HSQLDB, Dialect::STANDARD));
        self::assertSame('SELECT LIMIT 2 5 "A" FROM "T"', Dialect::convert('SELECT "A" FROM "T" LIMIT 5 OFFSET 2', Dialect::STANDARD, Dialect::HSQLDB));
        self::assertSame('SELECT Mod(1, 2)', Dialect::convert('SELECT Mod(1, 2)', Dialect::ACCESS, Dialect::STANDARD));
        self::assertTrue(Dialect::isPortableSelect('SELECT 1'));
        self::assertFalse(Dialect::isPortableSelect('DELETE FROM t'));
        self::assertNull(Dialect::checkHsqldb('SELECT "a" FROM "t"'));
        self::assertNotNull(Dialect::checkHsqldb('SELECT a FROM t WHERE a GLOB x'));
        self::assertNotNull(Dialect::checkHsqldb('SELECT * FROM (SELECT 1) s'));
        self::assertNull(Dialect::checkHsqldb('SELECT * FROM (SELECT "a" FROM "t") s WHERE "a" IN (SELECT "b" FROM "u")'));
    }

    public function testEvaluator(): void
    {
        $expression = Evaluator::compile("lower(name) || '-' || (price * 2) LIKE 'pen%' AND price BETWEEN 1 AND 5 AND name IN ('Pen', 'Book') AND CASE WHEN price > 2 THEN 0 ELSE 1 END = 1");
        self::assertSame(1, $expression(['name' => 'Pen', 'price' => 1.5]));
        self::assertSame(0, $expression(['name' => 'Book', 'price' => 3]));
        self::assertTrue(Evaluator::isTrue($expression(['name' => 'Pen', 'price' => 1.5])));
        // SQLite semantics: integer division, ASCII only case folding, NULL propagation
        self::assertSame(3, Evaluator::compile('7 / 2')([]));
        self::assertSame('é', Evaluator::compile("upper('é')")([]));
        self::assertSame('a', Evaluator::compile("coalesce(NULL, 'a')")([]));
        self::assertNull(Evaluator::compile('1 + NULL')([]));
        self::assertSame(1, Evaluator::compile('x IS NULL')(['x' => null]));
        $this->expectException(InvalidArgumentException::class);
        Evaluator::compile('unknown_function(1)');
    }

    public function testQueryFormat(): void
    {
        $query = QueryFormat::fromSql('SELECT DISTINCT i."Name", c."Cat" AS k FROM "Items" i INNER JOIN "Cats" c ON i."ID" = c."ItemID" WHERE i."Price" < 10 GROUP BY i."Name", c."Cat" ORDER BY i."Name" DESC LIMIT 5');
        self::assertNotNull($query);
        self::assertSame(QueryFormat::OBJECT_FLAG_SELECT, $query['flags']);
        $rows = array_map(static fn (array $row): array => ['Attribute' => $row[0], 'Expression' => $row[1], 'Flag' => $row[2], 'Name1' => $row[3], 'Name2' => $row[4], 'Order' => ''], $query['rows']);
        self::assertSame(
            "SELECT DISTINCT TOP 5 i.[Name], c.[Cat] AS k\nFROM Items AS i INNER JOIN Cats AS c ON i.[ID] = c.[ItemID]\nWHERE i.[Price] < 10\nGROUP BY i.[Name], c.[Cat]\nORDER BY i.[Name] DESC",
            QueryFormat::toSql($query['flags'], $rows)
        );
        $union = QueryFormat::fromSql('SELECT "a" FROM "t" UNION ALL SELECT "b" FROM "u" ORDER BY 1');
        self::assertNotNull($union);
        self::assertSame(QueryFormat::OBJECT_FLAG_UNION, $union['flags']);
        $rows = array_map(static fn (array $row): array => ['Attribute' => $row[0], 'Expression' => $row[1], 'Flag' => $row[2], 'Name1' => $row[3], 'Name2' => $row[4], 'Order' => ''], $union['rows']);
        self::assertSame("SELECT [a] FROM [t]\nUNION ALL SELECT [b] FROM [u]\nORDER BY 1", QueryFormat::toSql($union['flags'], $rows));
        // Not storable by Access
        self::assertNull(QueryFormat::fromSql('SELECT * FROM (SELECT 1) x'));
        self::assertNull(QueryFormat::fromSql('SELECT a FROM t LIMIT 5 OFFSET 2'));
        self::assertNull(QueryFormat::fromSql('DELETE FROM t'));
    }
}
