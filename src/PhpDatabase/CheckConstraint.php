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

namespace PhpOffice\PhpDatabase;

/**
 * Check constraint of a table (Access: validation rule), as a "standard" SQL boolean expression
 * (see Expression).
 */
class CheckConstraint
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $expression;

    public function __construct(string $expression, string $name = '')
    {
        $this->expression = trim($expression);
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function setExpression(string $expression): self
    {
        $this->expression = trim($expression);

        return $this;
    }
}
