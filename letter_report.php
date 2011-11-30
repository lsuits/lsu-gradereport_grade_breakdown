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

$courseid = required_param('id');
$bound    = required_param('bound');
$gradeid  = required_param('grade');
$groupid  = optional_param('group', 0, PARAM_INT);

if (!$course = get_record('course', 'id', $courseid)) {
    print_error('nocourseid');
}

require_login($course);

$context = get_context_instance(CONTEXT_COURSE, $course->id);

// They MUST be able to view grades to view this page
require_capability('gradereport/grade_breakdown:view', $context);
require_capability('moodle/grade:viewall', $context);

/// Build navigation
$strgrades  = get_string('grades');
$reportname = get_string('modulename', 'gradereport_grade_breakdown');

$navigation = grade_build_nav(__FILE__, $reportname, $courseid);

/// Print header
print_header_simple($strgrades.': '.$reportname, ': '.$strgrades, $navigation,
                    '', '', true, '', navmenu($course));

/// Print the plugin selector at the top
print_grade_plugin_selector($courseid, 'report', 'grade_breakdown');

// This grade report has the functionality to print the right
// group selector
$gpr = new grade_plugin_return(array('type' => 'report', 'plugin' => 'grade_breakdown', 'courseid' => $courseid));

$grade_report = new grade_report_grade_breakdown($courseid, $gpr, $context, $gradeid, $groupid);
$grade_report->pbarurl = $CFG->wwwroot.'/grade/report/grade_breakdown/letter_report.php?id='.
                         $courseid . '&amp;bound='. $bound;
$grade_report->setup_groups();

echo '<div class="selectors">'. $grade_report->group_selector. '</div>';

// Get all the students in this course
$roleids = explode(',', $CFG->gradebookroles);
$graded_users = get_role_users($roleids, $context, 
                false, '', 'u.lastname ASC', true, $groupid);
$userids = implode(',', array_keys($graded_users));

$sql = "SELECT g.id, g.grademax, g.itemname, gc.fullname FROM
            {$CFG->prefix}grade_items g,
            {$CFG->prefix}grade_categories gc
         WHERE g.id = {$gradeid}
           AND (gc.id = g.categoryid 
            OR (gc.id = g.iteminstance AND g.categoryid IS NULL))
           AND g.courseid = {$courseid}";

$grade_item = get_record_sql($sql);

$letters = grade_get_letters($context);
$high = 100;
foreach($letters as $boundary => $letter) {
    // Found it!
    if ($boundary == $bound) {
        break;
    }
    $high = $boundary - (1 / (pow(10, 5)));
}

// In the event that we're looking at the max, students actually have the 
// ability to go twice the max, so we must adhere to that rule
$high = ($high == 100) ? $high * 2 : $high;

$real_high = $grade_item->grademax * ($high / 100);
$real_low  = $grade_item->grademax * ($bound / 100);

// Add group sql
$group_select = "";
$group_where  = "";
if ($groupid) {
    $group_select = ", {$CFG->prefix}groups_members gr ";
    $group_where = " AND u.id = gr.userid
                     AND gr.groupid = {$groupid} ";
}

// Get all the grades for the users within the range specified with $real_high and $real_low
$sql = "SELECT u.id, g.id AS gradeid, g.finalgrade, u.firstname, u.lastname FROM 
                {$CFG->prefix}grade_grades g,
                {$CFG->prefix}user u
                $group_select
            WHERE u.id = g.userid
              $group_where
              AND g.itemid = {$gradeid}
              AND g.userid IN ({$userids})
              AND g.finalgrade <= {$real_high}
              AND g.finalgrade >= {$real_low}
            ORDER BY g.finalgrade DESC";

$grades = get_records_sql($sql);

// No grades; tell them that, then die
if (!$grades) {
    print_heading(get_string('no_grades', 'gradereport_grade_breakdown'));
    print_footer();
    die();
}

if ($grade_item->itemname) {
    $name = $grade_item->itemname;
} else if ($grade_item->fullname == '?') {
    $name = get_string('course_total', 'gradereport_grade_breakdown');
} else {
    $name = $grade_item->fullname;
}

// Get the Moodley version of this grade item
$grade_item = grade_item::fetch(array('id' => $gradeid));
$groupname = ($groupid) ? ' in ' . get_field('groups', 'name', 'id', $groupid) : '';

print_heading(get_string('user_grades', 'gradereport_grade_breakdown') . 
              $letters[$bound] . ' for ' . $name . $groupname);

$numusers = find_num_users($context, $groupid);

// Use simple_tree is it exists
$simple_tree = file_exists($CFG->dirroot . '/grade/edit/simple_tree');

// Build the data
$data = array();
foreach ($grades as $userid => $gr) {
    $line = array();
    $line[] = '<a href="'.$CFG->wwwroot.'/grade//report/user/index.php?id='.
              $courseid.'&amp;userid='. $userid.'">' . fullname($gr). '</a>';
    $line[] = grade_format_gradevalue($gr->finalgrade, $grade_item, true, 
                                      GRADE_DISPLAY_TYPE_REAL);
    $line[] = grade_format_gradevalue($gr->finalgrade, $grade_item, true, 
                                      GRADE_DISPLAY_TYPE_PERCENTAGE);
    $line[] = find_rank($context, $grade_item, $gr, $groupid) . '/' . $numusers;
    $line[] = print_edit_link($courseid, $gr->gradeid, $grade_item, $simple_tree);
    $data[] = $line;
}

$table = new object();
$table->head = array(get_string('fullname'), 
                     get_string('real_grade', 'gradereport_grade_breakdown'), 
                     get_string('percent', 'grades'), 
                     ($groupid ? get_string('group') : get_string('course')) . ' ' . get_string('rank', 'grades'), 
                     get_string('edit', 'grades'));
$table->data = $data;

print_table($table);
print_footer();

function print_edit_link($courseid, $grade_gradeid, $grade_item, $simple_tree = false) {
    global $CFG;

    $tree = ($simple_tree) ? 'simple_tree' : 'tree';

    if (!in_array($grade_item->itemtype, array('course', 'category'))) {
        return '<a href="'.$CFG->wwwroot.'/grade/edit/'.$tree.'/grade.php?courseid='.
                       $courseid.'&amp;id='.$grade_gradeid.'&amp;gpr_type=report'.
                      '&amp;gpr_plugin=grade_breakdown&amp;gpr_courseid='.
                       $courseid.'">'.get_string('edit', 'grades').'</a>';
    } else {
        return 'X';
    }
}

?>
