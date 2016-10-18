<?php

///////////////////////////////////////////////////////////////////////////
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
// Moodle - Modular Object-Oriented Dynamic Learning Environment         //
//          http://moodle.org                                            //
//                                                                       //
// Copyright (C) 1999 onwards  Martin Dougiamas  http://moodle.com       //
//                                                                       //
// This program is free software; you can redistribute it and/or modify  //
// it under the terms of the GNU General Public License as published by  //
// the Free Software Foundation; either version 2 of the License, or     //
// (at your option) any later version.                                   //
//                                                                       //
// This program is distributed in the hope that it will be useful,       //
// but WITHOUT ANY WARRANTY; without even the implied warranty of        //
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         //
// GNU General Public License for more details:                          //
//                                                                       //
//          http://www.gnu.org/copyleft/gpl.html                         //
//                                                                       //
///////////////////////////////////////////////////////////////////////////

require_once '../../../config.php';
require_once $CFG->dirroot.'/lib/gradelib.php';
require_once $CFG->dirroot.'/grade/lib.php';
require_once $CFG->dirroot.'/grade/report/grade_breakdown/lib.php';

$courseid = required_param('id', PARAM_INT);
$gradeid  = optional_param('grade', null, PARAM_INT);
$groupid  = optional_param('group', null, PARAM_INT);

$PAGE->set_url(new moodle_url('/grade/report/grade_breakdown/index.php', array(
    'id' => $courseid, 'grade' => $gradeid, 'group' => $groupid
)));

if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    print_error('nocourseid');
}

require_login($course);

$context = context_course::instance($course->id);
// This is the normal requirements
require_capability('gradereport/grade_breakdown:view', $context);

// Are they a teacher?
$is_teacher = has_capability('moodle/grade:viewall', $context);

// Graded roles
$gradedroles = explode(',', $CFG->gradebookroles);

$has_access = false;
// If the user is a "student" (graded role), and the teacher allowed them
// to view the report

$allowstudents = grade_get_setting(
    $courseid,
    'report_grade_breakdown_allowstudents',
    $CFG->grade_report_grade_breakdown_allowstudents
);

if (!$is_teacher && $allowstudents) {
    $user_roles = get_user_roles($context, $USER->id);

    foreach ($user_roles as $role) {
        if (in_array($role->roleid, $gradedroles)) {
            $has_access = true;
            break;
        }
    }
}
// End permission

$_s = function($key, $a = null) {
    return get_string($key, 'gradereport_grade_breakdown');
};

$gpr = new grade_plugin_return(array(
    'type' => 'report', 'plugin' => 'grade_breakdown', 'courseid' => $courseid
));

if (!isset($USER->grade_last_report)) {
    $USER->grade_last_report = array();
}
$USER->grade_last_report[$course->id] = 'grade_breakdown';

grade_regrade_final_grades($courseid);

$reportname = $_s('pluginname');

$PAGE->set_context($context);

print_grade_page_head($course->id, 'report', 'grade_breakdown', $reportname, false);

// The current user does not have access to view this report
if (!$has_access && !$is_teacher) {
    echo $OUTPUT->heading($_s('teacher_disabled'));
    echo $OUTPUT->footer();
    exit;
}

// Find the number of users in the course
$users = array();

if (COUNT($gradedroles) > 1) {
    foreach ($gradedroles as $gradedrole) {
        $users = $users + get_role_users(
            $gradedrole, $context, false, '',
            'u.id, u.lastname, u.firstname', null, $groupid
        );
    }
} else {
    $gradedrole = implode($gradedroles);
    $users = get_role_users(
        $gradedrole, $context, false, '',
        'u.id', 'u.lastname, u.firstname', null, $groupid
    );
}

$num_users = count($users);

// The student has access, but they still are unable to view it
// if there is 10 or less student enrollments in the class
if (!$is_teacher && $num_users <= 10) {
    echo $OUTPUT->heading($_s('size_disabled'));
    echo $OUTPUT->footer();
    exit;
}

$report = new grade_report_grade_breakdown(
    $courseid, $gpr, $context, $gradeid, $groupid
);

$report->setup_grade_items();
$report->setup_groups();

echo '<div class="selectors">
        '. ($is_teacher ? $report->group_selector : '') . $report->grade_selector . '
      </div>';

$report->print_table();

echo $OUTPUT->footer();

?>
