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
 * @package     mod_tracker
 * @category    mod
 * @author Clifford Tham, Valery Fremaux > 1.8
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/formslib.php');

class TrackerIssueForm extends moodleform {

    /**
     * List of dynamic forms elements
     */
    protected $elements;

    /**
     * Options for editors
     */
    public $editoroptions;

    /**
     * context for file handling
     */
    protected $context;

    /**
     * Dynamically defines the form using elements setup in tracker instance
     */
    public function definition()
    {
        global $DB, $COURSE, $USER;

        $tracker = $this->_customdata['tracker'];

        /*
         * SAMSUNG BEGIN - adding variables to have more control over assignees and editing
         */
        if (isset($this->_customdata['issueid'])) {
            $issue = $DB->get_record('tracker_issue', array('id' => $this->_customdata['issueid']));
        }
        /*
         * SAMSUNG END
         */

        $this->context = context_module::instance($this->_customdata['cmid']);
        $maxfiles = 99;                // TODO: add some setting.
        $maxbytes = $COURSE->maxbytes; // TODO: add some setting.
        $this->editoroptions = array('trusttext' => true,
            'subdirs' => false,
            'maxfiles' => $maxfiles,
            'maxbytes' => $maxbytes,
            'context' => $this->context);

        $mform = $this->_form;

        $mform->addElement('hidden', 'id'); // Course module id.
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'issueid'); // Issue id.
        $mform->setType('issueid', PARAM_INT);

        $mform->addElement('hidden', 'trackerid', $tracker->id);
        $mform->setType('trackerid', PARAM_INT);

        $mform->addElement('header', 'header0', get_string('description'));

        $summary = $mform->addElement('text', 'summary', get_string('summary', 'tracker'), array('size' => 80));
        $mform->setType('summary', PARAM_TEXT);
        $mform->addRule('summary', null, 'required', null, 'client');

        $editor = $mform->addElement('editor', 'description_editor', get_string('description'), $this->editoroptions);

        /*
         * SAMSUNG BEGIN - give student a choice, to whom address his ticket, if SOMEHOW he has multiple enrolments in one track
         */
        if ($this->_customdata['mode'] == 'add') {

            // try get student group context
            $studgroups = \local_sic\StudentGroup::getStudentGroupsOfStudent($USER->id);
            if (count($studgroups) == 1) {
                // get context level of group
                $studgroupcontext = new \local_sic\StudentGroup(reset($studgroups)->instanceid);
                $mform->addElement('hidden', 'reportercontextid');
                $mform->setType('reportercontextid', PARAM_INT);
                $mform->setConstant('reportercontextid', $studgroupcontext->id);
            } else if (count($studgroups) > 1) {
                // should not happen ???
                throw new coding_exception('Invalid student group');
            } else {
                // no studgroup - try get faculty context
                // TODO: this code bit feels janky, refactor this maybe
                $tracks_in_course = \local_sic\Track::getTracks($COURSE->id);
                $enrolments = array();  // 1st approach (using this at the moment)
                $enrs = array();        // 2nd approach (a bit more pretty)
                foreach ($tracks_in_course as $k => $v) {
                    $enrolment = \enrol_sic\Enrolment::getUserEnrolments($USER->id, $v->id);
                    if ($enrolment) {
                        $enrs = array_merge((array)$enrs, $enrolment);
                        $enrolments[] = $enrolment;
                    }
                }

                if (count($enrolments) == 0) {
                    // no enrols on this course on any track - probably self study
                    $coursecontext = context_course::instance($COURSE->id);
                    $mform->addElement('hidden', 'reportercontextid');
                    $mform->setType('reportercontextid', PARAM_INT);
                    $mform->setConstant('reportercontextid', $coursecontext->id);

                } else if (count($enrolments) == 1) {
                    // all fine - one enrolment on this course in one track
                    $enrols_arr = $enrolments[0];
                    if (count($enrols_arr) == 1) {
                        // all fine - one request on this course
                        $faculty_obj = reset($enrols_arr)->getFaculty();
                        $mform->addElement('hidden', 'reportercontextid');
                        $mform->setType('reportercontextid', PARAM_INT);
                        $mform->setConstant('reportercontextid', $faculty_obj->context->id);
                        $curators = $faculty_obj->getCurators();

                    } else if (count($enrols_arr) > 1) {
//                     ??? more than one faculty - should that happen ???
//                     student can choose to which faculty ticket is addressed

//                    $select_facs = [];
//                    foreach ($enrols_arr as $enrol_obj) {
//                        $fac_obj = $enrol_obj->getFaculty();
//                        $select_facs[$fac_obj->context->id] = $fac_obj->get_name();
//                    }

//                    // TODO: to string
//                    $mform->addElement('select', 'reportercontextid', 'Выберите адресата вашего тикета', $select_facs);
//                    $mform->setType('reportercontextid', PARAM_INT);

                    }
                } else {
//                 should not happen
//                echo "more than one enrol on one course (multiple tracks)";
                }
            }
        } else {
            $mform->addElement('hidden', 'reportercontextid');
            $mform->setType('reportercontextid', PARAM_INT);
            $mform->setConstant('summary', $issue->reportercontextid);
        }
        /*
         * SAMSUNG END
         */

