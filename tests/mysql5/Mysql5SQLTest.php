<?php
/**
 * DBSteward unit test for mysql5 ddl generation
 *
 * @package DBSteward
 * @license http://www.opensource.org/licenses/bsd-license.php Simplified BSD License
 * @author Austin Hyde <austin109@gmail.com>
 */

require_once 'PHPUnit/Framework/TestCase.php';
require_once 'PHPUnit/Framework/TestSuite.php';

require_once __DIR__ . '/../../lib/DBSteward/dbsteward.php';
require_once __DIR__ . '/../mock_output_file_segmenter.php';

/**
 * @group mysql5
 */
class Mysql5SQLTest extends PHPUnit_Framework_TestCase {

  public function setUp() {
    dbsteward::set_sql_format('mysql5');
    dbsteward::$quote_schema_names = TRUE;
    dbsteward::$quote_table_names = TRUE;
    dbsteward::$quote_column_names = TRUE;
    dbsteward::$quote_function_names = TRUE;
    dbsteward::$quote_object_names = TRUE;
    mysql5::$swap_function_delimiters = FALSE;
    mysql5::$use_auto_increment_table_options = FALSE;
    mysql5::$use_schema_name_prefix = TRUE;
  }

  public function testBuildSchema() {
    $xml = <<<XML
<dbsteward>
  <database>
    <host>db-host</host>
    <name>dbsteward</name>
    <role>
      <application>dbsteward_phpunit_app</application>
      <owner>deployment</owner>
      <replication/>
      <readonly/>
    </role>
    <!-- should be ignored -->
    <slony>
      <masterNode id="1"/>
      <replicaNode id="2" providerId="1"/>
      <replicaNode id="3" providerId="2"/>
      <replicationSet id="1"/>
      <replicationUpgradeSet id="2"/>
    </slony>
    <!-- should be ignored -->
    <configurationParameter name="TIME ZONE" value="America/New_York"/>
  </database>
  <!-- should be ignored -->
  <language name="plpgsql" procedural="true" owner="ROLE_OWNER"/>

  <schema name="public" owner="ROLE_OWNER">
    <grant operation="SELECT,UPDATE,DELETE" role="ROLE_OWNER"/>

    <type type="enum" name="permission_level">
      <enum name="guest"/>
      <enum name="user"/>
      <enum name="admin"/>
    </type>

    <function name="a_function" returns="text" owner="ROLE_OWNER" cachePolicy="VOLATILE" description="a test function">
      <functionParameter name="config_parameter" type="text"/>
      <functionParameter name="config_value" type="text"/>
      <!-- should be ignored -->
      <functionDefinition language="plpgsql" sqlFormat="pgsql8">
        DECLARE
          q text;
          name text;
          n text;
        BEGIN
          SELECT INTO name current_database();
          q := 'ALTER DATABASE ' || name || ' SET ' || config_parameter || ' ''' || config_value || ''';';
          n := 'DB CONFIG CHANGE: ' || q;
          RAISE NOTICE '%', n;
          EXECUTE q;
          RETURN n;
        END;
      </functionDefinition>
      <functionDefinition language="sql" sqlFormat="mysql5">
        BEGIN
          RETURN config_parameter;
        END
      </functionDefinition>
      <grant operation="EXECUTE" role="ROLE_APPLICATION"/>
    </function>

    <table name="user" owner="ROLE_OWNER" primaryKey="user_id" slonyId="1">
      <column name="user_id" type="int auto_increment" null="false"/>
      <column name="group_id" foreignSchema="public" foreignTable="group" foreignColumn="group_id" null="false"/>
      <column name="username" type="varchar(80)"/>
      <column name="user_age" type="numeric"/>
      <constraint name="username_unq" type="Unique" definition="(`username`)"/>
      <grant operation="SELECT,UPDATE,DELETE" role="ROLE_APPLICATION"/>
    </table>

    <table name="group" owner="ROLE_OWNER" primaryKey="group_id" slonyId="2">
      <column name="group_id" type="int auto_increment" null="false"/>
      <column name="permission_level" type="permission_level"/> <!-- enum type -->
      <column name="group_name" type="character varying(100)" unique="true"/>
      <column name="group_enabled" type="boolean" null="false" default="true"/>
      <grant operation="SELECT,UPDATE,DELETE" role="ROLE_APPLICATION"/>
    </table>

    <sequence name="a_sequence" owner="ROLE_OWNER">
      <grant operation="SELECT,UPDATE,DELETE" role="ROLE_APPLICATION"/>
    </sequence>

    <trigger name="a_trigger" sqlFormat="mysql5" table="user" when="before" event="insert" function="EXECUTE xyz"/>

    <!-- should be ignored -->
    <trigger name="a_trigger" sqlFormat="pgsql8" table="group" when="before" event="delete" function="EXECUTE xyz;"/>

    <view name="a_view" owner="ROLE_OWNER" description="Description goes here">
      <viewQuery sqlFormat="mysql5">SELECT * FROM user, group</viewQuery>
      <!-- should be ignored -->
      <viewQuery sqlFormat="pgsql8">SELECT * FROM pgsql8table</viewQuery>
      <grant operation="SELECT,UPDATE,DELETE" role="ROLE_APPLICATION"/>
    </view>
  </schema>

  <schema name="hotel" owner="ROLE_OWNER">
    <table name="rate" owner="ROLE_OWNER" primaryKey="rate_id" slonyId="1">
      <column name="rate_id" type="integer" null="false"/>
      <column name="rate_group_id" foreignSchema="hotel" foreignTable="rate_group" foreignColumn="rate_group_id" null="false"/>
      <column name="rate_name" type="character varying(120)"/>
      <column name="rate_value" type="numeric"/>
    </table>
    <table name="rate_group" owner="ROLE_OWNER" primaryKey="rate_group_id" slonyId="2">
      <column name="rate_group_id" type="integer" null="false"/>
      <column name="rate_group_name" type="character varying(100)"/>
      <column name="rate_group_enabled" type="boolean" null="false" default="true"/>
    </table>
  </schema>
</dbsteward>
XML;

    $expected = <<<SQL
GRANT SELECT, UPDATE, DELETE ON * TO `deployment`;

DROP FUNCTION IF EXISTS `public_a_function`;
CREATE DEFINER = deployment FUNCTION `public_a_function` (`config_parameter` text, `config_value` text)
RETURNS text
LANGUAGE SQL
MODIFIES SQL DATA
NOT DETERMINISTIC
SQL SECURITY INVOKER
COMMENT 'a test function'
BEGIN
  RETURN config_parameter;
END;

GRANT EXECUTE ON FUNCTION `public_a_function` TO `dbsteward_phpunit_app`;

CREATE TABLE `public_user` (
  `user_id` int NOT NULL,
  `group_id` int NOT NULL,
  `username` varchar(80),
  `user_age` numeric
);

ALTER TABLE `public_user`
  ADD INDEX `group_id` (`group_id`) USING BTREE;

GRANT SELECT, UPDATE, DELETE ON `public_user` TO `dbsteward_phpunit_app`;

CREATE TABLE `public_group` (
  `group_id` int NOT NULL,
  `permission_level` ENUM('guest','user','admin'),
  `group_name` character varying(100),
  `group_enabled` boolean NOT NULL DEFAULT true
);

ALTER TABLE `public_group`
  ADD UNIQUE INDEX `group_name` (`group_name`) USING BTREE;

GRANT SELECT, UPDATE, DELETE ON `public_group` TO `dbsteward_phpunit_app`;

CREATE TABLE IF NOT EXISTS `__sequences` (
  `name` VARCHAR(100) NOT NULL,
  `increment` INT(11) unsigned NOT NULL DEFAULT 1,
  `min_value` INT(11) unsigned NOT NULL DEFAULT 1,
  `max_value` BIGINT(20) unsigned NOT NULL DEFAULT 18446744073709551615,
  `cur_value` BIGINT(20) unsigned DEFAULT 1,
  `start_value` BIGINT(20) unsigned DEFAULT 1,
  `cycle` BOOLEAN NOT NULL DEFAULT FALSE,
  `should_advance` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`name`)
) ENGINE = MyISAM;

DROP FUNCTION IF EXISTS `currval`;
CREATE FUNCTION `currval` (`seq_name` varchar(100))
RETURNS BIGINT(20) NOT DETERMINISTIC
BEGIN
  DECLARE val BIGINT(20);
  IF @__sequences_lastval IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nextval() has not been called yet this session';
  ELSE
    SELECT `currval` INTO val FROM  `__sequences_currvals` WHERE `name` = seq_name;
    RETURN val;
  END IF;
END;

DROP FUNCTION IF EXISTS `lastval`;
CREATE FUNCTION `lastval` ()
RETURNS BIGINT(20) NOT DETERMINISTIC
BEGIN
  IF @__sequences_lastval IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nextval() has not been called yet this session';
  ELSE
    RETURN @__sequences_lastval;
  END IF;
END;

DROP FUNCTION IF EXISTS `nextval`;
CREATE FUNCTION `nextval` (`seq_name` varchar(100))
RETURNS BIGINT(20) NOT DETERMINISTIC
BEGIN
  DECLARE advance BOOLEAN;

  CREATE TEMPORARY TABLE IF NOT EXISTS `__sequences_currvals` (
    `name` VARCHAR(100) NOT NULL,
    `currval` BIGINT(20),
    PRIMARY KEY (`name`)
  );

  SELECT `cur_value` INTO @__sequences_lastval FROM `__sequences` WHERE `name` = seq_name;
  SELECT `should_advance` INTO advance FROM `__sequences` WHERE `name` = seq_name;

  IF @__sequences_lastval IS NOT NULL THEN

    IF advance = TRUE THEN
      UPDATE `__sequences`
      SET `cur_value` = IF (
        (`cur_value` + `increment`) > `max_value`,
        IF (`cycle` = TRUE, `min_value`, NULL),
        `cur_value` + `increment`
      )
      WHERE `name` = seq_name;

      SELECT `cur_value` INTO @__sequences_lastval FROM `__sequences` WHERE `name` = seq_name;

    ELSE
      UPDATE `__sequences`
      SET `should_advance` = TRUE
      WHERE `name` = seq_name;
    END IF;

    REPLACE INTO `__sequences_currvals` (`name`, `currval`)
    VALUE (seq_name, @__sequences_lastval);
  END IF;

  RETURN @__sequences_lastval;
END;

DROP FUNCTION IF EXISTS `setval`;
CREATE FUNCTION `setval` (`seq_name` varchar(100), `value` bigint(20), `advance` BOOLEAN)
RETURNS bigint(20) NOT DETERMINISTIC
BEGIN

  UPDATE `__sequences`
  SET `cur_value` = value,
      `should_advance` = advance
  WHERE `name` = seq_name;

  IF advance = FALSE THEN
    CREATE TEMPORARY TABLE IF NOT EXISTS `__sequences_currvals` (
      `name` VARCHAR(100) NOT NULL,
      `currval` BIGINT(20),
      PRIMARY KEY (`name`)
    );

    REPLACE INTO `__sequences_currvals` (`name`, `currval`)
    VALUE (seq_name, value);
    SET @__sequences_lastval = value;
  END IF;

  RETURN value;
END;

INSERT INTO `__sequences`
  (`name`, `increment`, `min_value`, `max_value`, `cur_value`, `start_value`, `cycle`)
VALUES
  ('a_sequence', DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT, DEFAULT);

GRANT SELECT, UPDATE, DELETE ON `__sequences` TO `dbsteward_phpunit_app`;

DROP TRIGGER IF EXISTS `public_a_trigger`;
CREATE TRIGGER `public_a_trigger` BEFORE INSERT ON `public_user`
FOR EACH ROW EXECUTE xyz;

CREATE TABLE `hotel_rate` (
  `rate_id` integer NOT NULL,
  `rate_group_id` integer NOT NULL,
  `rate_name` character varying(120),
  `rate_value` numeric
);

ALTER TABLE `hotel_rate`
  ADD INDEX `rate_group_id` (`rate_group_id`) USING BTREE;

CREATE TABLE `hotel_rate_group` (
  `rate_group_id` integer NOT NULL,
  `rate_group_name` character varying(100),
  `rate_group_enabled` boolean NOT NULL DEFAULT true
);

ALTER TABLE `public_user`
  ADD PRIMARY KEY (`user_id`),
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `public_group`
  ADD PRIMARY KEY (`group_id`),
  MODIFY `group_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `hotel_rate`
  ADD PRIMARY KEY (`rate_id`);
ALTER TABLE `hotel_rate_group`
  ADD PRIMARY KEY (`rate_group_id`);

ALTER TABLE `public_user`
  ADD UNIQUE INDEX `username_unq` (`username`),
  ADD CONSTRAINT `user_group_id_fkey` FOREIGN KEY `user_group_id_fkey` (`group_id`) REFERENCES `public_group` (`group_id`);

ALTER TABLE `hotel_rate`
  ADD CONSTRAINT `rate_rate_group_id_fkey` FOREIGN KEY `rate_rate_group_id_fkey` (`rate_group_id`) REFERENCES `hotel_rate_group` (`rate_group_id`);

CREATE OR REPLACE DEFINER = deployment SQL SECURITY DEFINER VIEW `public_a_view`
AS SELECT * FROM user, group;
SQL;

    $dbs = new SimpleXMLElement($xml);
    $ofs = new mock_output_file_segmenter();

    dbsteward::$new_database = $dbs;
    $table_dependency = xml_parser::table_dependency_order($dbs);

    mysql5::build_schema($dbs, $ofs, $table_dependency);

    $actual = $ofs->_get_output();
// var_dump($actual);    
    // get rid of comments
    // $expected = preg_replace('/\s*-- .*(\n\s*)?/','',$expected);
    // // get rid of extra whitespace
    // $expected = trim(preg_replace("/\n\n/","\n",$expected));
    $expected = preg_replace("/^ +/m","",$expected);
    $expected = trim(preg_replace("/\n+/","\n",$expected));

    // echo $actual;

    // get rid of comments
    $actual = preg_replace("/\s*-- .*$/m",'',$actual);
    // get rid of extra whitespace
    // $actual = trim(preg_replace("/\n\n+/","\n",$actual));
    $actual = preg_replace("/^ +/m","",$actual);
    $actual = trim(preg_replace("/\n+/","\n",$actual));

    $this->assertEquals($expected, $actual);
  }
}
?>
