<?php
/**
 *
 *
 * @package DBSteward
 * @license http://www.opensource.org/licenses/bsd-license.php Simplified BSD License
 * @author Rusty Hamilton <rusty@shrub3.net>
 */

require_once 'PHPUnit/Framework/TestCase.php';

require_once __DIR__ . '/../dbstewardUnitTestBase.php';
require_once(dirname(__FILE__) . '/../../lib/DBSteward/sql_format/sql99/sql99.php');
require_once(dirname(__FILE__) . '/../../lib/DBSteward/sql_format/pgsql8/pgsql8.php');

/**
 * @group pgsql8
 */
class IsolatedSequenceTest extends dbstewardUnitTestBase {

  protected $xml_content_a =  <<<XML
<dbsteward>
  <database>
    <role>
      <application>deployment</application>
      <owner>deployment</owner>
      <replication/>
      <readonly/>
    </role>
  </database>
  <schema name="public" owner="ROLE_OWNER">
    <sequence name="test_seq" start="1" inc="1" max="15" cycle="false" cache="1" owner="ROLE_OWNER">
      <grant operation="USAGE,SELECT,UPDATE" role="ROLE_APPLICATION"/>
    </sequence>
    <table name="user" owner="ROLE_OWNER" primaryKey="user_id" description="user logins">
      <column name="user_id" type="int" default="nextval('test_seq')"/>
      <column name="user_name" type="varchar(100)" null="false"/>
      <column name="user_role" type="varchar(100)" null="false"/>
      <column name="user_create_date" type="timestamp with time zone" null="false" default="NOW()"/>
      <grant role="ROLE_APPLICATION" operation="SELECT, INSERT, UPDATE"/>
      <rows columns="user_id, user_name, user_role">
        <tabrow>1	toor	super_admin</tabrow>
      </rows>
    </table>
  </schema>
  <schema name="testschema" owner="ROLE_OWNER">
    <table name="testtable" owner="ROLE_OWNER" primaryKey="idcol" description="test">
      <column name="idcol" type="bigint"/>
      <column name="testtext" type="text" null="false"/>
      <grant role="ROLE_APPLICATION" operation="SELECT, INSERT"/>
    </table>
  </schema>
</dbsteward>
XML;

  public function setUp() {
    $this->output_prefix = dirname(__FILE__) . '/../testdata/unit_test_xml_a';
    parent::setUp();

    $this->pgsql8->create_db();

    $host = $this->pgsql8->get_dbhost();
    $port = $this->pgsql8->get_dbport();
    $database = $this->pgsql8->get_dbname();
    $user = $this->pgsql8->get_dbuser();
    $password = $this->pgsql8->get_dbpass();

    pgsql8_db::connect("host=$host port=$port dbname=$database user=$user password=$password");
  }

  public function tearDown() {
    pgsql8_db::disconnect();
  }

  protected function query_db($sql) {
    $rs = pgsql8_db::query($sql);

    $rows = array();

    while (($row = pg_fetch_assoc($rs)) !== FALSE) {
      $rows[] = $row;
    }
    pgsql8_db::disconnect();
    return $rows;
  }

  protected function set_up_sequence_testing($schema_name = 'public') {
    $extracted_xml = pgsql8::extract_schema($this->pgsql8->get_dbhost(),
       $this->pgsql8->get_dbport(), $this->pgsql8->get_dbname(),
       $this->pgsql8->get_dbuser(), $this->pgsql8->get_dbpass());
    $rebuilt_db = simplexml_load_string($extracted_xml);
    // var_dump($rebuilt_db);
    $schema_node = $rebuilt_db->xpath("schema[@name='" . $schema_name . "']");
    // var_dump($schema_node);
    $sequence_node = $schema_node[0]->xpath("sequence");
    // var_dump($sequence_node);
    $expected_seq = $sequence_node[0];
    return $expected_seq;
  }

  public function testPublicSequencesBuildProperly() {
    $this->build_db_pgsql8();

    $sql = "CREATE SEQUENCE blah MINVALUE 3 MAXVALUE 10 CACHE 5";
    $this->pgsql8->query($sql);

    $expected_seq = $this->set_up_sequence_testing();

    $this->assertEquals('blah', (string)$expected_seq['name']);
    $this->assertEquals(3, (string)$expected_seq['min']);
    $this->assertEquals(10, (string)$expected_seq['max']);
    $this->assertEquals(5, (string)$expected_seq['cache']);

  }

