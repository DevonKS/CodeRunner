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
//
///////////////////
/// coderunner ///
///////////////////
/// CODERUNNER QUESTION TYPE CLASS //////////////////
// The class for programming code questions.
// A coderunner question consists of a specification for piece of program
// code, which might be a function or a complete program or
// just a fragment of code.
// The student's response must be source code that defines
// the specified function. The student's code is executed by
// a set of test cases, all of which must pass for the question
// to be marked correct. The code execution takes place in an external
// sandbox.
// In a typical use case each coderunner question will have its
// own submit button and students will keep submitting until
// they pass all tests, so that their mark will be based on
// the number of submissions and the penalty per wrong
// submissions.  However, there is the capability to allow per-test-case
// part marks by turning off the "all-or-nothing" checkbox when authoring the
// question.

/**
 * @package 	qtype
 * @subpackage 	coderunner
 * @copyright 	&copy; 2012, 2013, 2014 Richard Lobb
 * @author 	Richard Lobb richard.lobb@canterbury.ac.nz
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/engine/bank.php');
require_once($CFG->dirroot . '/lib/questionlib.php');
require_once($CFG->dirroot . '/question/type/coderunner/locallib.php');

/**
 * qtype_coderunner extends the base question_type to coderunner-specific functionality.
 * A coderunner question requires an additional DB table
 * that contains the definitions for the testcases associated with a programming code
 * question. There are an arbitrary number of these, so they can't be handled
 * by adding columns to the standard question table.
 * Each subclass cas its own testcase database table.
 *
 * Note: the database tables were to be given names like question_coderunner_testcases
 * but names are limited to 28 chars! So quest_coderunner_.* was used instead.
 */
class qtype_coderunner extends question_type {

    /**
     * Whether this question type can perform a frequency analysis of student
     * responses.
     *
     * If this method returns true, you must implement the get_possible_responses
     * method, and the question_definition class must implement the
     * classify_response method.
     *
     * @return bool whether this report can analyse all the student reponses
     * for things like the quiz statistics report.
     */
    public function can_analyse_responses() {
        return false;  // TODO Consider if this functionality should be enabled
    }

    /**
     * If your question type has a table that extends the question table, and
     * you want the base class to automatically save, backup and restore the extra fields,
     * override this method to return an array where the first element is the table name,
     * and the subsequent entries are the column names (apart from id and questionid).
     *
     * @return mixed array as above, or null to tell the base class to do nothing.
     */
    public function extra_question_fields() {
        return array('question_coderunner_options',
            'coderunnertype',
            'prototypetype',
            'allornothing',
            'penaltyregime',
            'showsource',
            'answerboxlines',
            'answerboxcolumns',
            'useace',
            'resultcolumns',
            'answer',
            'combinatortemplate',
            'testsplitterre',
            'enablecombinator',
            'pertesttemplate',
            'language',
            'acelang',
            'sandbox',
            'grader',
            'cputimelimitsecs',
            'memlimitmb',
            'sandboxparams',
            'templateparams'
        );
    }

    /** A list of the extra question fields that are NOT inheritable from
     *  the prototype and so are not hidden in the usual authoring interface
     *  as 'customise' fields.
     * @return array of strings
     */
    public static function noninherited_fields() {
        return array(
            'coderunnertype',
            'prototypetype',
            'allornothing',
            'penaltyregime',
            'showsource',
            'answerboxlines',
            'answerboxcolumns',
            'useace',
            'answer',
            'templateparams'
        );
    }

    /**
     * If you use extra_question_fields, overload this function to return question id field name
     * in case you table use another name for this column.
     * [Don't really need this as we're returning the default value, but I
     * prefer to be explicit.]
     */
    public function questionid_column_name() {
        return 'questionid';
    }


    /**
     * Abstract function implemented by each question type. It runs all the code
     * required to set up and save a question of any type for testing purposes.
     * Alternate DB table prefix may be used to facilitate data deletion.
     */
    public function generate_test($name, $courseid=null) {
        // Closer inspection shows that this method isn't actually implemented
        // by even the standard question types and wouldn't be called for any
        // non-standard ones even if implemented. I'm leaving the stub in, in
        // case it's ever needed, but have set it to throw an exception, and
        // I've removed the actual test code.
        throw new coding_exception('Unexpected call to generate_test. Read code for details.');
    }


