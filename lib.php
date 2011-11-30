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

require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->libdir.'/tablelib.php');

class grade_report_grade_breakdown extends grade_report {
    // Cache grade item pull from db
    var $grade_items;

    // Cache the group pulled from db
    var $group;

    // id of the current group
    var $currentgroup;

    // id of the current grade chosen
    var $currentgrade;

    /**
     * This is a view only report
     */
    function process_data($data) {
    }

    function process_action($target, $action) {
    }

    function __construct($courseid, $gpr, $context, $gradeid = null, $groupid = null) {
        global $CFG;
        parent::__construct($courseid, $gpr, $context);

        // Cache these capabilities
        $this->caps = array('is_teacher' => has_capability('moodle/grade:viewall', $context),
                            'hidden'     => has_capability('moodle/grade:viewhidden', $context));

        // By default we'll be pulling from every item in the course
        $query = array('courseid' => $courseid);

        // They selected a grade item; store it
        if ($gradeid !== null) {
            set_user_preference('report_grade_breakdown_gradeid', $gradeid);
        } else {
            // Get previous grade item id; if, in fact, the item has been removed,
            // then we must still default to the course
            $gradeid = get_user_preferences('report_grade_breakdown_gradeid', 0);
        }

        // We retrieved from the store, now we must check that it is a valid
        // grade item. If it is, then we can use it
        if ($gradeid != 0 && get_field('grade_items', 'id', 'id', $gradeid)) {
            $query['id'] = $gradeid;
        }

        // Get the percentage for only this group
        if ($groupid) {
            $this->group = get_record('groups', 'id', $groupid);
        }

        if (!$this->caps['hidden']) {
            $query['hidden'] = 0;
        }

        $this->currentgroup = $groupid;
        $this->currentgrade = $gradeid;
        $this->grade_items = grade_item::fetch_all($query);

        $this->baseurl = $CFG->wwwroot.'/grade/report/grade_breakdown/index.php?id='.$courseid;
        $this->pbarurl = $this->baseurl;

        $this->course->groupmode = 2;
    }

    function setup_grade_items() {
        global $CFG;

        $course_item_id = get_field('grade_items', 'id', 'itemtype', 
                             'course', 'courseid', $this->course->id);

        $sql = "SELECT g.id, g.itemname, gc.fullname FROM
                    {$CFG->prefix}grade_items g,
                    {$CFG->prefix}grade_categories gc
                 WHERE g.itemtype != 'course'
                   AND ((gc.id = g.iteminstance AND g.categoryid IS NULL)
                   OR 
                     gc.id = g.categoryid)
                   AND g.courseid = {$this->course->id} ";

        // User can't see hiddens: this means they can't see hidden
        if (!$this->caps['hidden']) {
            $sql .= " AND g.hidden = 0 ";
        }
        $sql .= " ORDER BY sortorder ";

        $sql_grades = get_records_sql($sql);
        $grades = array(0 => get_string('all_grades', 'gradereport_grade_breakdown'));
        foreach ($sql_grades as $id => $sql_g) {
            $grades[$id] = ($sql_g->itemname != null) ? $sql_g->itemname : $sql_g->fullname;
        }

        $grades += array($course_item_id => get_string('course_total', 
                                                       'gradereport_grade_breakdown'));

        // Cache the grade selector html for later use
        $this->grade_selector = popup_form($this->pbarurl . '&amp;group=' . $this->currentgroup . '&amp;grade=', 
                                $grades, 'selectgrade', $this->currentgrade, 
                                '', '', '', true, 'self',
                                get_string('items', 'gradereport_grade_breakdown'));

    }

