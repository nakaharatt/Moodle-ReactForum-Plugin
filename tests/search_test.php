<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * ReactForum search unit tests.
 *
 * @package     mod_reactforum
 * @category    test
 * @copyright   2015 David Monllao {@link http://www.davidmonllao.com}
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/search/tests/fixtures/testable_core_search.php');
require_once($CFG->dirroot . '/mod/reactforum/tests/generator/lib.php');
require_once($CFG->dirroot . '/mod/reactforum/lib.php');

/**
 * Provides the unit tests for reactforum search.
 *
 * @package     mod_reactforum
 * @category    test
 * @copyright   2015 David Monllao {@link http://www.davidmonllao.com}
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_reactforum_search_testcase extends advanced_testcase {

    /**
     * @var string Area id
     */
    protected $reactforumpostareaid = null;

    public function setUp() {
        $this->resetAfterTest(true);
        set_config('enableglobalsearch', true);

        $this->reactforumpostareaid = \core_search\manager::generate_areaid('mod_reactforum', 'post');

        // Set \core_search::instance to the mock_search_engine as we don't require the search engine to be working to test this.
        $search = testable_core_search::instance();
    }

    /**
     * Availability.
     *
     * @return void
     */
    public function test_search_enabled() {

        $searcharea = \core_search\manager::get_search_area($this->reactforumpostareaid);
        list($componentname, $varname) = $searcharea->get_config_var_name();

        // Enabled by default once global search is enabled.
        $this->assertTrue($searcharea->is_enabled());

        set_config($varname . '_enabled', 0, $componentname);
        $this->assertFalse($searcharea->is_enabled());

        set_config($varname . '_enabled', 1, $componentname);
        $this->assertTrue($searcharea->is_enabled());
    }

    /**
     * Indexing mod reactforum contents.
     *
     * @return void
     */
    public function test_posts_indexing() {
        global $DB;

        // Returns the instance as long as the area is supported.
        $searcharea = \core_search\manager::get_search_area($this->reactforumpostareaid);
        $this->assertInstanceOf('\mod_reactforum\search\post', $searcharea);

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();

        $course1 = self::getDataGenerator()->create_course();
        $course2 = self::getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($user2->id, $course1->id, 'student');

        $record = new stdClass();
        $record->course = $course1->id;

        // Available for both student and teacher.
        $reactforum1 = self::getDataGenerator()->create_module('reactforum', $record);

        // Create discussion1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->reactforum = $reactforum1->id;
        $record->message = 'discussion';
        $discussion1 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_discussion($record);

        // Create post1 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user2->id;
        $record->message = 'post2';
        $discussion1reply1 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_post($record);

        // All records.
        $recordset = $searcharea->get_recordset_by_timestamp(0);
        $this->assertTrue($recordset->valid());
        $nrecords = 0;
        foreach ($recordset as $record) {
            $this->assertInstanceOf('stdClass', $record);
            $doc = $searcharea->get_document($record);
            $this->assertInstanceOf('\core_search\document', $doc);

            // Static caches are working.
            $dbreads = $DB->perf_get_reads();
            $doc = $searcharea->get_document($record);
            $this->assertEquals($dbreads, $DB->perf_get_reads());
            $this->assertInstanceOf('\core_search\document', $doc);
            $nrecords++;
        }
        // If there would be an error/failure in the foreach above the recordset would be closed on shutdown.
        $recordset->close();
        $this->assertEquals(2, $nrecords);

        // The +2 is to prevent race conditions.
        $recordset = $searcharea->get_recordset_by_timestamp(time() + 2);

        // No new records.
        $this->assertFalse($recordset->valid());
        $recordset->close();
    }

    /**
     * Document contents.
     *
     * @return void
     */
    public function test_posts_document() {
        global $DB;

        // Returns the instance as long as the area is supported.
        $searcharea = \core_search\manager::get_search_area($this->reactforumpostareaid);
        $this->assertInstanceOf('\mod_reactforum\search\post', $searcharea);

        $user = self::getDataGenerator()->create_user();
        $course1 = self::getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course1->id, 'teacher');

        $record = new stdClass();
        $record->course = $course1->id;
        $reactforum1 = self::getDataGenerator()->create_module('reactforum', $record);

        // Teacher only.
        $reactforum2 = self::getDataGenerator()->create_module('reactforum', $record);
        set_coursemodule_visible($reactforum2->cmid, 0);

        // Create discussion1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user->id;
        $record->reactforum = $reactforum1->id;
        $record->message = 'discussion';
        $discussion1 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_discussion($record);

        // Create post1 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user->id;
        $record->subject = 'subject1';
        $record->message = 'post1';
        $discussion1reply1 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_post($record);

        $post1 = $DB->get_record('reactforum_posts', array('id' => $discussion1reply1->id));
        $post1->reactforumid = $reactforum1->id;
        $post1->courseid = $reactforum1->course;

        $doc = $searcharea->get_document($post1);
        $this->assertInstanceOf('\core_search\document', $doc);
        $this->assertEquals($discussion1reply1->id, $doc->get('itemid'));
        $this->assertEquals($this->reactforumpostareaid . '-' . $discussion1reply1->id, $doc->get('id'));
        $this->assertEquals($course1->id, $doc->get('courseid'));
        $this->assertEquals($user->id, $doc->get('userid'));
        $this->assertEquals($discussion1reply1->subject, $doc->get('title'));
        $this->assertEquals($discussion1reply1->message, $doc->get('content'));
    }

    /**
     * Document accesses.
     *
     * @return void
     */
    public function test_posts_access() {
        global $DB;

        // Returns the instance as long as the area is supported.
        $searcharea = \core_search\manager::get_search_area($this->reactforumpostareaid);

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();

        $course1 = self::getDataGenerator()->create_course();
        $course2 = self::getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'teacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course1->id, 'student');

        $record = new stdClass();
        $record->course = $course1->id;

        // Available for both student and teacher.
        $reactforum1 = self::getDataGenerator()->create_module('reactforum', $record);

        // Teacher only.
        $reactforum2 = self::getDataGenerator()->create_module('reactforum', $record);
        set_coursemodule_visible($reactforum2->cmid, 0);

        // Create discussion1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->reactforum = $reactforum1->id;
        $record->message = 'discussion';
        $discussion1 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_discussion($record);

        // Create post1 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user2->id;
        $record->message = 'post1';
        $discussion1reply1 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_post($record);

        // Create discussion2 only visible to teacher.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->reactforum = $reactforum2->id;
        $record->message = 'discussion';
        $discussion2 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_discussion($record);

        // Create post2 in discussion2.
        $record = new stdClass();
        $record->discussion = $discussion2->id;
        $record->parent = $discussion2->firstpost;
        $record->userid = $user1->id;
        $record->message = 'post2';
        $discussion2reply1 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_post($record);

        $this->setUser($user2);
        $this->assertEquals(\core_search\manager::ACCESS_GRANTED, $searcharea->check_access($discussion1reply1->id));
        $this->assertEquals(\core_search\manager::ACCESS_DENIED, $searcharea->check_access($discussion2reply1->id));
    }

    /**
     * Test for post attachments.
     *
     * @return void
     */
    public function test_attach_files() {
        global $DB;

        $fs = get_file_storage();

        // Returns the instance as long as the area is supported.
        $searcharea = \core_search\manager::get_search_area($this->reactforumpostareaid);
        $this->assertInstanceOf('\mod_reactforum\search\post', $searcharea);

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();

        $course1 = self::getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($user2->id, $course1->id, 'student');

        $record = new stdClass();
        $record->course = $course1->id;

        $reactforum1 = self::getDataGenerator()->create_module('reactforum', $record);

        // Create discussion1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->reactforum = $reactforum1->id;
        $record->message = 'discussion';
        $record->attachemt = 1;
        $discussion1 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_discussion($record);

        // Attach 2 file to the discussion post.
        $post = $DB->get_record('reactforum_posts', array('discussion' => $discussion1->id));
        $filerecord = array(
            'contextid' => context_module::instance($reactforum1->cmid)->id,
            'component' => 'mod_reactforum',
            'filearea'  => 'attachment',
            'itemid'    => $post->id,
            'filepath'  => '/',
            'filename'  => 'myfile1'
        );
        $file1 = $fs->create_file_from_string($filerecord, 'Some contents 1');
        $filerecord['filename'] = 'myfile2';
        $file2 = $fs->create_file_from_string($filerecord, 'Some contents 2');

        // Create post1 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user2->id;
        $record->message = 'post2';
        $record->attachemt = 1;
        $discussion1reply1 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_post($record);

        $filerecord['itemid'] = $discussion1reply1->id;
        $filerecord['filename'] = 'myfile3';
        $file3 = $fs->create_file_from_string($filerecord, 'Some contents 3');

        // Create post2 in discussion1.
        $record = new stdClass();
        $record->discussion = $discussion1->id;
        $record->parent = $discussion1->firstpost;
        $record->userid = $user2->id;
        $record->message = 'post3';
        $discussion1reply2 = self::getDataGenerator()->get_plugin_generator('mod_reactforum')->create_post($record);

        // Now get all the posts and see if they have the right files attached.
        $searcharea = \core_search\manager::get_search_area($this->reactforumpostareaid);
        $recordset = $searcharea->get_recordset_by_timestamp(0);
        $nrecords = 0;
        foreach ($recordset as $record) {
            $doc = $searcharea->get_document($record);
            $searcharea->attach_files($doc);
            $files = $doc->get_files();
            // Now check that each doc has the right files on it.
            switch ($doc->get('itemid')) {
                case ($post->id):
                    $this->assertCount(2, $files);
                    $this->assertEquals($file1->get_id(), $files[$file1->get_id()]->get_id());
                    $this->assertEquals($file2->get_id(), $files[$file2->get_id()]->get_id());
                    break;
                case ($discussion1reply1->id):
                    $this->assertCount(1, $files);
                    $this->assertEquals($file3->get_id(), $files[$file3->get_id()]->get_id());
                    break;
                case ($discussion1reply2->id):
                    $this->assertCount(0, $files);
                    break;
                default:
                    $this->fail('Unexpected post returned');
                    break;
            }
            $nrecords++;
        }
        $recordset->close();
        $this->assertEquals(3, $nrecords);
    }
}