    // Function to copy testcases from form fields into question->testcases
    private function copy_testcases_from_form(&$question) {

        function test_case_order_cmp($tc1, $tc2) {
            if ($tc1->ordering === $tc2->ordering) {
                return 0;
            } else {
                return $tc1->ordering < $tc2->ordering ? -1 : 1;
            }
        }

        $testcases = array();
        $numtests = count($question->testcode);
        assert(count($question->expected) == $numtests);
        for($i = 0; $i < $numtests; $i++) {
            $input = $this->filter_crs($question->testcode[$i]);
            $stdin = $this->filter_crs($question->stdin[$i]);
            $expected = $this->filter_crs($question->expected[$i]);
            $extra = $this->filter_crs($question->extra[$i]);
            if ($input === '' && $stdin === '' && $expected === '' && $extra === '') {
                continue;
            }
            $testcase = new stdClass;
            $testcase->questionid = isset($question->id) ? $question->id : 0;
            $testcase->testcode = $input;
            $testcase->stdin = $stdin;
            $testcase->expected = $expected;
            $testcase->extra = $extra;
            $testcase->useasexample = isset($question->useasexample[$i]);
            $testcase->display = $question->display[$i];
            $testcase->hiderestiffail = isset($question->hiderestiffail[$i]);
            $testcase->mark = trim($question->mark[$i]) == '' ? 1.0 : floatval($question->mark[$i]);
            $testcase->ordering = intval($question->ordering[$i]);
            $testcases[] = $testcase;
        }

        usort($testcases, 'test_case_order_cmp');  // Sort by ordering field

        $question->testcases = $testcases;  // Can't call setTestcases as question is a stdClass :-(
    }


    // Override save_question to record in $form if this is a new question or
    // not. Needed by save_question_options when saving prototypes.
    // Note that the $form parameter to save_question is passed through
    // to save_question_options as the $question parameter.
    public function save_question($question, $form) {
        $form->isnew = empty($question->id);

        $metatag = explode(PHP_EOL, $form->metatag);
        $metatags = array();

        foreach ($metatag as $tag) {
            if ($tag !== '') {
                $metatags[] = "meta;Base64;" . base64_encode($tag);
            }
        }

        //add splits to tags
        $form->tags = array_merge($form->tags, $metatags);

        return parent::save_question($question, $form);
    }

    // This override saves all the extra question data, including
    // the set of testcases and any datafiles to the database.

    public function save_question_options($question) {
        global $DB, $USER;

        assert(isset($question->coderunnertype));
        $fields = $this->extra_question_fields();
        array_shift($fields); // Discard table name
        $customised = isset($question->customise) && $question->customise;
        $isprototype = $question->prototypetype != 0;
        if ($customised && $question->prototypetype == 2 &&
            $question->coderunnertype != $question->typename) {
            // Saving a new user-defined prototype.
            // Copy new type name into coderunnertype
            $question->coderunnertype = $question->typename;
        }

        // If we're saving a new prototype, make sure its coderunnertype is
        // unique by appending a suitable suffix. [Shouldn't happen via
        // question edit form, but could be a spurious import or a question
        // duplication mouse click.]
        if ($question->isnew && $isprototype) {
            $suffix = '';
            $type = $question->coderunnertype;
            while (true) {
                try {
                    $row = $this->get_prototype($type . $suffix, $question->context);
                    $suffix = $suffix == '' ? '-1' : $suffix - 1;
                } catch (coderunner_exception $e) {
                    break;
                }
            }
            $question->coderunnertype = $type . $suffix;
        }

        // Set all inherited fields to null if the corresponding form
        // field is blank or if it's being saved with customise explicitly
        // turned off and it's not a prototype.

        $questioninherits = isset($question->customise) && !$question->customise && !$isprototype;
        foreach ($fields as $field) {
            $isinherited = !in_array($field, $this->noninherited_fields());
            $isblankstring = !isset($question->$field) ||
                (is_string($question->$field) && trim($question->$field) === '');
            if ($isinherited && ($isblankstring || $questioninherits)) {
                $question->$field = null;
            }
        }

        if (trim($question->sandbox) === 'DEFAULT') {
            $question->sandbox = null;
        }

        parent::save_question_options($question);

        $testcasetable = "question_coderunner_tests";

        if (!isset($question->testcases)) {
            $this->copy_testcases_from_form($question);
        }

        if (!$oldtestcases = $DB->get_records($testcasetable,
            array('questionid' => $question->id), 'id ASC')) {
            $oldtestcases = array();
        }

        foreach ($question->testcases as $tc) {
            if (($oldtestcase = array_shift($oldtestcases))) { // Existing testcase, so reuse it
                $tc->id = $oldtestcase->id;
                $DB->update_record($testcasetable, $tc);
            } else {
                // A new testcase
                $tc->questionid = $question->id;
                $id = $DB->insert_record($testcasetable, $tc);
            }
        }

        // delete old testcase records
        foreach ($oldtestcases as $otc) {
            $DB->delete_records($testcasetable, array('id' => $otc->id));
        }

        // If this is a prototype, clear the caching of any child questions
        if ($question->prototypetype != 0) {
            $typename = $question->coderunnertype;
            $children = $DB->get_records('question_coderunner_options',
                array('prototypetype' => 0,
                    'coderunnertype' => $typename)
            );
            foreach($children as $child) {
                question_bank::notify_question_edited($child->questionid);
            }
        }


        // Lastly, save any datafiles

        if ($USER->id && isset($question->datafiles))  {
            //  The id check is a hack to deal with phpunit initialisation, when no user exists
            file_save_draft_area_files($question->datafiles, $question->context->id,
                'qtype_coderunner', 'datafile', (int) $question->id, $this->fileoptions);
        }

        return true;
    }