  public function testIsolatedSequencesBuildProperly() {
    $this->build_db_pgsql8();

    $sql = "CREATE SEQUENCE testschema.testseq MINVALUE 3 MAXVALUE 10 CACHE 5";
    $this->pgsql8->query($sql);


    $expected_seq = $this->set_up_sequence_testing('testschema');

    $this->assertEquals('testseq', (string)$expected_seq['name']);
    $this->assertEquals(3, (string)$expected_seq['min']);
    $this->assertEquals(10, (string)$expected_seq['max']);
    $this->assertEquals(5, (string)$expected_seq['cache']);

  }

  public function testIntSequencesBecomeSerials() {
    $this->build_db_pgsql8();

    $extracted_xml = pgsql8::extract_schema($this->pgsql8->get_dbhost(),
       $this->pgsql8->get_dbport(), $this->pgsql8->get_dbname(),
       $this->pgsql8->get_dbuser(), $this->pgsql8->get_dbpass());
    $rebuilt_db = simplexml_load_string($extracted_xml);
    $public = $rebuilt_db->xpath("schema[@name='public']");
    $table = $public[0]->xpath("table[@name='user']");
    $column = $table[0]->xpath("column[@name='user_id']");

    $this->assertEquals('serial', (string)$column[0]['type']);

  }

  public function testNoTableSequencesBuilds() {
    // the base pgsql8 class keeps track of sequence columns linked to tables
    // (i.e. as primary keys, etc.)
    // during schema extraction so as to avoid creating duplicates; however,
    // if no tables link sequences, then the WHERE clause will contain an
    // empty string. this test should prove that this is no longer an issue.
    $xml = <<<XML
<dbsteward>
  <database>
    <role>
      <application>deployment</application>
      <owner>deployment</owner>
      <replication/>
      <readonly/>
    </role>
  </database>
  <schema name="public" owner="ROLE_OWNER">
    <!-- this sequence is just hanging around, not keyed to the table at all,
         so as to trigger an empty WHERE clause which should be handled
         properly now -->
    <sequence name="test_seq" start="1" inc="1" max="15" cycle="false" cache="1" owner="ROLE_OWNER">
      <grant operation="USAGE,SELECT,UPDATE" role="ROLE_APPLICATION"/>
    </sequence>
    <table name="user" owner="ROLE_OWNER" description="user logins" primaryKey="user_name">
      <column name="user_name" type="varchar(100)" null="false"/>
      <column name="user_role" type="varchar(100)" null="false"/>
      <column name="user_create_date" type="timestamp with time zone" null="false" default="NOW()"/>
      <grant role="ROLE_APPLICATION" operation="SELECT, INSERT, UPDATE"/>
      <rows columns="user_name, user_role">
        <tabrow>toor	super_admin</tabrow>
      </rows>
    </table>
  </schema>
</dbsteward>
XML;

    //
    $this->xml_file_a = __DIR__ . '/../testdata/unit_test_xml_a.xml';
    file_put_contents($this->xml_file_a, $xml);

    $this->build_db_pgsql8();

    $extracted_xml = pgsql8::extract_schema($this->pgsql8->get_dbhost(),
       $this->pgsql8->get_dbport(), $this->pgsql8->get_dbname(),
       $this->pgsql8->get_dbuser(), $this->pgsql8->get_dbpass());

    // no errors thrown by this point? we should be fine, but let's do some
    // checks to prove DDL integrtiry
    $rebuilt_db = simplexml_load_string($extracted_xml);
    $schema_node = $rebuilt_db->xpath("schema[@name='public']");
    $table_node = $schema_node[0]->xpath("table");

    // just make sure the table was built for now, the other tests do more
    // advanced checking
    $this->assertEquals('user', (string)$table_node[0]['name']);

    // test the sequence to make sure it built properly
    $sequence_node = $schema_node[0]->xpath("sequence");
    $expected_seq = $sequence_node[0];
    $this->assertEquals('test_seq', (string)$expected_seq['name']);
    $this->assertEquals(1, (string)$expected_seq['min']);
    $this->assertEquals(15, (string)$expected_seq['max']);
    $this->assertEquals(1, (string)$expected_seq['cache']);
  }

}
