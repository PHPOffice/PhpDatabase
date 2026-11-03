<?php

/**
 * This file is part of PhpOffice\PhpDatabase - A pure PHP library for reading and writing
 * database documents (ACCDB, ODB, SQLite).
 *
 * PhpOffice\PhpDatabase is free software distributed under the terms of the MIT License.
 *
 * @license https://opensource.org/licenses/MIT MIT
 */

declare(strict_types=1);

namespace PhpOffice\PhpDatabase;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Column of a table.
 */
class Column
{
    /**
     * @var string
     */
    protected $name;

    /**
     * One of the ColumnType constants.
     *
     * @var string
     */
    protected $type;

    /**
     * Maximum length of a TEXT column (null: not limited / format default).
     *
     * @var null|int
     */
    protected $length;

    /**
     * Total number of digits of a DECIMAL column.
     *
     * @var int
     */
    protected $precision = 18;

    /**
     * Number of digits after the decimal point of a DECIMAL column.
     *
     * @var int
     */
    protected $scale = 0;

    /**
     * @var bool
     */
    protected $nullable = true;

    /**
     * @var bool
     */
    protected $primaryKey = false;

    /**
     * @var bool
     */
    protected $autoIncrement = false;

    /**
     * Default value, as a PHP value of the column type.
     *
     * @var mixed
     */
    protected $defaultValue;

    /**
     * @var string
     */
    protected $description = '';

    /**
     * Number of digits of the fractions of seconds of a DATE/TIME/DATETIME column
     * (null: format default; Access 2016+: "Date/Time Extended" when > 3).
     *
     * @var null|int
     */
    protected $fractionalSeconds;

    /**
     * Multi-valued column (Access 2007+): the values are arrays.
     *
     * @var bool
     */
    protected $multiValued = false;

