<?php
/*
 * Copyright 2005-2014 the original author or authors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Mibew;

use Mibew\Database;
use Symfony\Component\Yaml\Parser as YamlParser;

/**
 * Encapsulates installation process.
 */
class Installer
{
    /**
     * Minimal PHP version Mibew works with.
     */
    const MIN_PHP_VERSION = 50303;

    /**
     * Installation process finished with error.
     */
    const STATE_ERROR = 'error';

    /**
     * Installation process finished successfully.
     */
    const STATE_SUCCESS = 'success';

    /**
     * Database tables should be created.
     */
    const STATE_NEED_CREATE_TABLES = 'need_create_tables';

    /**
     * Database tables should be updated.
     */
    const STATE_NEED_UPDATE_TABLES = 'need_update_tables';

    /**
     * Indicates that the main admin must change his password.
     */
    const STATE_NEED_CHANGE_PASSWORD = 'need_change_password';

    /**
     * Associative array of system configs.
     *
     * @var array
     */
    protected $configs = null;

    /**
     * List of errors.
     *
     * @var string[]
     */
    protected $errors = array();

    /**
     * List of log messages.
     *
     * @var string[]
     */
    protected $log = array();

    /**
     * An instance of YAML parser.
     *
     * @var Symfony\Component\Yaml\Parser
     */
    protected $parser = null;

    /**
     * Class constructor.
     *
     * @param array $system_configs Associative array of system configs.
     */
    public function __construct($system_configs)
    {
        $this->configs = $system_configs;
        $this->parser = new YamlParser();
    }

    /**
     * Retuns list of all errors that took place during installation process.
     *
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Returns list of all information messages.
     *
     * @return string[]
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * Install Mibew.
     *
     * @param string $real_base_path Real base path of the Mibew instance. For
     *   example if one tries to install Mibew to http://example.com/foo/mibew/
     *   the argument should be equal to "foo/mibew".
     * @return string Installation state. One of Installer::STATE_* constant.
     */
    public function install($real_base_path)
    {
        if (!$this->checkPhpVersion()) {
            return self::STATE_ERROR;
        }

        $this->log[] = getlocal(
            'PHP version {0}',
            array(format_version_id($this->getPhpVersionId()))
        );

        if (!$this->checkMibewRoot($real_base_path)) {
            return self::STATE_ERROR;
        }

        $this->log[] = getlocal(
            'Application path is {0}',
            array($real_base_path)
        );

        if (!$this->checkConnection()) {
            return self::STATE_ERROR;
        }

        if (!$this->checkMysqlVersion()) {
            return self::STATE_ERROR;
        }

        $this->log[] = getlocal(
            'You are connected to MySQL server version {0}',
            array($this->getMysqlVersion())
        );

        if (!$this->databaseExists()) {
            return self::STATE_NEED_CREATE_TABLES;
        }

        if ($this->databaseNeedUpdate()) {
            return self::STATE_NEED_UPDATE_TABLES;
        }

        $this->log[] = getlocal('Required tables are created.');
        $this->log[] = getlocal('Tables structure is up to date.');

        if (!$this->importLocales()) {
            return self::STATE_ERROR;
        }
        $this->log[] = getlocal('Locales are imported.');

        if (!$this->importLocalesContent()) {
            return self::STATE_ERROR;
        }
        $this->log[] = getlocal('Locales content is imported.');

        if ($this->needChangePassword()) {
            return self::STATE_NEED_CHANGE_PASSWORD;
        }

        return self::STATE_SUCCESS;
    }

    /**
     * Creates necessary tables.
     *
     * @return boolean Indicates if tables created or not. A list of all errors
     *   can be got using {@link \Mibew\Installer::getErrors()} method.
     */
    public function createTables()
    {
        if (!($db = $this->getDatabase())) {
            return false;
        }

        try {
            // Create tables according to database schema
            $schema = $this->getDatabaseSchema();
            foreach ($schema as $table => $table_structure) {
                $table_items = array();

                // Add fields
                foreach ($table_structure['fields'] as $field => $definition) {
                    $table_items[] = sprintf('%s %s', $field, $definition);
                }

                // Add indexes
                if (!empty($table_structure['indexes'])) {
                    foreach ($table_structure['indexes'] as $index => $fields) {
                        $table_items[] = sprintf(
                            'INDEX %s (%s)',
                            $index,
                            implode(', ', $fields)
                        );
                    }
                }

                // Add unique keys
                if (!empty($table_structure['unique_keys'])) {
                    foreach ($table_structure['unique_keys'] as $key => $fields) {
                        $table_items[] = sprintf(
                            'UNIQUE KEY %s (%s)',
                            $key,
                            implode(', ', $fields)
                        );
                    }
                }

                $db->query(sprintf(
                    'CREATE TABLE IF NOT EXISTS {%s} (%s) charset utf8 ENGINE=InnoDb',
                    $table,
                    implode(', ', $table_items)
                ));
            }
        } catch(\Exception $e) {
            $this->errors[] = getlocal(
                'Cannot create tables. Error: {0}',
                array($e->getMessage())
            );

            return false;
        }

        if (!$this->prepopulateDatabase()) {
            return false;
        }

        return true;
    }