    /**
     * Move all the files belonging to this question from one context to another.
     * Override superclass implementation to handle the extra data files
     * we have in CodeRunner questions.
     * @param int $questionid the question being moved.
     * @param int $oldcontextid the context it is moving from.
     * @param int $newcontextid the context it is moving to.
     */
    public function move_files($questionid, $oldcontextid, $newcontextid) {
        parent::move_files($questionid, $oldcontextid, $newcontextid);
        $fs = get_file_storage();
        $fs->move_area_files_to_new_context($oldcontextid,
            $newcontextid, 'qtype_coderunner', 'datafile', $questionid);
    }

    // Load the question options (all the question extension fields and
    // testcases) from the database into the question.
    // The various fields are initialised from the prototype, then overridden
    // by any non-null values in the specific question.

    public function get_question_options($question) {
        global $CFG, $DB, $OUTPUT;
        parent::get_question_options($question);

        if ($question->options->prototypetype != 0) { // Question prototype?
            // Yes. It's 100% customised with nothing to inherit.
            $question->options->customise = True;
        } else {

            // Add to the question all the inherited fields from the question's prototype
            // record that have not been overridden (i.e. that are null) by this
            // instance. If any of the inherited fields are modified (i.e. any
            // (extra field not in the noninheritedFields list), the 'customise'
            // field is set. This is used only to display the customisation panel.

            $qtype = $question->options->coderunnertype;
            $context = $this->question_context($question);
            $row = $this->get_prototype($qtype, $context);
            $question->options->customise = False; // Starting assumption
            $noninheritedfields = $this->noninherited_fields();
            foreach ($row as $field => $value) {
                $isinheritedfield = !in_array($field, $noninheritedfields);
                if ($isinheritedfield) {
                    if (isset($question->options->$field) &&
                        $question->options->$field !== null &&
                        $question->options->$field !== '' &&
                        $question->options->$field != $value) {
                        $question->options->customise = True; // An inherited field has been changed
                    } else {
                        $question->options->$field = $value;
                    }
                }
            }

            if (!isset($question->options->sandbox))  {
                $question->options->sandbox = null;
            }

            if (!isset($question->options->grader)) {
                $question->options->grader = null;
            }

            if (!isset($question->options->sandboxparams) || trim($question->options->sandboxparams) === '') {
                $question->options->sandboxparams = null;
            }
        }

        // Add in any testcases (expect none for built-in prototypes)
        if (!$question->options->testcases = $DB->get_records('question_coderunner_tests',
            array('questionid' => $question->id), 'id ASC')) {
            if ($question->options->prototypetype == 0
                && $question->options->grader !== 'qtype_coderunner_combinator_template_grader') {
                throw new coderunner_exception("Failed to load testcases for question id {$question->id}");
            } else {
                // Question prototypes may not have testcases
                $question->options->testcases = array();
            }
        }

        return true;
    }