        tracker_loadelementsused($tracker, $this->elements);

        if (!empty($this->elements)) {
            foreach ($this->elements as $element) {
                if ((get_class($element) == 'captchaelement') && ($this->_customdata['mode'] == 'update')) {
                    // Avoid captcha when updating issue data.
                    continue;
                }
                if ($this->_customdata['mode'] == 'add') {
                    if (($element->active == true) && ($element->private == false)) {
                        $element->add_form_element($mform);
                    }
                } else {
                    // We are updating.
                    $caps = array('mod/tracker:manage', 'mod/tracker:develop');
                    if ($element->private == false || has_any_capability($caps, $this->context)) {
                        $element->add_form_element($mform);
                    }
                }
            }
        }

        if ($this->_customdata['mode'] == 'update') {
            /*
             * SAMSUNG BEGIN - make summary and description readonly
             */
            $mform->hardFreeze(array('summary'));
            $mform->setConstant('summary', $issue->summary);

//            $mform->freeze(array('description_editor'));
            $editor_arr = array();
            $editor_arr['text'] = @$issue->description;
            $editor_arr['format'] = @$issue->descriptionformat;
            $mform->hardFreeze(array('description_editor'));
            $mform->setConstant('description_editor', $editor_arr);
            /*
             * SAMSUNG END
             */

            $mform->addelement('header', 'processinghdr', get_string('processing', 'tracker'), '');

            // Assignee. Both developers and resolvers can be assigned.
            // TODO: change logic of assignees' menu
            $context = context_module::instance($this->_customdata['cmid']);

            $sic_resolvers = tracker_get_sic_resolvers($issue);
            if ($sic_resolvers) {
                $resolversmenu[0] = '---- '. get_string('unassigned', 'tracker').' -----';
                foreach ($sic_resolvers as &$sic_resolver) {
                    $sic_resolver = \core_user::get_user($sic_resolver);
                    $resolversmenu[$sic_resolver->id] = fullname($sic_resolver);
                }
            } else {
                $managers = tracker_getadministrators($context);
                if ($managers) {
                    $resolversmenu[0] = '---- '. get_string('unassigned', 'tracker').' -----';
                    foreach ($managers as $manager) {
                        $resolversmenu[$manager->id] = fullname($manager);
                    }
                    $mform->addElement('select', 'assignedto', get_string('assignedto', 'tracker'), $resolversmenu);
                }
            }

            if (!empty($resolversmenu)) {
                if (isset($issue) && !isset($resolversmenu[$issue->assignedto])) {
                    $originalassignee = \core_user::get_user($issue->assignedto);
                    $resolversmenu[$issue->assignedto] = fullname($originalassignee);
                }
                $mform->addElement('select', 'assignedto', get_string('assignedto', 'tracker'), $resolversmenu);
            } else {
                $mform->addElement('static', 'resolversshadow', get_string('assignedto', 'tracker'), get_string('noresolvers', 'tracker'));
                $mform->addElement('hidden', 'assignedto');
                $mform->setType('assignedto', PARAM_INT);
            }

            /*
            $resolvers = tracker_getresolvers($context);
            $developers = tracker_getdevelopers($context);
            $resolversmenu[0] = '---- '. get_string('unassigned', 'tracker').' -----';
            if (($resolvers || $developers) && has_any_capability(array('mod/tracker:develop', 'mod/tracker:manage'), $this->context)) {
                if ($resolvers) {
                    foreach ($resolvers as $resolver) {
                        $resolversmenu[$resolver->id] = fullname($resolver);
                    }
                }
                if ($developers) {
                    foreach ($developers as $developer) {
                        // May overwrite previous resolver entry, but doesn't matter if it does.
                        $resolversmenu[$developer->id] = fullname($developer);
                    }
                }
                $mform->addElement('select', 'assignedto', get_string('assignedto', 'tracker'), $resolversmenu);
            } else {
                $mform->addElement('static', 'resolversshadow', get_string('assignedto', 'tracker'), get_string('noresolvers', 'tracker'));
                $mform->addElement('hidden', 'assignedto');
                $mform->setType('assignedto', PARAM_INT);
            }
            */


            // Status.
            $statuskeys = tracker_get_statuskeys($tracker);
            $mform->addElement('select', 'status', get_string('status', 'tracker'), $statuskeys);

            /*
             * SAMSUNG BEGIN - hide dependencies on ticket

            // Dependencies.
            $dependencies = tracker_getpotentialdependancies($tracker->id, $this->_customdata['issueid']);
            if (!empty($dependencies)) {
                foreach ($dependencies as $dependency) {
                    $summary = shorten_text(format_string($dependency->summary));
                    $dependenciesmenu[$dependency->id] = "{$tracker->ticketprefix}{$dependency->id} - ".$summary;
                }
                $select = &$mform->addElement('select', 'dependencies', get_string('dependson', 'tracker'), $dependenciesmenu);
                $select->setMultiple(true);
            } else {
                $mform->addElement('static', 'dependenciesshadow', get_string('dependson', 'tracker'), get_string('nopotentialdeps', 'tracker'));
            }
             * SAMSUNG END
             */

            $mform->addelement('header', 'resolutionhdr', get_string('resolution', 'tracker'), '');
            $mform->addElement('editor', 'resolution_editor', get_string('resolution', 'tracker') .
                '<br /><br /><h6>Внимание! Решение должно прикрепляться только к тикетам в соответствующем статусе "Решено". После решения тикета, редактировать решение будет невозможно.</h6>',
                $this->editoroptions);

            /*
             * SAMSUNG BEGIN - block resolution field, if ticket is resolved
             */
            if ($issue->status >= RESOLVED) {
                $resolution_arr = array();
                $resolution_arr['text'] = @$issue->resolution;
                $resolution_arr['format'] = @$issue->resolutionformat;
                $mform->hardFreeze(array('resolution_editor'));
                $mform->setConstant('resolution_editor', $resolution_arr);
            }
            /*
             * SAMSUNG END
             */

        }