    /**
     * Changing the setup groups method to look at group membership
     */
    function setup_groups() {
        global $CFG, $USER;

        $sql = "SELECT g.id, g.name 
                    FROM {$CFG->prefix}groups g,
                         {$CFG->prefix}groups_members gr
                    WHERE g.courseid = {$this->courseid}
                      AND gr.groupid = g.id ";

        if (!has_capability('moodle/site:accessallgroups', $this->context, $USER->id)) {
            $sql .= " AND gr.userid = {$USER->id} ";
        }

        $sql .= " ORDER BY g.name";

        $sql_groups = get_records_sql_menu($sql);
        if (count($sql_groups) > 1) {
            $groups = array(0 => get_string('allparticipants')) + $sql_groups;
        } else {
            $groups = $sql_groups;
            $this->currentgroup = current(array_keys($sql_groups));
        }

        // Cache the grade selector html for later use
        $this->group_selector = popup_form($this->pbarurl  . '&amp;grade=' . $this->currentgrade . '&amp;group=', 
                                $groups, 'selectgroup', $this->currentgroup, 
                                '', '', '', true, 'self', get_string('groupsvisible'));
    }

    function print_table() {
        global $CFG;

        // Filter by those who are actually enrolled
        $role_select = ", {$CFG->prefix}role_assignments ra ";
        $role_where = " AND ra.contextid = {$this->context->id}
                        AND ra.roleid IN ({$CFG->gradebookroles})
                        AND ra.userid = g.userid ";

        // Print a table for each grade item
        foreach ($this->grade_items as $item) {

            if (isset($this->group)) {
                $groupname = $this->group->name;

                // Get all the grades for that grade item, for this group
                $sql = "SELECT g.* FROM 
                            {$CFG->prefix}grade_grades g,
                            {$CFG->prefix}groups_members gm
                            $role_select
                        WHERE g.userid = gm.userid
                          $role_where
                          AND g.itemid = {$item->id}
                          AND gm.groupid = {$this->group->id} ";
            } else {
                $groupname = get_string('allparticipants');

                $sql = "SELECT g.* FROM
                            {$CFG->prefix}grade_grades g
                            $role_select
                        WHERE g.itemid = {$item->id}
                          $role_where ";
            }

            // If the user has the ability to view hiddens, then we query hidden grades
            $sql .= " AND g.finalgrade IS NOT NULL
                      AND g.excluded = 0 ";

            if (!$this->caps['hidden']) {
                $sql .= " AND g.hidden = 0 ";
            }

            // Check preference
            // Get all the grades for that grade item
            $grades = get_records_sql($sql);

            // Cache the decimal value of the grade item for later use
            $decimals = $item->get_decimals();

            // How many grades for this item?
            $total_grades = ($grades != null) ? count($grades) : 0;

            // Get the letter grade info for the course
            $letters = grade_get_letters($this->context);

            $data = array();
            // Prepare the data
            foreach ($letters as $boundary => $letter) {
                if (!isset($data[$letter])) {
                    $info = new stdClass;
                    $info->count = 0;
                    $info->boundary = $boundary;
                    $info->percent_total = 0;
                    $info->real_total = 0;
                    $info->high_percent = 0;
                    $info->high_real = 0;
                    $info->low_percent = 0;
                    $info->low_real = $item->grademax;
                    $data[$letter] = $info;
                }
            }

            // Filter the grades based on the letter
            if ($grades) {
                foreach ($grades as $grade) {
                    $value = grade_grade::standardise_score($grade->finalgrade, 
                                $item->grademin, $item->grademax, 0, 100);
                    //$value = bounded_number(0, $value, 100);
                    $value = round($value, $item->get_decimals());
                    foreach ($letters as $boundary => $letter) {
                        // Add it to the data
                        if ($value >= $boundary) {
                            // Get the highest grade for this boundary
                            if ($data[$letter]->high_real <= $grade->finalgrade) {
                                $data[$letter]->high_real = $grade->finalgrade;
                                $data[$letter]->high_percent = $value;
                            }

                            // Get the lowest grade for this boundary which might
                            // be the same as the highest grade
                            if ($grade->finalgrade <= $data[$letter]->low_real) {
                                $data[$letter]->low_real = $grade->finalgrade;
                                $data[$letter]->low_percent = $value;
                            }

                            $data[$letter]->count += 1;
                            $data[$letter]->percent_total += $value;
                            $data[$letter]->real_total += $grade->finalgrade;
                            continue 2;
                        }
                    }
                }
            }

            // After the filter process, we must build the display data
            $max = 100;
            $final_data = array();
            foreach ($data as $letter => $info) {
                $boundary = format_float($info->boundary, $decimals);
                $gmax = format_float($max, $decimals);
                $boundary_max = format_float($item->grademax * ($info->boundary / 100), $decimals);
                $pmax = format_float($item->grademax * ($max / 100), $decimals);
                $high_percent = round($info->high_percent, $decimals);
                $low_percent = round($info->low_percent , $decimals);
                $high_real = round($info->high_real, $decimals);
                $low_real = round($info->low_real, $decimals);

                $line = array();
                $line[] = ($info->count==0) ? format_string($letter) :
                           $this->link_to_letter($letter, $info->boundary, $item->id);
                $line[] = ($info->count==0) ? $boundary . '% - '.
                           $gmax . '%' : $this->link_to_letter(($boundary . '% - '.
                           $gmax . '%'), $info->boundary, $item->id);
                $line[] = ($info->count==0) ? $boundary_max . ' - ' .
                           $pmax : $this->link_to_letter(($boundary_max . ' - ' . $pmax),
                           $info->boundary, $item->id);
                $line[] = ($info->count==0) ? $high_percent . '%' :
                           $this->link_to_letter(($high_percent . '%'), $info->boundary, $item->id);
                $line[] = ($info->count==0) ? ($high_real) :
                           $this->link_to_letter($high_real, $info->boundary, $item->id);
                $line[] = ($info->count==0) ? ($low_percent . '%') :
                           $this->link_to_letter(($low_percent . '%'), $info->boundary, $item->id);
                $line[] = ($info->count == 0) ? 0 : $this->link_to_letter($low_real, $info->boundary, $item->id);
                $line[] = round(($info->percent_total / $info->count), $decimals) . '%';
                $line[] = round(($info->real_total / $info->count), $decimals);
                $line[] = round(($info->count / (($total_grades) ? $total_grades : 1)) * 100, $decimals) . '%';
                $line[] = $info->count;
                $final_data[] = $line;
                $max = $info->boundary - (1 / (pow(10, $decimals)));
            }

            // Footer info
            $final_data[] = array('<strong>'.get_string('total').'</strong>',
                                  '','','','','', '','','','',
                                  '<strong>' . $total_grades . '</strong>');

            // Get the name of the item
            if (!$item->itemname && $item->itemtype == 'course') {
                $name = get_string('course_total', 'gradereport_grade_breakdown');
            } else if (!$item->itemname) {
                $name = get_field('grade_categories', 'fullname', 'id', $item->iteminstance);
            } else {
                $name = $item->itemname;
            }

            print_heading($name . ' for '. $groupname);

            // Prepare the table for viewing
            $table = new object();
            $table->head = array(get_string('letter', 'grades'),
                                 get_string('percent_range', 'gradereport_grade_breakdown'),
                                 get_string('real_range', 'gradereport_grade_breakdown'),
                                 get_string('highest_percent', 'gradereport_grade_breakdown'),
                                 get_string('highest_real', 'gradereport_grade_breakdown'),
                                 get_string('lowest_percent', 'gradereport_grade_breakdown'),
                                 get_string('lowest_real', 'gradereport_grade_breakdown'),
                                 get_string('percent_average', 'gradereport_grade_breakdown'),
                                 get_string('real_average', 'gradereport_grade_breakdown'),
                                 get_string('total_percent', 'gradereport_grade_breakdown'),
                                 get_string('count', 'gradereport_grade_breakdown'));
            $table->size = array('10%', '30%', '20%', '5%', '5%', '5%', '5%', '5%',
                                 '5%', '5%', '5%');
            $table->align = array('left', 'right', 'right', 'right', 'right', 'right',
                                  'right', 'right', 'right', 'right', 'right');
            $table->data = $final_data;

            print_table($table);
        }
    }