    /**
     * Saves some necessary data in the database.
     *
     * This method is called just once after tables are created.
     *
     * @return boolean Indicates if the data are saved to the database or not.
     */
    protected function prepopulateDatabase()
    {
        if (!($db = $this->getDatabase())) {
            return false;
        }

        // Create The First Administrator if needed
        try {
            list($count) = $db->query(
                'SELECT COUNT(*) FROM {chatoperator} WHERE vclogin = :login',
                array(':login' => 'admin'),
                array(
                    'return_rows' => Database::RETURN_ONE_ROW,
                    'fetch_type' => Database::FETCH_NUM
                )
            );
            if ($count == 0) {
                $db->query(
                    ('INSERT INTO {chatoperator} ( '
                            . 'vclogin, vcpassword, vclocalename, vccommonname, '
                            . 'vcavatar, vcemail, iperm '
                        . ') values ( '
                            . ':login, :pass, :local_name, :common_name, '
                            . ':avatar, :email, :permissions)'),
                    array(
                        ':login' => 'admin',
                        ':pass' => md5(''),
                        ':local_name' => 'Administrator',
                        ':common_name' => 'Administrator',
                        ':avatar' => '',
                        ':email' => '',
                        ':permissions' => 65535,
                    )
                );
            }
        } catch(\Exception $e) {
            $this->errors[] = getlocal(
                'Cannot create the first administrator. Error {0}',
                array($e->getMessage())
            );

            return false;
        }

        // Initialize chat revision counter if it is needed
        try {
            list($count) = $db->query(
                'SELECT COUNT(*) FROM {chatrevision}',
                null,
                array(
                    'return_rows' => Database::RETURN_ONE_ROW,
                    'fetch_type' => Database::FETCH_NUM
                )
            );
            if ($count == 0) {
                $db->query(
                    'INSERT INTO {chatrevision} VALUES (:init_revision)',
                    array(':init_revision' => 1)
                );
            }
        } catch(\Exception $e) {
            $this->errors[] = getlocal(
                'Cannot initialize chat revision sequence. Error {0}',
                array($e->getMessage())
            );

            return false;
        }

        // Set correct database structure version if needed
        try {
            list($count) = $db->query(
                'SELECT COUNT(*) FROM {chatconfig} WHERE vckey = :key',
                array(':key' => 'dbversion'),
                array(
                    'return_rows' => Database::RETURN_ONE_ROW,
                    'fetch_type' => Database::FETCH_NUM
                )
            );
            if ($count == 0) {
                $db->query(
                    'INSERT INTO {chatconfig} (vckey, vcvalue) VALUES (:key, :value)',
                    array(
                        ':key' => 'dbversion',
                        ':value' => DB_VERSION,
                    )
                );
            }
        } catch(\Exception $e) {
            $this->errors[] = getlocal(
                'Cannot store database structure version. Error {0}',
                array($e->getMessage())
            );

            return false;
        }

        return true;
    }

    /**
     * Checks if $mibewroot param in system configs is correct or not.
     *
     * @param string $real_base_path Real base path of the Mibew instance.
     * @return boolean True if the $mibewroot param in config is correct and
     *   false otherwise.
     */
    protected function checkMibewRoot($real_base_path)
    {
        if ($real_base_path != MIBEW_WEB_ROOT) {
            $this->errors[] = getlocal(
                "Please, check file {0}<br/>Wrong value of \$mibewroot variable, should be \"{1}\"",
                array(
                    $real_base_path . "/libs/config.php",
                    $real_base_path
                )
            );

            return false;
        }

        return true;
    }

    /**
     * Checks database connection.
     *
     * @return boolean True if connection is established and false otherwise.
     */
    protected function checkConnection()
    {
        if (!$this->getDatabase()) {
            return false;
        }

        return true;
    }

