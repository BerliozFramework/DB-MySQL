<?php
/**
 * This file is part of Berlioz framework.
 *
 * @license   https://opensource.org/licenses/MIT MIT License
 * @copyright 2017 Ronan GIRON
 * @author    Ronan GIRON <https://github.com/ElGigi>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code, to the root.
 */

namespace Berlioz\DB\MySQL;


use Berlioz\Core\App\AppAwareInterface;
use Berlioz\Core\App\AppAwareTrait;
use Berlioz\Core\OptionList;
use Psr\Log\LogLevel;

/**
 * MySQL DB Class.
 *
 * @method mixed errorCode() Fetch the SQLSTATE associated with the last operation on the database handle
 * @method array errorInfo() Fetch extended error information associated with the last operation on the database handle
 * @method int exec(string $statement) Execute an SQL statement and return the number of affected rows
 * @method mixed getAttribute(int $attribute) Retrieve a database connection attribute
 * @method bool inTransaction() Checks if inside a transaction
 * @method string lastInsertId(string $name = null) Returns the ID of the last inserted row or sequence value
 * @method \PDOStatement prepare(string $statement, array $driver_options = []) Prepares a statement for execution and
 *         returns a statement object
 * @method \PDOStatement query(string $statement, int $fetch_mode = \PDO::FETCH_COLUMN) Executes an SQL statement,
 *         returning a result set as a \PDOStatement object
 * @method string quote(string $string, int $parameter_type = \PDO::PARAM_STR) Quotes a string for use in a query
 * @method bool setAttribute(int $attribute, mixed $value) Set an attribute
 */
class DB implements AppAwareInterface
{
    use AppAwareTrait;
    /** @var int Number of queries */
    private static $nbQueries = 0;
    /** @var \Berlioz\Core\OptionList Options of database connection */
    private $options;
    /** @var \PDO PDO object */
    private $pdo = null;
    /** @var bool If a transaction started */
    private $transactionStarted = false;
    /** @var int Number of started transaction (in cascade) */
    private $iTransactionStarted = 0;

    /**
     * DB MySQL constructor.
     *
     * @option string $driver   Database driver (default: mysql)
     * @option string $port     Database port (default: 3306)
     * @option string $encoding Database encoding (default: mb_internal_encoding())
     * @option int    $timeout  Database timeout in seconds (default: 5)
     * @option string $host     Database host connection
     * @option string $dbname   Database default database name
     * @option string $username Database username connection
     * @option string $password Database password connection
     *
     * @param \Berlioz\Core\OptionList|array $options Database connection options
     *
     * @throws \Berlioz\Core\Exception\BerliozException If an error occurred during \PDO connection
     */
    public function __construct($options)
    {
        try {
            $this->options = new OptionList;
            $this->options->set('driver', 'mysql');
            $this->options->set('port', 3306);
            $this->options->set('encoding', mb_internal_encoding());
            $this->options->set('timeout', 5);
            $this->options->setOptions($options);

            // \PDO options
            $pdoOptions = [\PDO::ATTR_TIMEOUT => (int) $this->options->get('timeout')];

            // Creation of \PDO objects
            $this->transactionStarted = false;
            $this->pdo = new \PDO($this->getDSN(),
                                  (string) $this->options->get('username'),
                                  (string) $this->options->get('password'),
                                  $pdoOptions);

            // Log
            $this->log(sprintf('Connection to %s', $this->getDSN()));
        } catch (\Exception $e) {
            // Log
            $this->log(sprintf('Connection failed to %s', $this->getDSN()), LogLevel::CRITICAL);

            throw new DBException('Connection error');
        }
    }

    /**
     * DB MySQL destructor.
     */
    public function __destruct()
    {
        // Log
        $this->log(sprintf('Disconnection from %s', $this->getDSN()));
    }

    /**
     * Log data into App logging service
     *
     * @param string $message Message
     * @param string $level
     */
    private function log(string $message, string $level = LogLevel::INFO)
    {
        if ($this->hasApp()) {
            $this->getApp()->getService('logging')->log(sprintf('%s / %s', get_class($this), $message), $level);
        }
    }

    /**
     * Get DSN of database for \PDO connection.
     *
     * @return string DSN
     */
    private function getDSN()
    {
        $dsn = "{$this->options->get('driver')}:";

        if (!$this->options->is_empty('unix_socket')) {
            $dsn .= "unix_socket={$this->options->get('unix_socket')}";
        } else {
            $dsn .= "host={$this->options->get('host')};port={$this->options->get('port')}";
        }

        if (!$this->options->is_empty('dbname')) {
            $dsn .= ";dbname={$this->options->get('dbname')}";
        }

        if (!is_null($this->encodingToCharset())) {
            $dsn .= ";charset={$this->encodingToCharset()}";
        }

        return $dsn;
    }

    /**
     * Get \PDO object.
     *
     * @return \PDO
     */
    protected function getPDO()
    {
        return $this->pdo;
    }

    /**
     * Get default DB name.
     *
     * @return string
     */
    public function getDbName()
    {
        return (string) $this->options->get('dbname');
    }