    // Link the letter grade to even further break down info
    function link_to_letter($letter, $boundary, $grade) {
        if ($this->caps['is_teacher']) {
            return '<a href="letter_report.php?id='.$this->course->id.
               '&amp;bound='.$boundary.'&amp;group='.$this->currentgroup.
               '&amp;grade='.$grade. '">'.
               format_string($letter).'</a>';
        } else {
            return format_string($letter);
        }
    }
}

// The following functions were ripped from /grade/user/report/lib.php
function find_rank($context, $grade_item, $grade_grade, $groupid) {
    global $CFG;

    $group_select = '';
    $group_where = '';
    if ($groupid) {
        $group_select = " INNER JOIN {$CFG->prefix}groups_members gr 
                            ON gr.userid = g.userid ";
        $group_where = " AND gr.groupid = {$groupid} ";
    }

    $sql = "SELECT COUNT(DISTINCT(g.userid))
              FROM {$CFG->prefix}grade_grades g
                INNER JOIN {$CFG->prefix}role_assignments r
                  ON r.userid = g.userid
                $group_select
             WHERE finalgrade IS NOT NULL AND finalgrade > $grade_grade->finalgrade
                AND itemid = {$grade_item->id}
                $group_where
                AND(r.contextid = $context->id)
                AND r.roleid IN ({$CFG->gradebookroles})";

    return count_records_sql($sql) + 1;
}