    /**
     * Checks if PHP version is high enough to run Mibew.
     *
     * @return boolean True if PHP version is suitable and false otherwise.
     */
    protected function checkPhpVersion()
    {
        $current_version = $this->getPhpVersionId();

        if ($current_version < self::MIN_PHP_VERSION) {
            $this->errors[] = getlocal(
                "PHP version is {0}, but Mibew works with {1} and later versions.",
                array(
                    format_version_id($current_version),
                    format_version_id(self::MIN_PHP_VERSION)
                )
            );

            return false;
        }

        return true;
    }

    /**
     * Checks if MySQL version is high enough or not to run Mibew.
     *
     * @return boolean True if MySQL version is suitable and false otherwise.
     * @todo Add real version check.
     */
    protected function checkMysqlVersion()
    {
        // At the moment minimal MySQL version is unknown. One should find
        // it out and replace the following with a real check.
        return ($this->getMysqlVersion() !== false);
    }

    /**
     * Returns current PHP version ID.
     *
     * For example, for PHP 5.3.3 the number 50303 will be returned.
     *
     * @return integer Verison ID.
     */
    protected function getPhpVersionId()
    {
        // PHP_VERSION_ID is available as of PHP 5.2.7 so we need to use
        // workaround for lower versions.
        return defined('PHP_VERSION_ID') ? PHP_VERSION_ID : 0;
    }

    /**
     * Returns current MySQL server version.
     *
     * @return string|boolean Current MySQL version or boolean false it it
     *   cannot be determined.
     */
    protected function getMysqlVersion()
    {
        if (!($db = $this->getDatabase())) {
            return false;
        }

        try {
            $result = $db->query(
                "SELECT VERSION() as c",
                null,
                array('return_rows' => Database::RETURN_ONE_ROW)
            );
        } catch (\Exception $e) {
            return false;
        }

        return $result['c'];
    }

    /**
     * Gets version of existing database structure.
     *
     * If Mibew is not installed yet boolean false will be returned.
     *
     * @return int|boolean Database structure version or boolean false if the
     *   version cannot be determined.
     */
    protected function getDatabaseVersion()
    {
        if (!($db = $this->getDatabase())) {
            return false;
        }

        try {
            $result = $db->query(
                "SELECT vcvalue AS version FROM {chatconfig} WHERE vckey = :key LIMIT 1",
                array(':key' => 'dbversion'),
                array('return_rows' => Database::RETURN_ONE_ROW)
            );
        } catch (\Exception $e) {
            return false;
        }

        if (!$result) {
            // It seems that database structure version isn't stored in the
            // database.
            return 0;
        }

        return intval($result['version']);
    }

    /**
     * Checks if the database structure must be updated.
     *
     * @return boolean
     */
    protected function databaseNeedUpdate()
    {
        return ($this->getDatabaseVersion() < DB_VERSION);
    }

    /**
     * Checks if database structure is already created.
     *
     * @return boolean
     */
    protected function databaseExists()
    {
        return ($this->getDatabaseVersion() !== false);
    }

    /**
     * Check if the admin must change his password to a new one.
     *
     * @return boolean True if the password must be changed and false otherwise.
     */
    protected function needChangePassword()
    {
        if (!($db = $this->getDatabase())) {
            return false;
        }

        try {
            $admin = $db->query(
                'SELECT * FROM {chatoperator} WHERE vclogin = :login',
                array(':login' => 'admin'),
                array('return_rows' => Database::RETURN_ONE_ROW)
            );
        } catch (\Exception $e) {
            $this->errors[] = getlocal(
                'Cannot load the main administrator\'s data. Error: {0}',
                array($e->getMessage())
            );

            return false;
        }

        if (!$admin) {
            $this->errors[] = getlocal('The main administrator has disappeared '
                . 'from the database. Do not know how to continue');

            return false;
        }

        return ($admin['vcpassword'] == md5(''));
    }

    /**
     * Import all available locales to the database and enable each of them.
     *
     * @return boolean Indicates if the locales were imported correctly. True if
     *   everything is OK and false otherwise.
     */
    protected function importLocales()
    {
        if (!($db = $this->getDatabase())) {
            return false;
        }

        try {
            $rows = $db->query(
                'SELECT code FROM {locale}',
                null,
                array('return_rows' => Database::RETURN_ALL_ROWS)
            );
            $exist_locales = array();
            foreach ($rows as $row) {
                $exist_locales[] = $row['code'];
            }

            $fs_locales = discover_locales();
            foreach ($fs_locales as $locale) {
                if (in_array($locale, $exist_locales)) {
                    // Do not create locales twice.
                    continue;
                }

                $db->query(
                    'INSERT INTO {locale} (code, enabled) values (:code, :enabled)',
                    array(
                        ':code' => $locale,
                        // Mark the locale as disabled to indicate that it's
                        // content is not imported yet.
                        ':enabled' => 0,
                    )
                );
            }
        } catch (\Exception $e) {
            $this->errors[] = getlocal(
                'Cannot import locales. Error: {0}',
                array($e->getMessage())
            );

            return false;
        }

        return true;
    }