    // Get a list of all valid prototypes in the current
    // course context.
    public static function get_all_prototypes() {
        global $DB, $COURSE;
        $rows = $DB->get_records_select(
            'question_coderunner_options',
            'prototypetype != 0');
        $valid = array();
        $coursecontext = context_course::instance($COURSE->id);
        foreach ($rows as $row) {
            if (self::is_available_prototype($row, $coursecontext)) {
                $valid[] = $row;
            }
        }
        return $valid;

    }


    // Get the specified prototype question from the database.
    // Returns the row from the question_coderunner_options table, not the
    // question itself.
    // To be valid, the named prototype (a question of the specified type
    // and with prototypetype non zero) must be in a question category that's
    // available in the given current context.
    public static function get_prototype($coderunnertype, $context) {
        global $DB;
        $rows = $DB->get_records_select(
            'question_coderunner_options',
            "coderunnertype = '$coderunnertype' and prototypetype != 0");

        if (count($rows) == 0) {
            throw new coderunner_exception("Failed to find prototype $coderunnertype");
        }

        $validProtos = array();
        foreach ($rows as $row) {
            if (self::is_available_prototype($row, $context)) {
                $validProtos[] = $row;
            }
        }

        if (count($validProtos) == 0) {
            throw new coderunner_exception("Prototype $coderunnertype is unavailable ".
                "in this context");
        } else if (count($validProtos) != 1) {
            throw new coderunner_exception("Multiple prototypes found for $coderunnertype");
        }
        return $validProtos[0];
    }


    // True iff the given row from the question_coderunner_options table
    // is a valid prototype in the given context.
    public static function is_available_prototype($questionoptionsrow, $context) {
        global $DB;
        static $activeCats = null;

        if (!$question = $DB->get_record('question', array('id' => $questionoptionsrow->questionid))) {
            throw new coderunner_exception("Missing question id = {$questionoptionsrow->questionid} in question table");
        }

        if (!$candidatecat = $DB->get_record('question_categories', array('id' => $question->category))) {
            throw new coderunner_exception('Missing question category');
        }

        if ($activeCats === null) {
            $allContexts = $context->get_parent_context_ids(true);
            $activeCats = get_categories_for_contexts(implode(',', $allContexts));
        }

        foreach ($activeCats as $cat) {
            if ($cat->id == $candidatecat->id) {
                return true;
            }
        }
        return false;
    }


    // Returns the context of the given question's category. The question
    // parameter might be a true question or might be a row from the
    // question options table.
    public static function question_context($question) {
        global $DB;

        if (!isset($question->contextid)) {
            $questionid = isset($question->questionid) ? $question->questionid : $question->id;
            $sql = "SELECT contextid FROM {question_categories}, {question} " .
                "WHERE {question}.id = $questionid " .
                "AND {question}.category = {question_categories}.id";
            $contextid = $DB->get_field_sql($sql, null, MUST_EXIST);
        } else {
            $contextid = $question->contextid;
        }
        return context::instance_by_id($contextid);
    }

    // Initialise the question_definition object from the questiondata
    // read from the database (probably a cached version of the question
    // object from the database enhanced by a call to get_question_options).
    // Only fields not explicitly listed in extra_question_fields (i.e. those
    // fields not from the question_coderunner_options table) need handling here.
    // All we do is flatten the question->options fields down into the
    // question itself, which will be all those fields of question->options
    // not already flattened down by the parent implementation.

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        foreach ($questiondata->options as $field => $value) {
            if (!isset($question->$field)) {
                $question->$field = $value;
            }
        }
    }


    // Override required here so we can check if this is a prototype
    // with children (in which case deletion is disallowed). If not,
    // deletion is allowed but must delete the testcases too.
    public function delete_question($questionid, $contextid) {
        global $DB;


        // TODO: find a solution to the problem of deleting in-use
        // prototypes. The code below isn't isn't correct (it doesn't
        // check the context of the question) so the entire block has
        // been commented out. Currently it's over to
        // to user to make sure they don't delete in-use prototypes.
        /*$question = $DB->get_record(
                'question_coderunner_options',
                array('questionid' => $questionid));


        if ($question->prototypetype != 0) {
            $typeName = $question->coderunnertype;
            $nUses = $DB->count_records('question_coderunner_options',
                    array('prototypetype' => 0,
                          'coderunnertype' => $typeName));
            if ($nUses != 0) {
                // TODO: see if a better solution to this problem can be found.
                // Throwing an exception is very heavy-handed but the return
                // value from this function is ignored by the question bank,
                // and other deletion (e.g. of the question itself) proceeds
                // regardless, leaving things in an even worse state than if
                // I didn't even check for an in-use prototype!
                throw new moodle_exception('Attempting to delete in-use prototype');
            }
        }

        */

        $success = $DB->delete_records("question_coderunner_tests",
            array('questionid' => $questionid));
        return $success && parent::delete_question($questionid, $contextid);
    }


