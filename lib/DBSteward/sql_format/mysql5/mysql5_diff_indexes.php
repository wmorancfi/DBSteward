<?php
/**
 * Diffs indexes.
 *
 * @package DBSteward
 * @subpackage mysql5
 * @license http://www.opensource.org/licenses/bsd-license.php Simplified BSD License
 * @author Nicholas J Kiraly <kiraly.nicholas@gmail.com>
 * @author Austin Hyde <austin109@gmail.com>
 */

require_once __DIR__ . '/../sql99/sql99_diff_indexes.php';
require_once __DIR__ . '/mysql5_index.php';

class mysql5_diff_indexes extends sql99_diff_indexes {
  /**
   * This works differently than sql99_diff_indexes::diff_indexes_table because of the way
   * mysql5 handles indexes on foreign keys. If we DROP then CREATE an index being used in an FK
   * in separate statements, like we do for other formats, MySQL errors because an FK constraint
   * relies on that index. If you DROP and CREATE in the same statement, though, MySQL is happy.
   * So, just mash all the index changes for each table into a single ALTER TABLE and be done with it.
   */
  public static function diff_indexes_table($ofs, $old_schema, $old_table, $new_schema, $new_table) {
    $bits = array();

    // Drop indexes that do not exist in new schema or are modified
    foreach(static::get_drop_indexes($old_schema, $old_table, $new_schema, $new_table) as $index) {
      $bits[] = format_index::get_alter_drop_sql($new_schema, $new_table, $index);
      // $ofs->write(format_index::get_drop_sql($new_schema, $new_table, $index)."\n\n");
    }

    // Add new indexes
    if ($old_schema == null) {
      foreach(format_index::get_table_indexes($new_schema, $new_table) as $index) {
        $bits[] = format_index::get_alter_add_sql($new_schema, $new_table, $index);
        // $ofs->write(format_index::get_creation_sql($new_schema, $new_table, $index)."\n\n");
      }
    } else {
      foreach(static::get_new_indexes($old_schema, $old_table, $new_schema, $new_table) as $index) {
        $bits[] = format_index::get_alter_add_sql($new_schema, $new_table, $index);
        // $ofs->write(format_index::get_creation_sql($new_schema, $new_table, $index)."\n\n");
      }
    }

    if (!empty($bits)) {
      $ofs->write('ALTER TABLE ' . mysql5::get_fully_qualified_table_name($new_schema['name'], $new_table['name']) . "\n  " . implode(",\n  ", $bits) . ";\n\n");
    }
  }
}