    /**
     * Import locales content, namely translations, canned messages and mail
     * templates.
     *
     * When the content will be imported the locale will be marked as enabled.
     * @return boolean True if all content was imported successfully and false
     *   otherwise.
     */
    protected function importLocalesContent()
    {
        if (!($db = $this->getDatabase())) {
            return false;
        }

        try {
            $locales = $db->query(
                'SELECT * FROM {locale} WHERE enabled = :enabled',
                array(':enabled' => 0),
                array('return_rows' => Database::RETURN_ALL_ROWS)
            );

            foreach ($locales as $locale_info) {
                $locale = $locale_info['code'];

                // Import localized messages
                import_messages(
                    $locale,
                    MIBEW_FS_ROOT . '/locales/' . $locale . '/translation.po',
                    true
                );

                // Import canned messages
                $canned_messages_file = MIBEW_FS_ROOT . '/locales/' . $locale
                    . '/canned_messages.yml';
                if (is_readable($canned_messages_file)) {
                    import_canned_messages($locale, $canned_messages_file);
                }

                // Import mail templates
                $mail_templates_file = MIBEW_FS_ROOT . '/locales/' . $locale
                    . '/mail_templates.yml';
                if (is_readable($mail_templates_file)) {
                    import_mail_templates($locale, $mail_templates_file);
                }

                // Mark the locale as "enabled" to indicate that all its content
                // is imported.
                $db->query(
                    'UPDATE {locale} SET enabled = :enabled WHERE code = :locale',
                    array(
                        ':locale' => $locale,
                        ':enabled' => 1,
                    )
                );
            }
        } catch (\Exception $e) {
            $this->errors[] = getlocal(
                'Cannot import locales content. Error: {0}',
                array($e->getMessage())
            );

            return false;
        }

        return true;
    }

    /**
     * Returns initialized database object.
     *
     * @return \Mibew\Database|boolean A database class instance or boolean
     *   false if something went wrong.
     */
    protected function getDatabase()
    {
        if (!Database::isInitialized()) {
            try {
                Database::initialize(
                    $this->configs['database']['host'],
                    $this->configs['database']['login'],
                    $this->configs['database']['pass'],
                    $this->configs['database']['use_persistent_connection'],
                    $this->configs['database']['db'],
                    $this->configs['database']['tables_prefix']
                );
            } catch(\PDOException $e) {
                $this->errors[] = getlocal(
                    "Could not connect. Please check server settings in config.php. Error: {0}",
                    array($e->getMessage())
                );

                return false;
            }
        }

        $db = Database::getInstance();
        $db->throwExeptions(true);

        return $db;
    }

    /**
     * Loads database schema.
     *
     * @return array Associative array of database schema. Each key of the array
     *   is a table name and each value is its description. Table array itself
     *   is an associative array with the following keys:
     *     - fields: An associative array, which keys are MySQL columns names
     *       and values are columns definitions.
     *     - unique_keys: An associative array. Each its value is a name of a
     *       table's unique key. Each value is an array with names of the
     *       columns the key is based on.
     *     - indexes: An associative array. Each its value is a name of a
     *       table's index. Each value is an array with names of the
     *       columns the index is based on.
     */
    protected function getDatabaseSchema()
    {
        return $this->parser->parse(file_get_contents(MIBEW_FS_ROOT . '/libs/database_schema.yml'));
    }

    /**
     * Loads available canned messages for specified locale.
     *
     * @param string $locale Locale code.
     * @return string[]|boolean List of canned messages boolean false if
     *   something went wrong.
     */
    protected function getCannedMessages($locale)
    {
        $file_path = MIBEW_FS_ROOT . '/locales/' . $locale . '/canned_messages.yml';
        if (!is_readable($file_path)) {
            return false;
        }
        $messages = $this->parser->parse(file_get_contents($file_path));

        return $messages ? $messages : false;
    }

    /**
     * Loads available mail templates for the specified locale.
     *
     * @param string $locale Locale code.
     * @return array|boolean List of mail template arrays or boolean false if
     *   something went wrong.
     */
    protected function getMailTemplates($locale)
    {
        $file_path = MIBEW_FS_ROOT . '/locales/' . $locale . '/mail_templates.yml';
        if (!is_readable($file_path)) {
            return false;
        }
        $templates = $this->parser->parse(file_get_contents($file_path));

        return $templates ? $templates : false;
    }
}
