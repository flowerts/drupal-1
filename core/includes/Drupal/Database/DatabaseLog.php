<?php

namespace Drupal\Database;

/**
 * Database query logger.
 *
 * We log queries in a separate object rather than in the connection object
 * because we want to be able to see all queries sent to a given database, not
 * database target. If we logged the queries in each connection object we
 * would not be able to track what queries went to which target.
 *
 * Every connection has one and only one logging object on it for all targets
 * and logging keys.
 */
class DatabaseLog {

  /**
   * Cache of logged queries. This will only be used if the query logger is enabled.
   *
   * The structure for the logging array is as follows:
   *
   * array(
   *   $logging_key = array(
   *     array(query => '', args => array(), caller => '', target => '', time => 0),
   *     array(query => '', args => array(), caller => '', target => '', time => 0),
   *   ),
   * );
   *
   * @var array
   */
  protected $queryLog = array();

  /**
   * The connection key for which this object is logging.
   *
   * @var string
   */
  protected $connectionKey = 'default';

  /**
   * Constructor.
   *
   * @param $key
   *   The database connection key for which to enable logging.
   */
  public function __construct($key = 'default') {
    $this->connectionKey = $key;
  }

  /**
   * Begin logging queries to the specified connection and logging key.
   *
   * If the specified logging key is already running this method does nothing.
   *
   * @param $logging_key
   *   The identification key for this log request. By specifying different
   *   logging keys we are able to start and stop multiple logging runs
   *   simultaneously without them colliding.
   */
  public function start($logging_key) {
    if (empty($this->queryLog[$logging_key])) {
      $this->clear($logging_key);
    }
  }

  /**
   * Retrieve the query log for the specified logging key so far.
   *
   * @param $logging_key
   *   The logging key to fetch.
   * @return
   *   An indexed array of all query records for this logging key.
   */
  public function get($logging_key) {
    return $this->queryLog[$logging_key];
  }

  /**
   * Empty the query log for the specified logging key.
   *
   * This method does not stop logging, it simply clears the log. To stop
   * logging, use the end() method.
   *
   * @param $logging_key
   *   The logging key to empty.
   */
  public function clear($logging_key) {
    $this->queryLog[$logging_key] = array();
  }

  /**
   * Stop logging for the specified logging key.
   *
   * @param $logging_key
   *   The logging key to stop.
   */
  public function end($logging_key) {
    unset($this->queryLog[$logging_key]);
  }

  /**
   * Log a query to all active logging keys.
   *
   * @param $statement
   *   The prepared statement object to log.
   * @param $args
   *   The arguments passed to the statement object.
   * @param $time
   *   The time in milliseconds the query took to execute.
   */
  public function log(DatabaseStatementInterface $statement, $args, $time) {
    foreach (array_keys($this->queryLog) as $key) {
      $this->queryLog[$key][] = array(
        'query' => $statement->getQueryString(),
        'args' => $args,
        'target' => $statement->dbh->getTarget(),
        'caller' => $this->findCaller(),
        'time' => $time,
      );
    }
  }

  /**
   * Determine the routine that called this query.
   *
   * We define "the routine that called this query" as the first entry in
   * the call stack that is not inside the includes/Drupal/Database directory
   * and does not begin with db_. That makes the climbing logic very simple, and
   * handles the variable stack depth caused by the query builders.
   *
   * @todo Revisit this logic to not be dependent on file path, so that we can
   *       split most of the DB layer out of Drupal.
   *
   * @link http://www.php.net/debug_backtrace
   * @return
   *   This method returns a stack trace entry similar to that generated by
   *   debug_backtrace(). However, it flattens the trace entry and the trace
   *   entry before it so that we get the function and args of the function that
   *   called into the database system, not the function and args of the
   *   database call itself.
   */
  public function findCaller() {
    $stack = debug_backtrace();
    $stack_count = count($stack);
    $blacklist_fragment = 'includes' . DIRECTORY_SEPARATOR . 'Drupal' . DIRECTORY_SEPARATOR . 'Database';

    for ($i = 0; $i < $stack_count; ++$i) {
      if (strpos($stack[$i]['file'], $blacklist_fragment) === FALSE && strpos($stack[$i + 1]['function'], 'db_') === FALSE) {
        return array(
          'file' => $stack[$i]['file'],
          'line' => $stack[$i]['line'],
          'function' => $stack[$i + 1]['function'],
          'class' => isset($stack[$i + 1]['class']) ? $stack[$i + 1]['class'] : NULL,
          'type' => isset($stack[$i + 1]['type']) ? $stack[$i + 1]['type'] : NULL,
          'args' => $stack[$i + 1]['args'],
        );
      }
    }
  }
}