        $this->add_action_buttons();
    }

    public function set_data($defaults) {

        $defaults->description_editor['text'] = @$defaults->description;
        $defaults->description_editor['format'] = @$defaults->descriptionformat;
        $defaults = file_prepare_standard_editor($defaults, 'description', $this->editoroptions, $this->context, 'mod_tracker',
                                                 'issuedescription', @$defaults->issueid);

        // Something to prepare for each element ?
        if (!empty($this->elements)) {
            foreach ($this->elements as $element) {
                $element->set_data($defaults, @$this->_customdata['issueid']);
                // SAMSUNG BEGIN - set constant these elements, if form is in update mode, otherwise data will be lost on submit
                if ($this->_customdata['mode'] == 'update') {
                    $this->_form->freeze('element'.$element->name);
//                    $this->_form->setConstant('element'.$element->name, $element->value);
                }
                // SAMSUNG END
            }
        }

        $defaults->resolution_editor['text'] = @$defaults->resolution;
        $defaults->resolution_editor['format'] = @$defaults->resolutionformat;
        $defaults = file_prepare_standard_editor($defaults, 'resolution', $this->editoroptions, $this->context, 'mod_tracker',
                                                 'issueresolution', @$defaults->issueid);

        parent::set_data($defaults);
    }

    public function validate($data, $files = array()) {

        $errors = array();

        // Something to prepare for each element ?
        if (!empty($this->elements)) {
            foreach ($this->elements as $element) {
                $errors = array_merge($errors, $element->validate($data, $files));
            }
        }

        return $errors;
    }
}