    public function __construct(string $name, string $type = ColumnType::TEXT, ?int $length = null)
    {
        $this->name = $name;
        $this->setType($type);
        $this->length = $length;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        if (!ColumnType::isValid($type)) {
            throw new InvalidArgumentException(sprintf('Unknown column type "%s"', $type));
        }
        $this->type = $type;

        return $this;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function setLength(?int $length): self
    {
        $this->length = $length;

        return $this;
    }

    public function getPrecision(): int
    {
        return $this->precision;
    }

    public function getScale(): int
    {
        return $this->scale;
    }

    public function setPrecision(int $precision, int $scale = 0): self
    {
        $this->precision = $precision;
        $this->scale = $scale;

        return $this;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function setNullable(bool $nullable): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function isPrimaryKey(): bool
    {
        return $this->primaryKey;
    }

    public function setPrimaryKey(bool $primaryKey = true): self
    {
        $this->primaryKey = $primaryKey;
        if ($primaryKey) {
            $this->nullable = false;
        }

        return $this;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function setAutoIncrement(bool $autoIncrement = true): self
    {
        $this->autoIncrement = $autoIncrement;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getDefaultValue()
    {
        return $this->defaultValue;
    }

    /**
     * @param mixed $defaultValue PHP value of the column type, or an Expression (CURRENT_TIMESTAMP...)
     */
    public function setDefaultValue($defaultValue): self
    {
        $this->defaultValue = $defaultValue instanceof Expression ? $defaultValue : $this->normalizeValue($defaultValue);

        return $this;
    }

    public function getFractionalSeconds(): ?int
    {
        return $this->fractionalSeconds;
    }

    public function setFractionalSeconds(?int $digits): self
    {
        $this->fractionalSeconds = null === $digits ? null : max(0, min(9, $digits));

        return $this;
    }

    public function isMultiValued(): bool
    {
        return $this->multiValued;
    }

    public function setMultiValued(bool $multiValued = true): self
    {
        $this->multiValued = $multiValued;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Convert a PHP value to the PHP type used for this column (see ColumnType).
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function normalizeValue($value)
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof Expression) {
            return $this->normalizeValue($value->evaluate());
        }
        if (ColumnType::ATTACHMENT === $this->type) {
            return $this->normalizeAttachments($value);
        }
        if ($this->multiValued) {
            $values = [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (null !== $item) {
                    $values[] = $this->normalizeScalar($item);
                }
            }

            return $values;
        }

        return $this->normalizeScalar($value);
    }

    /**
     * @param mixed $value
     *
     * @return array<int, Attachment>
     */
    private function normalizeAttachments($value): array
    {
        $attachments = [];
        foreach ($value instanceof Attachment || !is_array($value) || isset($value['name']) ? [$value] : $value as $item) {
            if ($item instanceof Attachment) {
                $attachments[] = $item;
            } elseif (is_array($item) && isset($item['name'])) {
                $attachments[] = new Attachment((string) $item['name'], (string) ($item['data'] ?? ''), isset($item['type']) ? (string) $item['type'] : null);
            } else {
                throw new InvalidArgumentException(sprintf('Invalid attachment for the column "%s"', $this->name));
            }
        }

        return $attachments;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function normalizeScalar($value)
    {
        switch ($this->type) {
            case ColumnType::BOOLEAN:
                if (is_string($value)) {
                    return in_array(strtolower(trim($value)), ['1', 'true', 't', 'yes', 'y', 'on', '-1'], true);
                }

                return (bool) $value;
            case ColumnType::TINYINT:
            case ColumnType::SMALLINT:
            case ColumnType::INTEGER:
            case ColumnType::BIGINT:
                if (is_bool($value)) {
                    return (int) $value;
                }
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException(sprintf('Invalid integer value for the column "%s"', $this->name));
                }

                return (int) $value;
            case ColumnType::FLOAT:
            case ColumnType::DOUBLE:
                if (!is_numeric($value) && !is_bool($value)) {
                    throw new InvalidArgumentException(sprintf('Invalid numeric value for the column "%s"', $this->name));
                }

                return (float) $value;
            case ColumnType::DECIMAL:
                if (is_float($value)) {
                    return number_format($value, $this->scale, '.', '');
                }
                if (!is_numeric($value) && !is_bool($value)) {
                    throw new InvalidArgumentException(sprintf('Invalid decimal value for the column "%s"', $this->name));
                }

                return self::formatDecimal((string) (is_bool($value) ? (int) $value : $value), $this->scale);
            case ColumnType::DATE:
            case ColumnType::TIME:
            case ColumnType::DATETIME:
                if ($value instanceof DateTimeImmutable) {
                    return $value;
                }
                if ($value instanceof DateTimeInterface) {
                    return DateTimeImmutable::createFromInterface($value);
                }
                if (is_int($value)) {
                    return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone(date_default_timezone_get()));
                }

                return new DateTimeImmutable((string) $value);
            case ColumnType::GUID:
                $hex = is_string($value) ? (string) preg_replace('/[{}\-\s]/', '', $value) : '';
                if (1 !== preg_match('/^[0-9a-fA-F]{32}$/', $hex)) {
                    if (is_string($value) && 16 === strlen($value)) {
                        // Binary GUID (as stored by Access: first three groups little-endian)
                        $hex = bin2hex(strrev(substr($value, 0, 4)) . strrev(substr($value, 4, 2)) . strrev(substr($value, 6, 2)) . substr($value, 8));
                    } else {
                        throw new InvalidArgumentException(sprintf('Invalid GUID for the column "%s"', $this->name));
                    }
                }
                $hex = strtoupper($hex);

                return '{' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20) . '}';
            default:
                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }
                if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                    return (string) $value;
                }

                throw new InvalidArgumentException(sprintf('Invalid value for the column "%s"', $this->name));
        }
    }

    /**
     * Format a numeric string with a fixed number of decimals, without going through floats.
     */
    public static function formatDecimal(string $value, int $scale): string
    {
        $value = trim($value);
        if (1 === preg_match('/[eE]/', $value)) {
            $value = number_format((float) $value, $scale, '.', '');
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        if ('' === $integer) {
            $integer = '0';
        }
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $result = $integer . ($scale > 0 ? '.' . $fraction : '');
        if ($negative && '' !== trim($result, '0.')) {
            $result = '-' . $result;
        }

        return $result;
    }
}
