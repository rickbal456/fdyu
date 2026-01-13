<?php
/**
 * AIKAFLOW Database Connection
 * 
 * PDO-based database connection with connection pooling support.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Database connection singleton
 */
class Database
{
    private static ?PDO $instance = null;
    private static array $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
    ];
    
    /**
     * Get database connection instance
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::connect();
        }
        
        return self::$instance;
    }
    
    /**
     * Establish database connection
     */
    private static function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        
        try {
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, self::$options);
        } catch (PDOException $e) {
            self::logError('Database connection failed: ' . $e->getMessage());
            
            if (APP_DEBUG) {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage());
            }
            
            throw new RuntimeException('Database connection failed. Please try again later.');
        }
    }
    
    /**
     * Close database connection
     */
    public static function close(): void
    {
        self::$instance = null;
    }
    
    /**
     * Execute a query and return PDOStatement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $pdo = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Fetch a single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Fetch a single column value
     */
    public static function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetchColumn($column);
    }
    
    /**
     * Insert a row and return last insert ID
     */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":$col", $columns);
        
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::escapeIdentifier($table),
            implode(', ', array_map([self::class, 'escapeIdentifier'], $columns)),
            implode(', ', $placeholders)
        );
        
        self::query($sql, $data);
        return (int) self::getInstance()->lastInsertId();
    }
    
    /**
     * Update rows and return affected count
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setParts = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            $paramName = "set_$column";
            $setParts[] = self::escapeIdentifier($column) . " = :$paramName";
            $params[$paramName] = $value;
        }
        
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::escapeIdentifier($table),
            implode(', ', $setParts),
            $where
        );
        
        $stmt = self::query($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }
    
    /**
     * Delete rows and return affected count
     */
    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            self::escapeIdentifier($table),
            $where
        );
        
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getInstance()->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public static function commit(): bool
    {
        return self::getInstance()->commit();
    }
    
    /**
     * Rollback transaction
     */
    public static function rollback(): bool
    {
        return self::getInstance()->rollBack();
    }
    
    /**
     * Execute callback within transaction
     */
    public static function transaction(callable $callback): mixed
    {
        self::beginTransaction();
        
        try {
            $result = $callback(self::getInstance());
            self::commit();
            return $result;
        } catch (Throwable $e) {
            self::rollback();
            throw $e;
        }
    }
    
    /**
     * Check if table exists
     */
    public static function tableExists(string $table): bool
    {
        $result = self::fetchOne(
            "SELECT COUNT(*) as count FROM information_schema.tables 
             WHERE table_schema = :db AND table_name = :table",
            ['db' => DB_NAME, 'table' => $table]
        );
        
        return ($result['count'] ?? 0) > 0;
    }
    
    /**
     * Escape identifier (table/column name)
     */
    public static function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
    
    /**
     * Log database errors
     */
    private static function logError(string $message): void
    {
        $logFile = LOGS_PATH . '/database.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        
        error_log($logMessage, 3, $logFile);
    }
}

/**
 * Shortcut function to get database instance
 */
function db(): PDO
{
    return Database::getInstance();
}