/// IMPORT/EXPORT FUNCTIONS /////////////////

    /*
     * Imports question from the Moodle XML format
     *
     * Overrides default since coderunner questions contain a list of testcases,
     * not a list of answers.
     *
     */
    function import_from_xml($data, $question, qformat_xml $format, $extra=null) {

        if ($extra != null) {
            throw new coding_exception("coderunner:import_from_xml: unexpected 'extra' parameter");
        }

        $questiontype = $data['@']['type'];
        if ($questiontype != $this->name()) {
            return false;
        }

        $extraquestionfields = $this->extra_question_fields();
        if (!is_array($extraquestionfields)) {
            return false;
        }

        //omit table name
        array_shift($extraquestionfields);
        $qo = $format->import_headers($data);
        $qo->qtype = $questiontype;

        $newdefaults = array(
            'allornothing' => 1,
            'answerboxlines' => 15,
            'answerboxcolumns' => 90,
            'useace' => 1
        );

        foreach ($extraquestionfields as $field) {
            if ($field === 'pertesttemplate'  && isset($data['#']['custom_template'])) {
                // Legacy import
                $qo->pertesttemplate = $format->getpath($data, array('#', 'custom_template', 0, '#'), '');
            }
            else {
                $map = $this->legacy_field_name_map();
                if (isset($map[$field]) && isset($data['#'][$map[$field]])) {
                    $data['#'][$field] = $data['#'][$map[$field]]; // Map old field names to new
                    unset($data['#'][$map[$field]]);
                }
                if (array_key_exists($field, $newdefaults)) {
                    $default = $newdefaults[$field];
                } else {
                    $default = '';
                }
                $qo->$field = $format->getpath($data, array('#', $field, 0, '#'), $default);
            }
        }

        $qo->isnew = true;

        $qo->testcases = array();

        if (isset($data['#']['testcases'][0]['#']['testcase']) &&
            is_array($data['#']['testcases'][0]['#']['testcase'])) {
            $testcases = $data['#']['testcases'][0]['#']['testcase'];
            foreach ($testcases as $testcase) {
                $tc = new stdClass;
                $tc->testcode = $testcase['#']['testcode'][0]['#']['text'][0]['#'];
                $tc->stdin = $testcase['#']['stdin'][0]['#']['text'][0]['#'];
                if (isset($testcase['#']['output'])) { // Handle old exports
                    $tc->expected = $testcase['#']['output'][0]['#']['text'][0]['#'];
                }
                else {
                    $tc->expected = $testcase['#']['expected'][0]['#']['text'][0]['#'];
                }
                $tc->extra = $testcase['#']['extra'][0]['#']['text'][0]['#'];
                $tc->display = 'SHOW';
                $tc->mark = 1.0;
                if (isset($testcase['@']['mark'])) {
                    $tc->mark = floatval($testcase['@']['mark']);
                }
                if (isset($testcase['@']['hidden']) && $testcase['@']['hidden'] == "1") {
                    $tc->display = 'HIDE';  // Handle old-style export too
                }
                if (isset($testcase['#']['display'])) {
                    $tc->display = $testcase['#']['display'][0]['#']['text'][0]['#'];
                }
                if (isset($testcase['@']['hiderestiffail'] )) {
                    $tc->hiderestiffail = $testcase['@']['hiderestiffail'] == "1" ? 1 : 0;
                }
                else {
                    $tc->hiderestiffail = 0;
                }
                $tc->useasexample = $testcase['@']['useasexample'] == "1" ? 1 : 0;
                $qo->testcases[] = $tc;
            }
        }

        $datafiles = $format->getpath($data,
            array('#', 'testcases', 0, '#', 'file'), array());
        if (is_array($datafiles)) { // Seems like a non-array does occur in some versions of PHP :-/
            $qo->datafiles = $format->import_files_as_draft($datafiles);
        }

        return $qo;
    }

    /*
     * Export question to the Moodle XML format
     *
     * We override the default method because we don't have 'answers' but
     * testcases.
     *
     */

    // Exporting is complicated by inheritance from the prototype.
    // To deal with this we re-read the prototype and include in the
    // export only the coderunner extra fields that are not inherited or that
    // are not equal in value to the field from the prototype.

    function export_to_xml($question, qformat_xml $format, $extra=null) {
        global $COURSE;
        if ($extra !== null) {
            throw new coding_exception("coderunner:export_to_xml: Unexpected parameter");
        }

        // Copy the question so we can modify it for export
        // (Just in case the original gets used elsewhere).
        $questiontoexport = clone $question;

        $qtype = $question->options->coderunnertype;
        $coursecontext = context_course::instance($COURSE->id);
        $row = self::get_prototype($qtype, $coursecontext);

        // Clear all inherited fields equal in value to the corresponding Prototype field
        // (but only if this is not a prototype question itself)
        if ($questiontoexport->options->prototypetype == 0) {
            $noninheritedfields = $this->noninherited_fields();
            $extrafields = $this->extra_question_fields();
            foreach ($row as $field => $value) {
                if (in_array($field, $extrafields) &&
                    !in_array($field, $noninheritedfields) &&
                    $question->options->$field === $value) {
                    $questiontoexport->options->$field = null;
                }
            }
        }

        $expout = parent::export_to_xml($questiontoexport, $format, $extra);;

        $expout .= "    <testcases>\n";

        foreach ($question->options->testcases as $testcase) {
            $useasexample = $testcase->useasexample ? 1 : 0;
            $hiderestiffail = $testcase->hiderestiffail ? 1 : 0;
            $mark = sprintf("%.7f", $testcase->mark);
            $expout .= "      <testcase useasexample=\"$useasexample\" hiderestiffail=\"$hiderestiffail\" mark=\"$mark\" >\n";
            foreach (array('testcode', 'stdin', 'expected', 'extra', 'display') as $field) {
                $exportedValue = $format->writetext($testcase->$field, 4);
                $expout .= "      <{$field}>\n        {$exportedValue}      </{$field}>\n";
            }
            $expout .= "    </testcase>\n";
        }

        // Add datafiles within the scope of the <testcases> element
        $fs = get_file_storage();
        $contextid = $question->contextid;
        $datafiles = $fs->get_area_files(
            $contextid, 'qtype_coderunner', 'datafile', $question->id);
        $expout .= $format->write_files($datafiles);

        $expout .= "    </testcases>\n";
        return $expout;
    }


    /** Utility func: remove all '\r' chars from $s and also trim trailing newlines */
    private function filter_crs($s) {
        $s = str_replace("\r", "", $s);
        while (substr($s, strlen($s) - 1, 1) == '\n') {
            $s = substr($s, 0, strlen($s) - 1);
        }
        return $s;
    }


    // A map from question_options field names to their legacy versions
    // withn underscores. Only those field names changed between versions 2.3
    // and 2.4 of CodeRunner appear here.
    public static function legacy_field_name_map() {
        $oldfields = array(
            'coderunnertype'   => 'coderunner_type',
            'prototypetype'    => 'prototype_type',
            'allornothing'     => 'all_or_nothing',
            'answerboxlines'   => 'answerbox_lines',
            'answerboxcolumns' => 'answerbox_columns',
            'useace'           => 'use_ace',
            'penaltyregime'    => 'penalty_regime',
            'enablecombinator' => 'enable_combinator',
            'resultcolumns'    => 'result_columns',
            'combinatortemplate' => 'combinator_template',
            'testsplitterre'   => 'test_splitter_re',
            'pertesttemplate'  => 'per_test_template',
            'templateparams'   => 'template_params',
            'acelang'          => 'ace_lang',
            'sandboxparams'    => 'sandbox_params',
            'showsource'       => 'show_source');
        return $oldfields;
    }

}