function find_num_users($context, $groupid) {
    global $CFG;

    $group_select = '';
    $group_where = '';
    if ($groupid) {
        $group_select = " JOIN {$CFG->prefix}groups_members gr 
                          ON gr.userid = u.id ";
        $group_where = " AND gr.groupid = {$groupid}";
    }

    $parentcontexts = '';
    $parentcontexts = substr($context->path, 1); // kill leading slash
    $parentcontexts = str_replace('/', ',', $parentcontexts);

    if ($parentcontexts !== '') {
        $parentcontexts = ' OR r.contextid IN ('.$parentcontexts.' )';
    }

   //Checking to see if the person can view hidden role assignments. If not, then omit any hidden roles from the number of users in a course
    $canseehidden = has_capability('moodle/role:viewhiddenassigns', $context);
    if (!$canseehidden) {
        $hidden = ' AND r.hidden = 0 ';
    }

    // Counts the sql for gradeable users in the course
    $sql = "SELECT count(u.id)
              FROM {$CFG->prefix}role_assignments r
                  JOIN {$CFG->prefix}user u 
                  ON u.id = r.userid
                  $group_select
              WHERE (r.contextid = $context->id $parentcontexts)
                  $hidden
                  AND r.roleid IN ({$CFG->gradebookroles})
                  $group_where
                  AND u.deleted = 0";
    return count_records_sql($sql);
}

// Course settings moodle form definition
function grade_report_grade_breakdown_settings_definition(&$mform) {
    global $CFG;

    $options = array(
        -1 => get_string('default', 'grades'),
        0 => get_string('no'),
        1 => get_string('yes')
    );

    $allowstudents = get_config('moodle', 'grade_report_grade_greakdown_allowstudents');

    if (empty($allowstudents)) {
        $options[-1] = get_string('defaultprev', 'grades', $options[0]);
    } else {
        $options[-1] = get_string('defaultprev', 'grades', $options[1]);
    }

    $mform->addElement(
        'select', 'report_grade_breakdown_allowstudents',
        get_string('allowstudents', 'gradereport_grade_breakdown'),
        $options
    );
}

