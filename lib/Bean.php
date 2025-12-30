<?php
/**
 * Bean - RedBeanPHP Wrapper
 *
 * Normalizes bean type names to work around RedBeanPHP's strict naming requirements.
 * R::dispense() requires all lowercase, no underscores. This wrapper accepts:
 * - camelCase: 'enterpriseSettings' -> 'enterprisesettings'
 * - snake_case: 'enterprise_settings' -> 'enterprisesettings'
 * - Already lowercase: 'enterprisesettings' -> 'enterprisesettings'
 *
 * Usage:
 *   use \app\Bean;
 *
 *   $setting = Bean::findOne('enterpriseSettings', 'setting_key = ?', ['api_key']);
 *   if (!$setting) {
 *       $setting = Bean::dispense('enterpriseSettings');
 *       $setting->setting_key = 'api_key';
 *   }
 *   Bean::store($setting);
 */

namespace app;

use \RedBeanPHP\R as R;

class Bean {

    /**
     * Normalize bean type to lowercase, no underscores
     * This is required for R::dispense() to work
     *
     * @param string $type Bean type (camelCase, snake_case, or lowercase)
     * @return string Normalized lowercase bean type
     */
    public static function normalize(string $type): string {
        return strtolower(str_replace('_', '', $type));
    }

    /**
     * Create a new bean
     *
     * @param string $type Bean type
     * @return \RedBeanPHP\OODBBean
     */
    public static function dispense(string $type) {
        return R::dispense(self::normalize($type));
    }

    /**
     * Load a bean by ID
     *
     * @param string $type Bean type
     * @param int $id Bean ID
     * @return \RedBeanPHP\OODBBean
     */
    public static function load(string $type, int $id) {
        return R::load(self::normalize($type), $id);
    }

    /**
     * Find a single bean matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function findOne(string $type, ?string $sql = null, array $params = []) {
        // Convert null to empty string for PHP 8.1+ compatibility with RedBeanPHP
        return R::findOne(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Find all beans matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return array Array of beans
     */
    public static function find(string $type, ?string $sql = null, array $params = []) {
        // Convert null to empty string for PHP 8.1+ compatibility with RedBeanPHP
        return R::find(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Find all beans matching criteria (alias for find)
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return array Array of beans
     */
    public static function findAll(string $type, ?string $sql = null, array $params = []) {
        // Convert null to empty string for PHP 8.1+ compatibility with RedBeanPHP
        return R::findAll(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Count beans matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return int Count
     */
    public static function count(string $type, ?string $sql = null, array $params = []) {
        // Convert null to empty string for PHP 8.1+ compatibility with RedBeanPHP
        return R::count(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Store a bean
     *
     * @param \RedBeanPHP\OODBBean $bean Bean to store
     * @return int|string Bean ID
     */
    public static function store($bean) {
        return R::store($bean);
    }

    /**
     * Delete a bean
     *
     * @param \RedBeanPHP\OODBBean|string $beanOrType Bean or type name
     * @param int|null $id Optional ID if first param is type
     * @return void
     */
    public static function trash($beanOrType, ?int $id = null) {
        if (is_string($beanOrType) && $id !== null) {
            return R::trash(self::normalize($beanOrType), $id);
        }
        return R::trash($beanOrType);
    }

    /**
     * Delete multiple beans
     *
     * @param array $beans Array of beans to delete
     * @return void
     */
    public static function trashAll(array $beans) {
        return R::trashAll($beans);
    }

    /**
     * Execute raw SQL (use sparingly - only for DDL or complex operations)
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return int Affected rows
     */
    public static function exec(string $sql, array $params = []) {
        return R::exec($sql, $params);
    }

    /**
     * Get all rows as arrays (for complex SELECT with joins)
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array Array of arrays
     */
    public static function getAll(string $sql, array $params = []) {
        return R::getAll($sql, $params);
    }

    /**
     * Get a single cell value from a query
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return mixed Single value
     */
    public static function getCell(string $sql, array $params = []) {
        return R::getCell($sql, $params);
    }

    /**
     * Get a single column as an array
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array Column values
     */
    public static function getCol(string $sql, array $params = []) {
        return R::getCol($sql, $params);
    }

    /**
     * Get a single row as an array
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array|null Row data
     */
    public static function getRow(string $sql, array $params = []) {
        return R::getRow($sql, $params);
    }

    /**
     * Add a database connection
     *
     * @param string $key Database key
     * @param string $dsn DSN string
     * @param string|null $user Username
     * @param string|null $pass Password
     * @param bool $frozen Freeze mode
     */
    public static function addDatabase(string $key, string $dsn, ?string $user = null, ?string $pass = null, bool $frozen = false) {
        return R::addDatabase($key, $dsn, $user, $pass, $frozen);
    }

    /**
     * Select a database connection
     *
     * @param string $key Database key
     */
    public static function selectDatabase(string $key) {
        return R::selectDatabase($key);
    }

    /**
     * Set freeze mode
     *
     * @param bool $frozen Freeze mode
     */
    public static function freeze(bool $frozen = true) {
        return R::freeze($frozen);
    }

    /**
     * Begin a transaction
     */
    public static function begin() {
        return R::begin();
    }

    /**
     * Commit a transaction
     */
    public static function commit() {
        return R::commit();
    }

    /**
     * Rollback a transaction
     */
    public static function rollback() {
        return R::rollback();
    }

    /**
     * Get the database adapter
     *
     * @return \RedBeanPHP\Adapter\DBAdapter
     */
    public static function getDatabaseAdapter() {
        return R::getDatabaseAdapter();
    }
}
