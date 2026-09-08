<?php
namespace WPDSQL\Engine;

/** Buffered result contract. This deliberately implements only the required PDO-like operations. */
final class Result {
    private int $position = 0;
    public function __construct(
        private readonly array $rows,
        private readonly array $columns,
        private readonly int $affectedRows,
        public readonly string $operation,
        public readonly bool $command = false,
        public readonly int $insertId = 0,
        private readonly string $sourceSql = '',
        private readonly string $sourceMode = '',
    ) {}
    public function sourceQuery(): string {return $this->sourceSql;}
    public function sourceSqlMode(): string {return $this->sourceMode;}
    public function rowCount(): int { return $this->affectedRows; }
    public function columnCount(): int { return count($this->columns); }
    public function getColumnMeta(int $column): array|false { return $this->columns[$column] ?? false; }
    public function fetch(int $mode = \PDO::FETCH_ASSOC): array|object|false {
        if (!in_array($mode, [\PDO::FETCH_ASSOC, \PDO::FETCH_NUM, \PDO::FETCH_OBJ], true)) {
            throw new \InvalidArgumentException('Unsupported result fetch mode');
        }
        if (!isset($this->rows[$this->position])) { return false; }
        $row = $this->rows[$this->position++];
        return match ($mode) { \PDO::FETCH_NUM => array_values($row), \PDO::FETCH_OBJ => (object) $row, default => $row };
    }
    public function fetchAll(int $mode = \PDO::FETCH_ASSOC): array {
        $rows = [];
        while (($row = $this->fetch($mode)) !== false) { $rows[] = $row; }
        return $rows;
    }
    public function fetchColumn(int $column = 0): mixed {
        $row = $this->fetch(\PDO::FETCH_NUM);
        if ($row === false) { return false; }
        if (!array_key_exists($column, $row)) { throw new \ValueError('Invalid column index'); }
        return $row[$column];
    }
}