    /**
     * Get encoding (default: mb_internal_encoding()).
     *
     * @return string
     */
    public function getEncoding()
    {
        return !$this->options->is_empty('encoding') ? (string) $this->options->get('encoding') : mb_internal_encoding();
    }

    /**
     * Get charset encoding string for SQL queries.
     *
     * @return string
     */
    protected function encodingToCharset()
    {
        switch (mb_strtolower($this->getEncoding())) {
            case 'cp1252':
            case 'iso-8859-1':
                return 'latin1';
            case 'iso-8859-2':
                return 'latin2';
            case 'iso-8859-5':
                return 'latin5';
            case 'iso-8859-7':
                return 'greek';
            case 'iso-8859-8':
                return 'hebrew';
            case 'iso-8859-13':
                return 'latin7';
            case 'utf-8':
                return 'utf8';
            case 'utf-16':
                return 'utf16';
            case 'utf-32':
                return 'utf32';
            default:
                return null;
        }
    }

    /**
     * __call() magic method.
     *
     * @param  string $name      Method name to call
     * @param  array  $arguments Arguments list
     *
     * @return mixed
     */
    public function __call($name, array $arguments)
    {
        // Return value
        $returnValue = call_user_func_array([&$this->pdo, $name], $arguments);

        // Log
        switch ($name) {
            case 'exec':
            case 'prepare':
            case 'query':
                $this->log(sprintf('%s "%s"', ucwords($name), $arguments[0] ?? 'N/A'), LogLevel::INFO);
        }

        return $returnValue;
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction()
    {
        if (false === $this->transactionStarted) {
            $this->transactionStarted = true;
            $this->__call("beginTransaction", func_get_args());
        }

        $this->iTransactionStarted++;
    }

    /**
     * Commit a transaction.
     */
    public function commit()
    {
        $this->iTransactionStarted--;

        if (true === $this->transactionStarted && 0 == $this->iTransactionStarted) {
            $this->transactionStarted = false;
            $this->__call("commit", func_get_args());
        }
    }

    /**
     * Rollback a transaction.
     */
    public function rollBack()
    {
        if (true === $this->transactionStarted) {
            $this->transactionStarted = false;
            $this->__call("rollback", func_get_args());
        }

        $this->iTransactionStarted = 0;
    }

    /**
     * Get next auto increment of a table.
     *
     * @param  string      $table    Table name
     * @param  string|null $database Database name (if empty: defaut database name)
     *
     * @return int
     * @throws \Berlioz\DB\MySQL\DBException If DB result is an error
     */
    public function nextAutoIncrement($table, $database = null)
    {
        $query = "SHOW TABLE STATUS FROM `" . (is_null($database) ? $this->getDbName() : $database) . "` LIKE '" . escapeshellcmd($table) . "';";

        if (($result = $this->query($query)) !== false) {
            $row = $result->fetch(\PDO::FETCH_ASSOC);

            return $row["Auto_increment"];
        } else {
            throw new DBException();
        }
    }

    /**
     * Protect data to pass to queries.
     *
     * @param  string $text       String to protect
     * @param  bool   $forceQuote Force quote (default: false)
     * @param  bool   $strip_tags Strip tags (default: true)
     *
     * @return string
     * @throws \Berlioz\DB\MySQL\DBException If an error occurred during protection
     */
    public function protectData($text, $forceQuote = false, $strip_tags = true)
    {
        try {
            if (null === $text) {
                $text = 'NULL';
            } else {
                if (get_magic_quotes_gpc()) {
                    $text = stripslashes($text);
                }

                // Protect if it's not an integer
                if (!is_numeric($text) || true === $forceQuote) {
                    // Strip tags
                    if (true === $strip_tags) {
                        $text = strip_tags((string) $text);
                    }

                    // Convert encoding
                    $text = $this->convertCharacterEncoding($text);

                    // Add charset on string value
                    if (($quote = $this->quote((string) $text)) !== false) {
                        $charset = $this->encodingToCharset();
                        $text = (!is_null($charset) ? '_' . $charset : '') . (string) $quote;
                    } else {
                        throw new DBException(sprintf('Unable to protect data "%s" for MySQL', (string) $text));
                    }
                }
            }

            return !empty($text) || is_numeric($text) ? $text : "''";
        } catch (DBException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DBException;
        }
    }

    /**
     * Convert charset encoding to pass to queries.
     *
     * @param  string $content
     *
     * @return string
     */
    private function convertCharacterEncoding($content)
    {
        if (is_string($content) && !empty($content)) {
            $encoding = mb_detect_encoding($content, mb_detect_order(), true);
            $content = @mb_convert_encoding($content, $this->getEncoding(), (false === $encoding ? 'ASCII' : $encoding));

            if ($this->getEncoding() == 'UTF-8') {
                $content = str_replace(chr(0xC2) . chr(0x80), chr(0xE2) . chr(0x82) . chr(0xAC), $content);
            }
        }

        return $content;
    }

    /**
     * Get number of queries.
     *
     * @return int
     */
    public static function getNbQueries()
    {
        return self::$nbQueries;
    }
}
